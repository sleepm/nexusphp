<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the massmail.php migration
 * (MassMailController::web) against a real database.
 */
class MassMailPageTest extends TestCase
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
            'added' => now(),
            'last_access' => now(),
            'commentpm' => 'no',
            'last_pm' => now()->subMinutes(5),
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

    private function callMassMail(User $user, string $method, array $data = [])
    {
        app('auth')->forgetGuards();
        $request = $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en');

        return strtoupper($method) === 'POST'
            ? $request->post('/massmail.php', $data)
            : $request->get('/massmail.php');
    }

    // ------------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->get('/massmail.php')->assertRedirect();
    }

    public function testDeniedBelowSysOp()
    {
        $admin = $this->makeUser('massmail_admin', User::CLASS_ADMINISTRATOR);

        $this->callMassMail($admin, 'GET')->assertForbidden();
    }

    // ------------------------------------------------------------------ form

    public function testFormRenders()
    {
        $sysop = $this->makeUser('massmail_sysop', User::CLASS_SYSOP);

        $this->callMassMail($sysop, 'GET')
            ->assertOk()
            ->assertSee('Send mass e-mail to all members', false)
            ->assertSee('action=massmail.php', false)
            ->assertSee('name=subject', false)
            ->assertSee('name=message', false);
    }

    // -------------------------------------------------------------- submit

    public function testInvalidOperatorRejected()
    {
        $sysop = $this->makeUser('massmail_invalidop', User::CLASS_SYSOP);

        $this->callMassMail($sysop, 'POST', [
            'class' => 0,
            'or' => '^^',
            'subject' => 'hi',
            'message' => 'body',
        ])->assertStatus(400);
    }

    public function testEmptyMessageRejected()
    {
        $sysop = $this->makeUser('massmail_empty', User::CLASS_SYSOP);

        $this->callMassMail($sysop, 'POST', [
            'class' => 0,
            'or' => '>=',
            'subject' => 'hi',
            'message' => '   ',
        ])->assertStatus(400);
    }

    public function testSendMailSuccess()
    {
        $sysop = $this->makeUser('massmail_ok', User::CLASS_SYSOP);
        $this->makeUser('massmail_target', User::CLASS_USER);

        $this->callMassMail($sysop, 'POST', [
            'class' => 0,
            'or' => '>=',
            'subject' => 'Hello there',
            'message' => 'A bulk message',
        ])
            ->assertOk()
            ->assertSee('Messages sent.', false);
    }
}