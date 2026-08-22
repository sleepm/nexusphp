<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the ipsearch.php migration
 * (IpSearchController::web) against a real database.
 */
class IpSearchPageTest extends TestCase
{
    protected array $createdUserIds = [];

    protected array $createdIplogIds = [];

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
        if ($this->createdIplogIds !== []) {
            DB::table('iplog')->whereIn('id', $this->createdIplogIds)->delete();
        }
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

    private function addIplog(int $userId, string $ip): void
    {
        $this->createdIplogIds[] = DB::table('iplog')->insertGetId([
            'ip' => $ip,
            'userid' => $userId,
            'access' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    private function cookieFor(User $user): string
    {
        $tokenJson = json_encode(['user_id' => $user->id, 'expires' => time() + 3600]);
        $signature = hash_hmac('sha256', $tokenJson, $user->auth_key);
        return base64_encode($tokenJson . '.' . $signature);
    }

    private function getIpSearch(User $user, array $params = [])
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/ipsearch.php' . ($params ? '?' . http_build_query($params) : ''));
    }

    // ------------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->get('/ipsearch.php')->assertRedirect();
    }

    public function testDeniedBelowAdministrator()
    {
        $user = $this->makeUser('ipsearch_user', User::CLASS_USER);

        $this->getIpSearch($user)->assertForbidden();
    }

    public function testDeniedForModeratorWithoutUserprofilePermission()
    {
        $mod = $this->makeUser('ipsearch_mod', User::CLASS_MODERATOR);

        $this->getIpSearch($mod)->assertForbidden();
    }

    // ------------------------------------------------------------------ search

    public function testSearchByCurrentIp()
    {
        $admin = $this->makeUser('ipsearch_admin', User::CLASS_ADMINISTRATOR);
        $target = $this->makeUser('ipsearch_target', User::CLASS_USER, '203.0.113.7');

        $this->getIpSearch($admin, ['ip' => '203.0.113.7'])
            ->assertOk()
            ->assertSee('Search in IP History', false)
            ->assertSee('ipsearch_target', false)
            ->assertSee('iphistory.php?id=' . $target->id, false);
    }

    public function testSearchByIplogEntry()
    {
        $admin = $this->makeUser('ipsearch_admin2', User::CLASS_ADMINISTRATOR);
        $target = $this->makeUser('ipsearch_logged', User::CLASS_USER, '127.0.0.1');
        $this->addIplog($target->id, '198.51.100.9');

        $this->getIpSearch($admin, ['ip' => '198.51.100.9'])
            ->assertOk()
            ->assertSee('ipsearch_logged', false);
    }

    public function testNoUsersFound()
    {
        $admin = $this->makeUser('ipsearch_admin3', User::CLASS_ADMINISTRATOR);

        $this->getIpSearch($admin, ['ip' => '203.0.113.99'])
            ->assertOk()
            ->assertSee('No users found', false);
    }

    public function testInvalidIpRejected()
    {
        $admin = $this->makeUser('ipsearch_admin4', User::CLASS_ADMINISTRATOR);

        $this->getIpSearch($admin, ['ip' => 'not-an-ip'])->assertStatus(400);
    }

    public function testInvalidSubnetMaskRejected()
    {
        $admin = $this->makeUser('ipsearch_admin5', User::CLASS_ADMINISTRATOR);

        $this->getIpSearch($admin, ['ip' => '203.0.113.7', 'mask' => 'not-a-mask'])->assertStatus(400);
    }

    public function testCidrMaskSearch()
    {
        $admin = $this->makeUser('ipsearch_admin6', User::CLASS_ADMINISTRATOR);
        $this->makeUser('ipsearch_cidr_a', User::CLASS_USER, '203.0.113.5');
        $this->makeUser('ipsearch_cidr_b', User::CLASS_USER, '203.0.113.200');
        $this->makeUser('ipsearch_cidr_other', User::CLASS_USER, '198.51.100.5');

        $this->getIpSearch($admin, ['ip' => '203.0.113.5', 'mask' => '/24'])
            ->assertOk()
            ->assertSee('ipsearch_cidr_a', false)
            ->assertSee('ipsearch_cidr_b', false)
            ->assertDontSee('ipsearch_cidr_other', false);
    }
}
