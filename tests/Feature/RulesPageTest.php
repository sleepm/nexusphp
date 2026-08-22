<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/rules.php migration (RulesController::web).
 *
 * The rules page is public (no auth), so the plain-route assertions below use
 * no session cookie. Content is served from the rules table for the guest
 * language (English here), falling back to English (lang_id 6) when the guest
 * language is not marked rule_lang.
 */
class RulesPageTest extends TestCase
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
        $username = 'rules_' . substr(md5((string) mt_rand()), 0, 6);
        $user = User::query()->create([
            'username' => $username,
            'passhash' => str_repeat('d', 32),
            'secret' => 'secret',
            'auth_key' => 'authkey_rules_' . $username,
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

    public function testRulesPageIsPublic()
    {
        $this->get('/rules.php')
            ->assertOk()
            ->assertSee('Rules');
    }

    public function testRulesPageRendersRuleTitles()
    {
        $this->withCookie('c_lang_folder', 'en')
            ->get('/rules.php')
            ->assertOk()
            ->assertSee('General rules', false)
            ->assertSee('Downloading rules', false);
    }

    public function testRulesPageRendersAllRules()
    {
        $response = $this->withCookie('c_lang_folder', 'en')
            ->get('/rules.php');

        $response->assertOk();

        $rules = DB::table('rules')->where('lang_id', 6)->get();
        $this->assertGreaterThan(0, $rules->count(), 'Expected at least one rule for lang_id 6');

        foreach ($rules as $rule) {
            $response->assertSee($rule->title, false);
        }
    }

    public function testRulesPageWorksWhenLoggedIn()
    {
        $user = $this->makeUser();

        $this->asUser($user)
            ->get('/rules.php')
            ->assertOk()
            ->assertSee('General rules', false);
    }

    public function testRulesPageFallsBackToEnglish()
    {
        $this->withCookie('c_lang_folder', 'ru')
            ->get('/rules.php')
            ->assertOk()
            ->assertSee('General rules', false);
    }
}