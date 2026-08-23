<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the ok.php migration
 * (ToolController::notification → error/notification Blade view).
 */
class NotificationPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
    }

    private function makeUser(): User
    {
        $username = 'notif_' . substr(md5((string) mt_rand()), 0, 6);
        $user = User::query()->create([
            'username' => $username,
            'passhash' => str_repeat('a', 32),
            'secret' => 'secret',
            'auth_key' => 'authkey_' . $username,
            'email' => $username . '@example.com',
            'status' => 'confirmed',
            'enabled' => 'yes',
            'class' => User::CLASS_USER,
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

    private array $createdUserIds = [];

    protected function tearDown(): void
    {
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
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

    // --------------------------------------------------------- public access

    public function testPageIsPublic()
    {
        $this->get('/ok.php?type=confirm')
            ->assertOk();
    }

    public function testMissingTypeReturns404()
    {
        $this->get('/ok.php')->assertStatus(404);
    }

    public function testInvalidTypeReturns404()
    {
        $this->get('/ok.php?type=nonexistent')->assertStatus(404);
    }

    public function testSignupWithoutEmailReturns404()
    {
        $this->get('/ok.php?type=signup')->assertStatus(404);
    }

    // -------------------------------------------------------- type=adminactivate

    public function testAdminactivateType()
    {
        $this->get('/ok.php?type=adminactivate')
            ->assertOk()
            ->assertSee('Signup successful but Account not activated!')
            ->assertSee('Admin must validate new members');
    }

    // -------------------------------------------------------- type=inviter

    public function testInviterType()
    {
        $this->get('/ok.php?type=inviter')
            ->assertOk()
            ->assertSee('Signup successful but Account not activated!')
            ->assertSee('your inviter must validate new members');
    }

    // -------------------------------------------------------- type=signup

    public function testSignupType()
    {
        $this->get('/ok.php?type=signup&email=test@example.com')
            ->assertOk()
            ->assertSee('Signup successful')
            ->assertSee('test@example.com');
    }

    // -------------------------------------------------------- type=sysop

    public function testSysopTypeWithoutLogin()
    {
        $this->get('/ok.php?type=sysop')
            ->assertOk()
            ->assertSee('Sysop Account successfully activated')
            ->assertSee('disabled cookies in your browser');
    }

    public function testSysopTypeWithLogin()
    {
        $user = $this->makeUser();
        $this->asUser($user)
            ->get('/ok.php?type=sysop')
            ->assertOk()
            ->assertSee('Sysop Account successfully activated')
            ->assertSee('automatically logged in');
    }

    // -------------------------------------------------------- type=confirmed

    public function testConfirmedType()
    {
        $this->get('/ok.php?type=confirmed')
            ->assertOk()
            ->assertSee('Already confirmed');
    }

    // -------------------------------------------------------- type=confirm

    public function testConfirmTypeWithoutLogin()
    {
        $this->get('/ok.php?type=confirm')
            ->assertOk()
            ->assertSee('Account successfully confirmed')
            ->assertSee('disabled cookies in your browser');
    }

    public function testConfirmTypeWithLogin()
    {
        $user = $this->makeUser();
        $siteName = Setting::getSiteName();
        $this->asUser($user)
            ->get('/ok.php?type=confirm')
            ->assertOk()
            ->assertSee('Account successfully confirmed')
            ->assertSee('automatically logged in')
            ->assertSee($siteName);
    }
}