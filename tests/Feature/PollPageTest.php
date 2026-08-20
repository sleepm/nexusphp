<?php

namespace Tests\Feature;

use App\Models\Poll;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the makepoll.php / polloverview.php migration
 * (PollController::webMakePoll / webOverview) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes the users/polls/answers this test created.
 */
class PollPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdPollIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
    }

    protected function tearDown(): void
    {
        if ($this->createdPollIds !== []) {
            DB::table('pollanswers')->whereIn('pollid', $this->createdPollIds)->delete();
            DB::table('polls')->whereIn('id', $this->createdPollIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    /**
     * pollmanage defaults to CLASS_ADMINISTRATOR (config: authority.pollmanage =
     * '14'), so an administrator can manage polls.
     */
    private function makeAdmin(): User
    {
        $username = 'poll_admin_' . substr(md5((string) mt_rand()), 0, 6);
        $user = User::query()->create([
            'username' => $username,
            'passhash' => str_repeat('a', 32),
            'secret' => 'secret',
            'auth_key' => 'authkey_' . $username,
            'email' => $username . '@example.com',
            'status' => 'confirmed',
            'enabled' => 'yes',
            'class' => User::CLASS_ADMINISTRATOR,
            'passkey' => md5($username),
            'ip' => '127.0.0.1',
            'avatar' => '',
            'title' => '',
            'signature' => '',
            'seedbonus' => 100,
            'showfb' => 'yes',
            'hidehb' => 'no',
            'added' => now(),
            'last_access' => now(),
            'commentpm' => 'no',
        ]);
        $this->createdUserIds[] = $user->id;

        return $user;
    }

    private function cookieFor(User $user): string
    {
        $tokenJson = json_encode(['user_id' => $user->id, 'expires' => time() + 3600]);
        $signature = hash_hmac('sha256', $tokenJson, $user->auth_key);
        return base64_encode($tokenJson . '.' . $signature);
    }

    private function asAdmin(User $user)
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en');
    }

    private function makePoll(array $overrides = []): Poll
    {
        $poll = Poll::query()->create(array_merge([
            'added' => now(),
            'question' => 'Poll question?',
            'option0' => 'Option A',
            'option1' => 'Option B',
        ], $overrides));
        $this->createdPollIds[] = $poll->id;

        return $poll;
    }

    // ------------------------------------------------------------------ auth

    public function testMakepollRequiresLogin()
    {
        $this->get('/makepoll.php')->assertRedirect();
    }

    public function testPolloverviewRequiresLogin()
    {
        $this->get('/polloverview.php')->assertRedirect();
    }

    public function testMakepollRejectsNonAdmin()
    {
        $mod = $this->makeAdmin();
        DB::table('users')->where('id', $mod->id)->update(['class' => User::CLASS_MODERATOR]);

        $this->asAdmin($mod)->get('/makepoll.php')->assertForbidden();
    }

    public function testPolloverviewRejectsNonAdmin()
    {
        $mod = $this->makeAdmin();
        DB::table('users')->where('id', $mod->id)->update(['class' => User::CLASS_MODERATOR]);

        $this->asAdmin($mod)->get('/polloverview.php')->assertForbidden();
    }

    // -------------------------------------------------------------- rendering

    public function testMakepollRendersNewPollForm()
    {
        $admin = $this->makeAdmin();

        $this->asAdmin($admin)
            ->get('/makepoll.php')
            ->assertOk()
            ->assertSee('name="question"', false)
            ->assertSee('name="option0"', false)
            ->assertSee('name="option19"', false)
            ->assertSee('makepoll.php', false);
    }

    public function testMakepollRendersEditPollForm()
    {
        $admin = $this->makeAdmin();
        $poll = $this->makePoll(['question' => 'Edit me?', 'option19' => 'Twentieth']);

        $this->asAdmin($admin)
            ->get('/makepoll.php?action=edit&pollid=' . $poll->id)
            ->assertOk()
            ->assertSee('Edit me?')
            ->assertSee('Twentieth')
            ->assertSee('name="pollid"', false);
    }

    public function testMakepollEditMissingPoll404()
    {
        $admin = $this->makeAdmin();

        $this->asAdmin($admin)->get('/makepoll.php?action=edit&pollid=999999')->assertNotFound();
    }

    // ----------------------------------------------------------- submission

    public function testMakepollCreatePoll()
    {
        $admin = $this->makeAdmin();

        $response = $this->asAdmin($admin)->post('/makepoll.php', [
            'question' => 'Brand new?',
            'option0' => 'Yes',
            'option1' => 'No',
            'option2' => 'Maybe',
            'returnto' => 'main',
        ]);

        $response->assertStatus(302);
        $this->assertStringContainsString(Setting::getBaseUrl(), $response->headers->get('Location'));

        $poll = Poll::query()->where('question', 'Brand new?')->first();
        $this->assertNotNull($poll);
        $this->assertSame('Maybe', $poll->option2);
        $this->createdPollIds[] = $poll->id;
    }

    public function testMakepollCreateRequiresFirstTwoOptions()
    {
        $admin = $this->makeAdmin();

        $this->asAdmin($admin)
            ->post('/makepoll.php', ['question' => 'Incomplete?', 'option0' => 'A'])
            ->assertSessionHas('error');

        $this->assertNull(Poll::query()->where('question', 'Incomplete?')->first());
    }

    public function testMakepollEditPoll()
    {
        $admin = $this->makeAdmin();
        $poll = $this->makePoll();

        $response = $this->asAdmin($admin)->post('/makepoll.php', [
            'pollid' => $poll->id,
            'question' => 'Updated?',
            'option0' => 'New A',
            'option1' => 'New B',
            'option2' => 'New C',
        ]);

        $response->assertStatus(302);
        $this->assertStringContainsString('log.php?action=poll', $response->headers->get('Location'));

        $this->assertSame('Updated?', $poll->fresh()->question);
        $this->assertSame('New C', $poll->fresh()->option2);
    }

    // ----------------------------------------------------------- overview

    public function testPolloverviewListPolls()
    {
        $admin = $this->makeAdmin();
        $this->makePoll(['question' => 'List me?']);

        $this->asAdmin($admin)
            ->get('/polloverview.php')
            ->assertOk()
            ->assertSee('List me?')
            ->assertSee('polloverview.php?id=', false);
    }

    public function testPolloverviewDetailShowsOptionsAndVotes()
    {
        $admin = $this->makeAdmin();
        $voter = $this->makeAdmin();
        $poll = $this->makePoll(['option2' => 'Option C']);
        DB::table('pollanswers')->insert([
            'pollid' => $poll->id,
            'userid' => $voter->id,
            'selection' => 2,
        ]);

        $this->asAdmin($admin)
            ->get('/polloverview.php?id=' . $poll->id)
            ->assertOk()
            ->assertSee('Option C')
            ->assertSee($voter->username)
            ->assertSee('polloverview.php?id=' . $poll->id, false);
    }

    public function testPolloverviewMissingPoll404()
    {
        $admin = $this->makeAdmin();

        $this->asAdmin($admin)->get('/polloverview.php?id=999999')->assertNotFound();
    }
}