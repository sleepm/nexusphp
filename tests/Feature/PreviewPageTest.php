<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the preview.php migration
 * (PreviewController::web → preview Blade fragment).
 */
class PreviewPageTest extends TestCase
{
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

    private array $createdUserIds = [];

    private function makeUser(): User
    {
        $username = 'prev_' . substr(md5((string) mt_rand()), 0, 6);
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

    // ------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->post('/preview.php', ['body' => 'hello'])
            ->assertRedirect();
    }

    // ------------------------------------------------------- happy path

    public function testRendersFormattedBody()
    {
        $user = $this->makeUser();

        $this->asUser($user)
            ->post('/preview.php', ['body' => '[b]bold text[/b]'])
            ->assertOk()
            ->assertSee('<b>bold text</b>', false)
            ->assertSee('<table', false);
    }

    public function testRendersPlainTextBody()
    {
        $user = $this->makeUser();

        $this->asUser($user)
            ->post('/preview.php', ['body' => 'just some text'])
            ->assertOk()
            ->assertSee('just some text', false);
    }

    public function testEmptyBody()
    {
        $user = $this->makeUser();

        $this->asUser($user)
            ->post('/preview.php', ['body' => ''])
            ->assertOk();
    }

    public function testHtmlIsEscaped()
    {
        $user = $this->makeUser();

        $this->asUser($user)
            ->post('/preview.php', ['body' => '<script>alert(1)</script>'])
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }
}