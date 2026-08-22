<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the iphistory.php migration
 * (IpHistoryController::web) against a real database.
 */
class IpHistoryPageTest extends TestCase
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

    private function addIplog(int $userId, string $ip, ?string $access = null): void
    {
        $this->createdIplogIds[] = DB::table('iplog')->insertGetId([
            'ip' => $ip,
            'userid' => $userId,
            'access' => $access ?? now()->subDay()->format('Y-m-d H:i:s'),
        ]);
    }

    private function cookieFor(User $user): string
    {
        $tokenJson = json_encode(['user_id' => $user->id, 'expires' => time() + 3600]);
        $signature = hash_hmac('sha256', $tokenJson, $user->auth_key);
        return base64_encode($tokenJson . '.' . $signature);
    }

    private function getIpHistory(User $user, int $id)
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/iphistory.php?id=' . $id);
    }

    // ------------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->get('/iphistory.php?id=1')->assertRedirect();
    }

    public function testDeniedBelowAdministrator()
    {
        $user = $this->makeUser('iphistory_user', User::CLASS_USER);

        $this->getIpHistory($user, 1)->assertForbidden();
    }

    public function testDeniedForModeratorWithoutUserprofilePermission()
    {
        $mod = $this->makeUser('iphistory_mod', User::CLASS_MODERATOR);

        $this->getIpHistory($mod, 1)->assertForbidden();
    }

    // ------------------------------------------------------------------ view

    public function testUserNotFound()
    {
        $admin = $this->makeUser('iphistory_admin', User::CLASS_ADMINISTRATOR);

        $this->getIpHistory($admin, 99999999)->assertStatus(400);
    }

    public function testShowsCurrentIpAndIplogEntries()
    {
        $admin = $this->makeUser('iphistory_admin2', User::CLASS_ADMINISTRATOR);
        $target = $this->makeUser('iphistory_target', User::CLASS_USER, '203.0.113.7');
        $this->addIplog($target->id, '198.51.100.9');

        $this->getIpHistory($admin, $target->id)
            ->assertOk()
            ->assertSee('Historical IP addresses used by', false)
            ->assertSee('iphistory_target', false)
            ->assertSee('203.0.113.7', false)
            ->assertSee('198.51.100.9', false);
    }

    public function testDuplicateIpMarked()
    {
        $admin = $this->makeUser('iphistory_admin3', User::CLASS_ADMINISTRATOR);
        $target = $this->makeUser('iphistory_target2', User::CLASS_USER, '203.0.113.8');
        $this->makeUser('iphistory_other', User::CLASS_USER, '203.0.113.8');

        $this->getIpHistory($admin, $target->id)
            ->assertOk()
            ->assertSee('Dupe', false);
    }
}
