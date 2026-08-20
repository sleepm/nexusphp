<?php

namespace Tests\Feature;

use App\Models\Shoutbox;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/shoutbox.php migration
 * (ShoutboxController::web) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection; tearDown() removes what the tests
 * created. The default DB has main.showhelpbox = no, so the helpbox path is
 * exercised in its "disabled" state.
 */
class ShoutboxPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdShoutboxIds = [];

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
        if ($this->createdShoutboxIds !== []) {
            Shoutbox::query()->whereIn('id', $this->createdShoutboxIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser($class = User::CLASS_USER): User
    {
        $username = 'sb_' . substr(md5((string) mt_rand()), 0, 6);
        $user = User::query()->create([
            'username' => $username,
            'passhash' => str_repeat('b', 32),
            'secret' => 'secret',
            'auth_key' => 'authkey_sb_' . $username,
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
            'sbrefresh' => 120,
            'sbnum' => 70,
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

    private function makeMessage(array $overrides = []): Shoutbox
    {
        $message = Shoutbox::query()->create(array_merge([
            'userid' => 0,
            'date' => time(),
            'text' => 'hello shoutbox',
            'type' => 'sb',
        ], $overrides));
        $this->createdShoutboxIds[] = $message->id;

        return $message;
    }

    // ------------------------------------------------------------------ guests

    public function testShoutboxGuestReadDenied()
    {
        $this->get('/shoutbox.php?type=shoutbox')
            ->assertOk()
            ->assertSee('Access Denied');
    }

    public function testHelpboxGuestReadWhenDisabled()
    {
        // main.showhelpbox defaults to 'no' -> the helpbox fragment falls back
        // to the guest access denial.
        $this->get('/shoutbox.php?type=helpbox')
            ->assertOk()
            ->assertSee('Access Denied');
    }

    public function testShoutboxGuestSendRejected()
    {
        $this->get('/shoutbox.php?type=shoutbox&sent=yes&shbox_text=hi')
            ->assertStatus(403)
            ->assertSee('You have no permission to send messages to shoutbox');
    }

    public function testHelpboxGuestSendRejectedWhenDisabled()
    {
        $this->get('/shoutbox.php?type=helpbox&sent=yes&shbox_text=hi')
            ->assertStatus(403)
            ->assertSee('Helpbox is currently disabled');
    }

    // ----------------------------------------------------------- logged in

    public function testShoutboxListsOwnMessages()
    {
        $user = $this->makeUser();
        $this->makeMessage(['userid' => $user->id, 'text' => 'first shout']);

        $this->asUser($user)
            ->get('/shoutbox.php?type=shoutbox')
            ->assertOk()
            ->assertSee('first shout')
            ->assertSee('startcountdown', false);
    }

    public function testShoutboxSendInsertsAndRendersMessage()
    {
        $user = $this->makeUser();

        $response = $this->asUser($user)
            ->get('/shoutbox.php?type=shoutbox&sent=yes&shbox_text=brand%20new%20shout');

        $response->assertOk()->assertSee('brand new shout');
        $this->assertTrue(
            Shoutbox::query()->where('userid', $user->id)
                ->where('type', 'sb')->where('text', 'brand new shout')->exists(),
            'shoutbox message was not stored'
        );
    }

    public function testShoutboxGuestMessageRendersGuestLabel()
    {
        $user = $this->makeUser();
        $this->makeMessage(['userid' => 0, 'text' => 'guest hello']);

        $this->asUser($user)
            ->get('/shoutbox.php?type=shoutbox')
            ->assertOk()
            ->assertSee('guest hello')
            ->assertSee('<b>Guest</b>', false);
    }

    // ------------------------------------------------------------------ delete

    public function testShoutboxManagerCanDelete()
    {
        $mod = $this->makeUser(User::CLASS_MODERATOR);
        $message = $this->makeMessage(['text' => 'delete me']);

        $this->asUser($mod)->get('/shoutbox.php?del=' . $message->id)->assertOk();

        $this->assertNull(Shoutbox::query()->find($message->id));
    }

    public function testShoutboxRegularUserCannotDelete()
    {
        $user = $this->makeUser();
        $message = $this->makeMessage(['text' => 'keep me']);

        $this->asUser($user)->get('/shoutbox.php?del=' . $message->id)->assertOk();

        $this->assertNotNull(Shoutbox::query()->find($message->id));
    }
}