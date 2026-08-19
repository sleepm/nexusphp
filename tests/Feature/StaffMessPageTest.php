<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the staffmess.php / takestaffmess.php migrations
 * (StaffMessController::web / webTake) against a real database.
 */
class StaffMessPageTest extends TestCase
{
    protected array $createdUserIds = [];

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
        if ($this->createdUserIds !== []) {
            DB::table('messages')->whereIn('receiver', $this->createdUserIds)->delete();
            DB::table('messages')->whereIn('sender', $this->createdUserIds)->delete();
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $name, int $class, array $overrides = []): User
    {
        $user = User::query()->create(array_merge([
            'username' => $name,
            'passhash' => str_repeat('a', 32),
            'secret' => 'secret',
            'auth_key' => 'authkey_' . $name,
            'email' => $name . '@example.com',
            'status' => 'confirmed',
            'enabled' => 'yes',
            'class' => $class,
            'passkey' => md5($name),
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
            'last_pm' => now()->subMinutes(5),
        ], $overrides));
        $this->createdUserIds[] = $user->id;

        return $user;
    }

    private function cookieFor(User $user): string
    {
        $tokenJson = json_encode(['user_id' => $user->id, 'expires' => time() + 3600]);
        $signature = hash_hmac('sha256', $tokenJson, $user->auth_key);
        return base64_encode($tokenJson . '.' . $signature);
    }

    private function headersFor(User $user): array
    {
        return [
            'c_secure_pass' => $this->cookieFor($user),
            'c_lang_folder' => 'en',
        ];
    }

    private function getStaffMess(User $user, string $query = '')
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/staffmess.php' . $query);
    }

    private function postTakestaff(User $user, array $data)
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->post('/takestaffmess.php', $data);
    }

    // ------------------------------------------------------------------ auth

    public function testStaffMessRequiresLogin()
    {
        $this->get('/staffmess.php')->assertRedirect();
    }

    public function testStaffMessRejectsBelowAdministrator()
    {
        $mod = $this->makeUser('staffmess_mod', User::CLASS_MODERATOR);

        $this->getStaffMess($mod)->assertForbidden();
    }

    public function testTakeStaffMessRequiresLogin()
    {
        $this->post('/takestaffmess.php', [])->assertRedirect();
    }

    // ------------------------------------------------------------ rendering

    public function testStaffMessRendersForm()
    {
        $admin = $this->makeUser('staffmess_admin', User::CLASS_ADMINISTRATOR);

        $this->getStaffMess($admin)
            ->assertOk()
            ->assertSee('Mass PM to all Staff members and users', false)
            ->assertSee('action=takestaffmess.php', false)
            ->assertSee('name="classes[]"', false)
            ->assertSee('name=subject', false)
            ->assertSee('name=msg', false);
    }

    public function testStaffMessShowsSentConfirmation()
    {
        $admin = $this->makeUser('staffmess_sent_admin', User::CLASS_ADMINISTRATOR);

        $this->getStaffMess($admin, '?sent=1')
            ->assertOk()
            ->assertSee('The message has ben sent.', false);
    }

    // ------------------------------------------------------------ submission

    public function testTakeStaffMessRejectsNonPost()
    {
        $admin = $this->makeUser('staffmess_get_admin', User::CLASS_ADMINISTRATOR);

        app('auth')->forgetGuards();
        $this->withCookie('c_secure_pass', $this->cookieFor($admin))
            ->get('/takestaffmess.php')->assertStatus(403);
    }

    public function testTakeStaffMessRejectsBelowAdministrator()
    {
        $mod = $this->makeUser('staffmess_take_mod', User::CLASS_MODERATOR);

        $this->postTakestaff($mod, ['msg' => 'x', 'classes' => [1]])->assertForbidden();
    }

    public function testTakeStaffMessSendsToSelectedClasses()
    {
        $admin = $this->makeUser('staffmess_send_admin', User::CLASS_ADMINISTRATOR);
        $user = $this->makeUser('staffmess_recipient', User::CLASS_USER);

        $this->postTakestaff($admin, [
            'subject' => 'Bulk subject',
            'msg' => 'Bulk body',
            'sender' => 'self',
            'classes' => [User::CLASS_USER],
        ])
            ->assertRedirect()
            ->assertRedirect('staffmess.php?sent=1');

        $this->assertSame(
            'Bulk body',
            DB::table('messages')->where('receiver', $user->id)->value('msg')
        );
        $this->assertSame(
            'Bulk subject',
            DB::table('messages')->where('receiver', $user->id)->value('subject')
        );
        $this->assertSame(
            $admin->id,
            (int) DB::table('messages')->where('receiver', $user->id)->value('sender')
        );
    }

    public function testTakeStaffMessSystemSender()
    {
        $admin = $this->makeUser('staffmess_system_admin', User::CLASS_ADMINISTRATOR);
        $user = $this->makeUser('staffmess_system_recipient', User::CLASS_USER);

        $this->postTakestaff($admin, [
            'subject' => 'S',
            'msg' => 'M',
            'sender' => 'system',
            'classes' => [User::CLASS_USER],
        ])->assertRedirect();

        $this->assertSame(0, (int) DB::table('messages')->where('receiver', $user->id)->value('sender'));
    }

    public function testTakeStaffMessSkipsDisabledAndUnconfirmed()
    {
        $admin = $this->makeUser('staffmess_skip_admin', User::CLASS_ADMINISTRATOR);
        $disabled = $this->makeUser('staffmess_disabled', User::CLASS_USER, ['enabled' => 'no']);
        $unconfirmed = $this->makeUser('staffmess_unconfirmed', User::CLASS_USER, ['status' => 'pending']);

        $this->postTakestaff($admin, [
            'subject' => 'S',
            'msg' => 'M',
            'sender' => 'self',
            'classes' => [User::CLASS_USER],
        ])->assertRedirect();

        $this->assertSame(0, DB::table('messages')->where('receiver', $disabled->id)->count());
        $this->assertSame(0, DB::table('messages')->where('receiver', $unconfirmed->id)->count());
    }

    public function testTakeStaffMessEmptyMessageRejected()
    {
        $admin = $this->makeUser('staffmess_empty_admin', User::CLASS_ADMINISTRATOR);

        $this->postTakestaff($admin, [
            'subject' => 'S',
            'msg' => '   ',
            'sender' => 'self',
            'classes' => [User::CLASS_USER],
        ])->assertStatus(400);
    }

    public function testTakeStaffMessNoClassFilterRejected()
    {
        $admin = $this->makeUser('staffmess_nofilter_admin', User::CLASS_ADMINISTRATOR);

        $this->postTakestaff($admin, [
            'subject' => 'S',
            'msg' => 'M',
            'sender' => 'self',
        ])->assertStatus(400);
    }

    public function testTakeStaffMessInvalidClassRejected()
    {
        $admin = $this->makeUser('staffmess_badclass_admin', User::CLASS_ADMINISTRATOR);

        $this->postTakestaff($admin, [
            'subject' => 'S',
            'msg' => 'M',
            'sender' => 'self',
            'clases' => ['-3'],
            'classes' => ['999'],
        ])->assertStatus(400);
    }
}