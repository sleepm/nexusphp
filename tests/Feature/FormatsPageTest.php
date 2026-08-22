<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/formats.php / public/videoformats.php
 * migration (FormatsController::web / FormatsController::video).
 *
 * Both pages are static help guides that require login (the legacy pages called
 * loggedinorreturn()), so the assertions below use a signed session cookie.
 */
class FormatsPageTest extends TestCase
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
        $username = 'fmt_' . substr(md5((string) mt_rand()), 0, 6);
        $user = User::query()->create([
            'username' => $username,
            'passhash' => str_repeat('d', 32),
            'secret' => 'secret',
            'auth_key' => 'authkey_fmt_' . $username,
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

    // --------------------------------------------------------------- formats.php

    public function testFormatsRequiresLogin()
    {
        $this->get('/formats.php')->assertRedirect();
    }

    public function testFormatsPageRendersGuide()
    {
        $user = $this->makeUser();

        $this->asUser($user)
            ->get('/formats.php')
            ->assertOk()
            ->assertSee('A Handy Guide to Using the Files')
            ->assertSee('Compression Files')
            ->assertSee('Multimedia Files')
            ->assertSee('CD Image Files')
            ->assertSee('Other Files');
    }

    public function testFormatsPageRendersFileTypeSections()
    {
        $user = $this->makeUser();

        $this->asUser($user)
            ->get('/formats.php')
            ->assertOk()
            ->assertSee('.rar .zip .ace .r01 .001', false)
            ->assertSee('.avi .mpg. .mpeg .divx .xvid .wmv', false)
            ->assertSee('.bin and .cue', false)
            ->assertSee('.nfo', false)
            ->assertSee('.pdf', false)
            ->assertSee('.par', false);
    }

    // --------------------------------------------------------- videoformats.php

    public function testVideoFormatsRequiresLogin()
    {
        $this->get('/videoformats.php')->assertRedirect();
    }

    public function testVideoFormatsPageRendersGuide()
    {
        $user = $this->makeUser();

        $this->asUser($user)
            ->get('/videoformats.php')
            ->assertOk()
            ->assertSee('Downloaded a movie and don\'t know what CAM/TS/TC/SCR means?', false)
            ->assertSee('TELESYNC (TS)')
            ->assertSee('DVDRip')
            ->assertSee('Scene Tags')
            ->assertSee('NUKE REASONS');
    }

    public function testVideoFormatsPageRendersReleaseTypes()
    {
        $user = $this->makeUser();

        $this->asUser($user)
            ->get('/videoformats.php')
            ->assertOk()
            ->assertSee('CAM -')
            ->assertSee('TELECINE (TC)')
            ->assertSee('SCREENER (SCR)')
            ->assertSee('WORKPRINT (WP)')
            ->assertSee('PROPER -')
            ->assertSee('NUKED -')
            ->assertSee('DUPE -');
    }
}
