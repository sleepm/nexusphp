<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/smilies.php / public/moresmilies.php
 * migration (SmiliesController::web / SmiliesController::more).
 *
 * Both pages require login (the legacy pages called loggedinorreturn()), so
 * the assertions below use a signed session cookie.
 */
class SmiliesPageTest extends TestCase
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
        $username = 'sml_' . substr(md5((string) mt_rand()), 0, 6);
        $user = User::query()->create([
            'username' => $username,
            'passhash' => str_repeat('d', 32),
            'secret' => 'secret',
            'auth_key' => 'authkey_sml_' . $username,
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

    // ------------------------------------------------------------- smilies.php

    public function testSmiliesRequiresLogin()
    {
        $this->get('/smilies.php')->assertRedirect();
    }

    public function testSmiliesPageRendersEmTags()
    {
        $user = $this->makeUser();

        $this->asUser($user)
            ->get('/smilies.php')
            ->assertOk()
            ->assertSee('Smilies', false)
            ->assertSee('[em1]', false)
            ->assertSee('[em191]', false)
            ->assertSee('pic/smilies/1.gif', false)
            ->assertSee('pic/smilies/191.gif', false);
    }

    public function testSmiliesPageRendersColumnHeaders()
    {
        $user = $this->makeUser();

        $this->asUser($user)
            ->get('/smilies.php')
            ->assertOk()
            ->assertSee('Type...', false)
            ->assertSee('To make a...', false);
    }

    // -------------------------------------------------------- moresmilies.php

    public function testMoreSmiliesRequiresLogin()
    {
        $this->get('/moresmilies.php')->assertRedirect();
    }

    public function testMoreSmiliesRendersPopupGrid()
    {
        $user = $this->makeUser();

        $this->asUser($user)
            ->get('/moresmilies.php?form=upload&text=descr')
            ->assertOk()
            ->assertSee('More Clickable Smilies', false)
            ->assertSee('SmileIT', false)
            ->assertSee('[em1]', false)
            ->assertSee('[em191]', false)
            ->assertSee('pic/smilies/1.gif', false)
            ->assertSee('window.close()', false);
    }

    public function testMoreSmiliesEscapesFormAndTextParams()
    {
        $user = $this->makeUser();

        $this->asUser($user)
            ->get('/moresmilies.php?form=%22%3E%3Cscript%3E&text=body%27x')
            ->assertOk()
            ->assertDontSee('<script>', false)
            ->assertDontSee('body\'x', false);
    }
}
