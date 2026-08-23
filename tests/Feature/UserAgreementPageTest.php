<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/useragreement.php migration
 * (UserAgreementController::web).
 *
 * The user agreement page is public (no auth). It renders the static legalese
 * text with the site name and base URL interpolated.
 */
class UserAgreementPageTest extends TestCase
{
    private array $createdUserIds = [];

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

    private function makeUser(): User
    {
        $username = 'uag_' . substr(md5((string) mt_rand()), 0, 6);
        $user = User::query()->create([
            'username' => $username,
            'passhash' => str_repeat('d', 32),
            'secret' => 'secret',
            'auth_key' => 'authkey_uag_' . $username,
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

    public function testPageIsPublic()
    {
        $this->get('/useragreement.php')
            ->assertOk()
            ->assertSee('User Agreement', false);
    }

    public function testPageRendersSiteName()
    {
        $siteName = Setting::getSiteName();

        $this->get('/useragreement.php')
            ->assertOk()
            ->assertSee($siteName, false);
    }

    public function testPageRendersLegalSections()
    {
        $this->get('/useragreement.php')
            ->assertOk()
            ->assertSee('PERMITTED USE', false)
            ->assertSee('RESTRICTED ACCESS', false)
            ->assertSee('LEGAL NOTICE OF INFRINGEMENT', false)
            ->assertSee('NO WARRANTY', false)
            ->assertSee('DISPUTE RESOLUTION', false)
            ->assertSee('Privacy and Security Statement', false);
    }

    public function testPageWorksWhenLoggedIn()
    {
        $user = $this->makeUser();

        $this->asUser($user)
            ->get('/useragreement.php')
            ->assertOk()
            ->assertSee('User Agreement', false);
    }
}