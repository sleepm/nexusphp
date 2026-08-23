<?php

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\ToolRepository;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the mailtest.php migration
 * (MailTestController::web) against a real database.
 */
class MailTestPageTest extends TestCase
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
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $name, int $class): User
    {
        $user = User::query()->create([
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
            'added' => now()->format('Y-m-d H:i:s'),
            'last_access' => now()->format('Y-m-d H:i:s'),
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

    // ------------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->get('/mailtest.php')->assertRedirect();
    }

    public function testDeniedBelowSysop()
    {
        $user = $this->makeUser('mailtest_user', User::CLASS_USER);

        $this->asUser($user)->get('/mailtest.php')->assertForbidden();
    }

    // ------------------------------------------------------------------ view

    public function testFormRenders()
    {
        $sysop = $this->makeUser('mailtest_sysop', User::CLASS_SYSOP);

        $this->asUser($sysop)->get('/mailtest.php')
            ->assertOk()
            ->assertSee('Mail Test', false)
            ->assertSee("name='email'", false)
            ->assertSee("name='action'", false);
    }

    // ------------------------------------------------------------------ submit

    public function testInvalidEmailRejected()
    {
        $sysop = $this->makeUser('mailtest_bad_sysop', User::CLASS_SYSOP);

        $this->asUser($sysop)->post('/mailtest.php', [
            'action' => 'sendmail',
            'email' => 'not-an-email',
        ])
            ->assertOk()
            ->assertSee('Invalid email address!', false);
    }

    public function testSendSucceeds()
    {
        $sysop = $this->makeUser('mailtest_ok_sysop', User::CLASS_SYSOP);

        $this->mock(ToolRepository::class)
            ->shouldReceive('sendMail')
            ->once()
            ->withArgs(fn ($to, $subject, $body, $exception) =>
                $to === 'test@example.com' && $subject !== '' && $body !== '' && $exception === true)
            ->andReturn(true);

        $this->asUser($sysop)->post('/mailtest.php', [
            'action' => 'sendmail',
            'email' => 'test@example.com',
        ])
            ->assertOk()
            ->assertSee('No error found', false);
    }

    public function testSendFailureReported()
    {
        $sysop = $this->makeUser('mailtest_fail_sysop', User::CLASS_SYSOP);

        $this->mock(ToolRepository::class)
            ->shouldReceive('sendMail')
            ->once()
            ->andThrow(new \RuntimeException('SMTP connection refused'));

        $this->asUser($sysop)->post('/mailtest.php', [
            'action' => 'sendmail',
            'email' => 'test@example.com',
        ])
            ->assertOk()
            ->assertSee('Unable to send mail', false)
            ->assertSee('SMTP connection refused', false);
    }
}
