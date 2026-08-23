<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/aboutnexus.php migration
 * (AboutNexusController::web).
 *
 * The about page is public (no auth). It renders version info, a NexusPHP
 * description, translation status (language table), stylesheet credits and
 * contact info.
 */
class AboutNexusPageTest extends TestCase
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
        $username = 'abt_' . substr(md5((string) mt_rand()), 0, 6);
        $user = User::query()->create([
            'username' => $username,
            'passhash' => str_repeat('d', 32),
            'secret' => 'secret',
            'auth_key' => 'authkey_abt_' . $username,
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
        $this->get('/aboutnexus.php')
            ->assertOk()
            ->assertSee(PROJECTNAME, false);
    }

    public function testPageRendersVersionInfo()
    {
        $this->get('/aboutnexus.php')
            ->assertOk()
            ->assertSee(VERSION_NUMBER, false)
            ->assertSee(RELEASE_DATE, false)
            ->assertSee('Version', false);
    }

    public function testPageRendersTranslationSection()
    {
        $response = $this->get('/aboutnexus.php');

        $response->assertOk()
            ->assertSee('About Translation', false);

        $languages = Language::query()->orderBy('trans_state')->get(['lang_name', 'trans_state']);
        if ($languages->isNotEmpty()) {
            $response->assertSee($languages->first()->lang_name, false);
        }
    }

    public function testPageRendersStylesheets()
    {
        $response = $this->get('/aboutnexus.php');

        $response->assertOk()
            ->assertSee('About Stylesheet', false);

        $style = DB::table('stylesheets')->orderBy('id')->first();
        if ($style) {
            $response->assertSee($style->name, false);
        }
    }

    public function testPageRendersContactSection()
    {
        $this->get('/aboutnexus.php')
            ->assertOk()
            ->assertSee('Contact', false)
            ->assertSee(NEXUSPHPURL, false);
    }

    public function testPageWorksWhenLoggedIn()
    {
        $user = $this->makeUser();

        $this->asUser($user)
            ->get('/aboutnexus.php')
            ->assertOk()
            ->assertSee(PROJECTNAME, false);
    }
}