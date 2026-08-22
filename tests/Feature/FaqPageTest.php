<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/faq.php migration (FaqController::web).
 *
 * The FAQ page is public (no auth). It renders a welcome intro, a table of
 * contents and the FAQ categories + items stored in the faq table for the
 * guest language (English here).
 */
class FaqPageTest extends TestCase
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
        $username = 'faq_' . substr(md5((string) mt_rand()), 0, 6);
        $user = User::query()->create([
            'username' => $username,
            'passhash' => str_repeat('d', 32),
            'secret' => 'secret',
            'auth_key' => 'authkey_faq_' . $username,
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

    public function testFaqPageIsPublic()
    {
        $this->get('/faq.php')
            ->assertOk()
            ->assertSee('FAQ');
    }

    public function testFaqPageRendersWelcomeIntro()
    {
        $this->withCookie('c_lang_folder', 'en')
            ->get('/faq.php')
            ->assertOk()
            ->assertSee('Welcome to', false)
            ->assertSee('private tracker', false)
            ->assertSee('rules', false);
    }

    public function testFaqPageRendersTocAndCategories()
    {
        $response = $this->withCookie('c_lang_folder', 'en')
            ->get('/faq.php');

        $response->assertOk()
            ->assertSee('Contents', false)
            ->assertSee('Site information', false)
            ->assertSee('User information', false);
    }

    public function testFaqPageRendersAllCategories()
    {
        $response = $this->withCookie('c_lang_folder', 'en')
            ->get('/faq.php');

        $response->assertOk();

        $categs = DB::table('faq')
            ->where('lang_id', 6)
            ->where('type', 'categ')
            ->where('flag', 1)
            ->get();

        $this->assertGreaterThan(0, $categs->count(), 'Expected at least one FAQ category for lang_id 6');

        foreach ($categs as $categ) {
            $response->assertSee($categ->question, false);
        }
    }

    public function testFaqPageRendersItems()
    {
        $response = $this->withCookie('c_lang_folder', 'en')
            ->get('/faq.php');

        $response->assertOk();

        $item = DB::table('faq')
            ->where('lang_id', 6)
            ->where('type', 'item')
            ->where('flag', 1)
            ->first();

        $this->assertNotNull($item, 'Expected at least one FAQ item for lang_id 6');

        $response->assertSee($item->question, false);
    }

    public function testFaqPageWorksWhenLoggedIn()
    {
        $user = $this->makeUser();

        $this->asUser($user)
            ->get('/faq.php')
            ->assertOk()
            ->assertSee('Site information', false);
    }

    public function testFaqPageFallsBackToEnglish()
    {
        $this->withCookie('c_lang_folder', 'ru')
            ->get('/faq.php')
            ->assertOk()
            ->assertSee('Site information', false);
    }
}
