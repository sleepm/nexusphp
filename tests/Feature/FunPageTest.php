<?php

namespace Tests\Feature;

use App\Http\Controllers\IndexController;
use App\Models\Fun;
use App\Models\FunVote;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/fun.php migration (FunController::web).
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection; tearDown() removes what the tests
 * created. funmanage/sbmanage default to CLASS_MODERATOR in the DB.
 */
class FunPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdFunIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        Cache::flush();
    }

    protected function tearDown(): void
    {
        if ($this->createdFunIds !== []) {
            DB::table('funvotes')->whereIn('funid', $this->createdFunIds)->delete();
            DB::table('fun')->whereIn('id', $this->createdFunIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            DB::table('messages')->whereIn('receiver', $this->createdUserIds)->delete();
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser($class = User::CLASS_USER): User
    {
        $username = 'fun_' . substr(md5((string) mt_rand()), 0, 6);
        $user = User::query()->create([
            'username' => $username,
            'passhash' => str_repeat('c', 32),
            'secret' => 'secret',
            'auth_key' => 'authkey_fun_' . $username,
            'email' => $username . '@example.com',
            'status' => 'confirmed',
            'enabled' => 'yes',
            'class' => $class,
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

    private function asUser(User $user)
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en');
    }

    private function makeFun(array $overrides = []): Fun
    {
        $fun = Fun::query()->create(array_merge([
            'userid' => 0,
            'added' => now(),
            'body' => 'a joke body',
            'title' => 'A Great Joke',
            'status' => Fun::STATUS_NORMAL,
        ], $overrides));
        $this->createdFunIds[] = $fun->id;

        return $fun;
    }

    private function addVotes(int $funId, int $funCount, int $dullCount = 0): void
    {
        $rows = [];
        for ($i = 0; $i < $funCount; $i++) {
            $rows[] = ['funid' => $funId, 'userid' => $this->makeUser()->id, 'added' => now(), 'vote' => 'fun'];
        }
        for ($i = 0; $i < $dullCount; $i++) {
            $rows[] = ['funid' => $funId, 'userid' => $this->makeUser()->id, 'added' => now(), 'vote' => 'dull'];
        }
        DB::table('funvotes')->insert($rows);
    }

    // ------------------------------------------------------------------- auth

    public function testFunRequiresLogin()
    {
        $this->get('/fun.php')->assertRedirect();
    }

    // ------------------------------------------------------------------ view

    public function testFunViewRendersEmptyFragment()
    {
        $user = $this->makeUser();

        $this->asUser($user)->get('/fun.php?action=view')->assertOk();
    }

    public function testFunViewShowsCurrentItem()
    {
        $user = $this->makeUser();
        $this->makeFun(['userid' => $user->id, 'title' => 'A Great Joke', 'body' => 'funny body text']);

        $this->asUser($user)
            ->get('/fun.php?action=view')
            ->assertOk()
            ->assertSee('A Great Joke')
            ->assertSee('funny body text');
    }

    // ------------------------------------------------------------------- new

    public function testFunNewBlockedWithin24Hours()
    {
        $user = $this->makeUser();
        $this->makeFun(['added' => now()]);

        $this->asUser($user)
            ->get('/fun.php?action=new')
            ->assertOk()
            ->assertSee('The latest fun item')
            ->assertSee('Please wait till it gets 24 hours old.');
    }

    public function testFunNewRendersFormWhenOldItem()
    {
        $user = $this->makeUser();
        $this->makeFun(['added' => now()->subDays(2)]);

        $this->asUser($user)
            ->get('/fun.php?action=new')
            ->assertOk()
            ->assertSee('name="subject"', false)
            ->assertSee('name="body"', false)
            ->assertSee('fun.php?action=add', false);
    }

    // ------------------------------------------------------------------- add

    public function testFunAddCreatesItemAndRedirects()
    {
        $user = $this->makeUser();

        $response = $this->asUser($user)->post('/fun.php', [
            'action' => 'add',
            'subject' => 'My Fun',
            'body' => 'my fun body',
        ]);

        $response->assertStatus(302);
        $this->assertStringContainsString('/index.php', $response->headers->get('Location'));

        $fun = Fun::query()->where('title', 'My Fun')->first();
        $this->assertNotNull($fun);
        $this->assertSame('my fun body', $fun->body);
        $this->assertSame($user->id, (int) $fun->userid);
        $this->createdFunIds[] = $fun->id;
    }

    public function testFunAddRequiresBody()
    {
        $user = $this->makeUser();

        $this->asUser($user)
            ->post('/fun.php', ['action' => 'add', 'subject' => 'No Body'])
            ->assertOk()
            ->assertSee('The body cannot be empty!');
    }

    public function testFunAddRequiresTitle()
    {
        $user = $this->makeUser();

        $this->asUser($user)
            ->post('/fun.php', ['action' => 'add', 'body' => 'no title'])
            ->assertOk()
            ->assertSee('The title cannot be empty!');
    }

    // ------------------------------------------------------------------ edit

    public function testFunEditRendersForm()
    {
        $user = $this->makeUser();
        $fun = $this->makeFun(['userid' => $user->id, 'title' => 'Edit Me', 'body' => 'old body']);

        $this->asUser($user)
            ->get('/fun.php?action=edit&id=' . $fun->id)
            ->assertOk()
            ->assertSee('Edit Me')
            ->assertSee('fun.php?action=edit', false);
    }

    public function testFunEditOwnerCanUpdate()
    {
        $user = $this->makeUser();
        $fun = $this->makeFun(['userid' => $user->id, 'added' => now()->subDays(2)]);

        $response = $this->asUser($user)->post('/fun.php?action=edit&id=' . $fun->id, [
            'subject' => 'Updated Title',
            'body' => 'updated body',
        ]);

        $response->assertStatus(302);
        $this->assertSame('Updated Title', $fun->fresh()->title);
        $this->assertSame('updated body', $fun->fresh()->body);
    }

    public function testFunEditNonOwnerForbidden()
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $fun = $this->makeFun(['userid' => $owner->id]);

        $this->asUser($other)
            ->get('/fun.php?action=edit&id=' . $fun->id)
            ->assertOk()
            ->assertSee('Permission denied!');
    }

    public function testFunEditMissingItem()
    {
        $user = $this->makeUser();

        $this->asUser($user)
            ->get('/fun.php?action=edit&id=999999')
            ->assertOk()
            ->assertSee('Invalid fun item id');
    }

    // ----------------------------------------------------------------- delete

    public function testFunDeleteRequiresFunmanage()
    {
        $user = $this->makeUser();
        $fun = $this->makeFun();

        $this->asUser($user)->get('/fun.php?action=delete&id=' . $fun->id)->assertForbidden();
    }

    public function testFunDeleteConfirmThenDelete()
    {
        $mod = $this->makeUser(User::CLASS_MODERATOR);
        $fun = $this->makeFun(['title' => 'doomed fun']);

        $this->asUser($mod)
            ->get('/fun.php?action=delete&id=' . $fun->id)
            ->assertOk()
            ->assertSee('Do you really want to delete a fun item?');

        $response = $this->asUser($mod)->get('/fun.php?action=delete&id=' . $fun->id . '&sure=1');
        $response->assertStatus(302);
        $this->assertNull(Fun::query()->find($fun->id));
    }

    // -------------------------------------------------------------------- ban

    public function testFunBanRequiresFunmanage()
    {
        $user = $this->makeUser();
        $fun = $this->makeFun();

        $this->asUser($user)->get('/fun.php?action=ban&id=' . $fun->id)->assertForbidden();
    }

    public function testFunBanConfirmForm()
    {
        $mod = $this->makeUser(User::CLASS_MODERATOR);
        $fun = $this->makeFun(['title' => 'ban me']);

        $this->asUser($mod)
            ->get('/fun.php?action=ban&id=' . $fun->id)
            ->assertOk()
            ->assertSee('Are you sure to ban this fun item?')
            ->assertSee('name=banreason', false);
    }

    public function testFunBanSubmitMarksBannedAndPm()
    {
        $mod = $this->makeUser(User::CLASS_MODERATOR);
        $owner = $this->makeUser();
        $fun = $this->makeFun(['userid' => $owner->id, 'title' => 'spam fun']);

        $this->asUser($mod)
            ->post('/fun.php?action=ban&id=' . $fun->id, ['banreason' => 'spam'])
            ->assertOk()
            ->assertSee('successfully banned');

        $this->assertSame(Fun::STATUS_BANNED, $fun->fresh()->status);
        $this->assertTrue(
            Message::query()->where('receiver', $owner->id)->where('msg', 'like', '%spam%')->exists(),
            'no ban PM sent to the poster'
        );
    }

    public function testFunBanRequiresReason()
    {
        $mod = $this->makeUser(User::CLASS_MODERATOR);
        $fun = $this->makeFun();

        $this->asUser($mod)
            ->post('/fun.php?action=ban&id=' . $fun->id, ['banreason' => ''])
            ->assertOk()
            ->assertSee('You must give the reason!');
    }

    // ------------------------------------------------------------------ vote

    public function testFunVoteInsertsVote()
    {
        $user = $this->makeUser();
        $fun = $this->makeFun();

        $this->asUser($user)->get('/fun.php?action=vote&id=' . $fun->id . '&yourvote=fun')->assertOk();

        $this->assertTrue(
            FunVote::query()->where('funid', $fun->id)->where('userid', $user->id)->where('vote', 'fun')->exists()
        );
    }

    public function testFunVoteDuplicateRejected()
    {
        $user = $this->makeUser();
        $fun = $this->makeFun();

        $this->asUser($user)->get('/fun.php?action=vote&id=' . $fun->id . '&yourvote=fun')->assertOk();
        $this->asUser($user)
            ->get('/fun.php?action=vote&id=' . $fun->id . '&yourvote=dull')
            ->assertOk()
            ->assertSee('You have already voted!');
    }

    public function testFunVoteMarksDullAtThreshold()
    {
        $user = $this->makeUser();
        $owner = $this->makeUser();
        $fun = $this->makeFun(['userid' => $owner->id]);
        // 4 fun + 17 dull = 21 votes once the 21st vote lands, ratio ~0.18
        // (the controller re-counts from the DB) -> status 'dull' + PM.
        $this->addVotes($fun->id, 4, 17);

        $this->asUser($user)->get('/fun.php?action=vote&id=' . $fun->id . '&yourvote=dull')->assertOk();

        $this->assertSame(Fun::STATUS_DULL, $fun->fresh()->status);
        $this->assertTrue(
            Message::query()->where('receiver', $owner->id)->where('msg', 'like', '%dull%')->exists(),
            'no dull PM sent to the poster'
        );
    }

    public function testFunVoteVeryFunnyAtThreshold()
    {
        $user = $this->makeUser();
        $owner = $this->makeUser();
        $fun = $this->makeFun(['userid' => $owner->id]);
        // 20 fun + 4 dull = 24 votes; the 25th fun vote pushes the ratio above
        // 0.75 -> 'veryfunny' + poster reward (legacy rewards at 25 votes).
        $this->addVotes($fun->id, 20, 4);

        $this->asUser($user)->get('/fun.php?action=vote&id=' . $fun->id . '&yourvote=fun')->assertOk();

        $this->assertSame(Fun::STATUS_VERY_FUNNY, $fun->fresh()->status);
        $this->assertTrue(
            Message::query()->where('receiver', $owner->id)->where('msg', 'like', '%is fun%')->exists(),
            'no reward PM sent to the poster'
        );
    }
}