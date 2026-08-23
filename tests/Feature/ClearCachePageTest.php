<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the clearcache.php migration
 * (ClearCacheController::web) against a real database.
 */
class ClearCachePageTest extends TestCase
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
        $this->get('/clearcache.php')->assertRedirect();
    }

    public function testDeniedBelowModerator()
    {
        $user = $this->makeUser('clearcache_user', User::CLASS_USER);

        $this->asUser($user)->get('/clearcache.php')->assertForbidden();
    }

    // ------------------------------------------------------------------ view

    public function testFormRenders()
    {
        $mod = $this->makeUser('clearcache_mod', User::CLASS_MODERATOR);

        $this->asUser($mod)->get('/clearcache.php')
            ->assertOk()
            ->assertSee('Clear cache', false)
            ->assertSee('name=cachename', false)
            ->assertSee('name=multilang', false);
    }

    public function testEmptyCacheNameRejected()
    {
        $mod = $this->makeUser('clearcache_empty_mod', User::CLASS_MODERATOR);

        $this->asUser($mod)->post('/clearcache.php', ['cachename' => ''])
            ->assertOk()
            ->assertSee('You must fill in cache name.', false);
    }

    public function testClearsCacheValue()
    {
        $mod = $this->makeUser('clearcache_clear_mod', User::CLASS_MODERATOR);
        $key = 'clearcache_test_key_' . uniqid();
        Cache::put($key, 'cached-value', 60);
        $this->assertNotNull(Cache::get($key));

        $this->asUser($mod)->post('/clearcache.php', ['cachename' => $key])
            ->assertOk()
            ->assertSee('Cache cleared', false);

        $this->assertNull(Cache::get($key));
    }

    public function testClearsMultilangCacheValue()
    {
        $mod = $this->makeUser('clearcache_ml_mod', User::CLASS_MODERATOR);
        $key = 'clearcache_ml_test_key_' . uniqid();
        Cache::put($key, 'cached-value', 60);
        $this->assertNotNull(Cache::get($key));

        $this->asUser($mod)->post('/clearcache.php', ['cachename' => $key, 'multilang' => 'yes'])
            ->assertOk()
            ->assertSee('Cache cleared', false);

        $this->assertNull(Cache::get($key));
    }
}
