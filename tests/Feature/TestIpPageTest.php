<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the testip.php migration
 * (TestIpController::web) against a real database.
 */
class TestIpPageTest extends TestCase
{
    protected array $createdUserIds = [];

    protected array $createdBanIds = [];

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
        if ($this->createdBanIds !== []) {
            DB::table('bans')->whereIn('id', $this->createdBanIds)->delete();
        }
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
        DB::table('users')->where('id', $user->id)->update(['ip' => '127.0.0.1']);

        return $user;
    }

    private function makeBan(string $first, string $last, string $comment): void
    {
        $this->createdBanIds[] = DB::table('bans')->insertGetId([
            'added' => now()->format('Y-m-d H:i:s'),
            'addedby' => 0,
            'first' => ip2long($first),
            'last' => ip2long($last),
            'comment' => $comment,
        ]);
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
        $this->get('/testip.php')->assertRedirect();
    }

    public function testDeniedBelowModerator()
    {
        $user = $this->makeUser('testip_user', User::CLASS_USER);

        $this->asUser($user)->get('/testip.php')->assertForbidden();
    }

    // ------------------------------------------------------------------ view

    public function testFormRenders()
    {
        $mod = $this->makeUser('testip_mod', User::CLASS_MODERATOR);

        $this->asUser($mod)->get('/testip.php')
            ->assertOk()
            ->assertSee('Test IP address', false)
            ->assertSee('name=ip', false);
    }

    public function testBannedIpShowsBanRanges()
    {
        $mod = $this->makeUser('testip_mod2', User::CLASS_MODERATOR);
        $this->makeBan('192.0.2.0', '192.0.2.255', 'example ban');

        $this->asUser($mod)->post('/testip.php', ['ip' => '192.0.2.10'])
            ->assertOk()
            ->assertSee('is banned', false)
            ->assertSee('192.0.2.0', false)
            ->assertSee('192.0.2.255', false)
            ->assertSee('example ban', false);
    }

    public function testNonBannedIpShowsNotBanned()
    {
        $mod = $this->makeUser('testip_mod3', User::CLASS_MODERATOR);
        $this->makeBan('192.0.2.0', '192.0.2.255', 'example ban');

        $this->asUser($mod)->post('/testip.php', ['ip' => '198.51.100.5'])
            ->assertOk()
            ->assertSee('is not banned', false);
    }

    public function testBadIpRejected()
    {
        $mod = $this->makeUser('testip_mod4', User::CLASS_MODERATOR);

        $this->asUser($mod)->post('/testip.php', ['ip' => 'not-an-ip'])->assertStatus(400);
    }
}
