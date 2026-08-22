<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the ipcheck.php migration
 * (IpCheckController::web) against a real database.
 */
class IpCheckPageTest extends TestCase
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

    private function makeUser(string $name, int $class, string $ip = '127.0.0.1'): User
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
            'ip' => $ip,
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
        DB::table('users')->where('id', $user->id)->update(['ip' => $ip]);

        return $user;
    }

    private function cookieFor(User $user): string
    {
        $tokenJson = json_encode(['user_id' => $user->id, 'expires' => time() + 3600]);
        $signature = hash_hmac('sha256', $tokenJson, $user->auth_key);
        return base64_encode($tokenJson . '.' . $signature);
    }

    private function getIpCheck(User $user)
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/ipcheck.php');
    }

    // ------------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->get('/ipcheck.php')->assertRedirect();
    }

    public function testDeniedBelowModerator()
    {
        $user = $this->makeUser('ipcheck_user', User::CLASS_USER);

        $this->getIpCheck($user)->assertForbidden();
    }

    // ------------------------------------------------------------------ view

    public function testModeratorSeesDuplicateIpUsers()
    {
        $mod = $this->makeUser('ipcheck_mod', User::CLASS_MODERATOR, '10.1.1.1');
        $dupeA = $this->makeUser('ipcheck_dupe_a', User::CLASS_USER, '203.0.113.5');
        $dupeB = $this->makeUser('ipcheck_dupe_b', User::CLASS_USER, '203.0.113.5');
        $unique = $this->makeUser('ipcheck_unique', User::CLASS_USER, '198.51.100.5');

        $this->getIpCheck($mod)
            ->assertOk()
            ->assertSee('Duplicate IP users', false)
            ->assertSee('ipcheck_dupe_a', false)
            ->assertSee('ipcheck_dupe_b', false)
            ->assertSee('203.0.113.5', false)
            ->assertDontSee('ipcheck_unique', false)
            ->assertDontSee('198.51.100.5', false);
    }

    public function testGroupRequiresTwoEnabledUsers()
    {
        $mod = $this->makeUser('ipcheck_mod3', User::CLASS_MODERATOR, '10.1.1.3');
        $enabled = $this->makeUser('ipcheck_solo_a', User::CLASS_USER, '203.0.113.9');
        $disabled = $this->makeUser('ipcheck_solo_b', User::CLASS_USER, '203.0.113.9');
        DB::table('users')->where('id', $disabled->id)->update(['enabled' => 'no']);

        // only one enabled user on that IP -> the dupl group is skipped entirely
        $this->getIpCheck($mod)
            ->assertOk()
            ->assertDontSee('ipcheck_solo_a', false)
            ->assertDontSee('ipcheck_solo_b', false);
    }
}
