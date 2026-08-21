<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/tags.php migration (TagController::web).
 *
 * The BB-tags help page is public (no auth), so the plain-route assertions
 * below use no session cookie. The POST "test this code" form is excluded from
 * CSRF verification like the legacy page.
 */
class TagPageTest extends TestCase
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
        $username = 'tag_' . substr(md5((string) mt_rand()), 0, 6);
        $user = User::query()->create([
            'username' => $username,
            'passhash' => str_repeat('d', 32),
            'secret' => 'secret',
            'auth_key' => 'authkey_tag_' . $username,
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

    // ------------------------------------------------------------------ guests

    public function testTagsPageIsPublic()
    {
        $this->get('/tags.php')
            ->assertOk()
            ->assertSee('Tags');
    }

    public function testTagsPageShowsTagTables()
    {
        $this->get('/tags.php')
            ->assertOk()
            ->assertSee('Syntax:')
            ->assertSee('Example:')
            ->assertSee('Result:')
            ->assertSee('Bold')
            ->assertSee('Italic')
            ->assertSee('YouTube')
            ->assertSee('Spoiler');
    }

    public function testTagsPageRendersTestForm()
    {
        $this->get('/tags.php')
            ->assertOk()
            ->assertSee('name="test"', false)
            ->assertSee('Test&nbsp;this&nbsp;code!', false);
    }

    // ------------------------------------------------------------------- post

    public function testTagsPageRendersBbcodePreviewOnPost()
    {
        $this->post('/tags.php', ['test' => '[b]hello bold[/b]'])
            ->assertOk()
            ->assertSee('<b>hello bold</b>', false)
            ->assertSee('[b]hello bold[/b]', false);
    }

    // -------------------------------------------------------------- logged in

    public function testTagsPageRendersUsernameInQuoteExampleWhenLoggedIn()
    {
        $user = $this->makeUser();

        $this->asUser($user)
            ->get('/tags.php')
            ->assertOk()
            ->assertSee($user->username);
    }

    public function testTagsPageWorksForGuestsDespiteQuoteExample()
    {
        $this->get('/tags.php')
            ->assertOk()
            ->assertSee('Quote (alt. 2)');
    }
}
