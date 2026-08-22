<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserBanLog;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/user-ban-log.php migration to the
 * Filament UserBanLogResource.
 *
 * The legacy procedural "User ban log" page is replaced by the admin panel's
 * System\UserBanLogResource list page. The legacy entry point now redirects
 * there.
 */
class UserBanLogPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdBanLogIds = [];

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
        if ($this->createdBanLogIds !== []) {
            DB::table('user_ban_logs')->whereIn('id', $this->createdBanLogIds)->delete();
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
            'added' => now(),
            'last_access' => now(),
            'commentpm' => 'no',
        ]);
        $this->createdUserIds[] = $user->id;

        return $user;
    }

    private function makeBanLog(int $uid, string $username, int $operator, string $reason = 'test ban'): UserBanLog
    {
        $log = UserBanLog::query()->create([
            'uid' => $uid,
            'username' => $username,
            'operator' => $operator,
            'reason' => $reason,
        ]);
        $this->createdBanLogIds[] = $log->id;

        return $log;
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

    // ------------------------------------------------------------------ legacy entry

    public function testLegacyUserBanLogRedirectsToFilament(): void
    {
        $this->get('/user-ban-log.php')
            ->assertRedirect(route('filament.admin.resources.system.user-ban-logs.index'));
    }

    // ---------------------------------------------------------- auth on panel

    public function testUserBanLogResourceRequiresLogin(): void
    {
        $this->get('/nexusphp/system/user-ban-logs')
            ->assertStatus(302);
    }

    public function testUserBanLogResourceDeniedBelowAdministrator(): void
    {
        $user = $this->makeUser('userbanlog_moderator', User::CLASS_MODERATOR);

        $this->asUser($user)->get('/nexusphp/system/user-ban-logs')->assertForbidden();
    }

    // ------------------------------------------------------------ panel pages

    public function testAdministratorCanAccessUserBanLogResource(): void
    {
        $admin = $this->makeUser('userbanlog_admin', User::CLASS_ADMINISTRATOR);

        $this->asUser($admin)
            ->get('/nexusphp/system/user-ban-logs')
            ->assertOk();
    }

    public function testListSeesBanLog(): void
    {
        $admin = $this->makeUser('userbanlog_list', User::CLASS_ADMINISTRATOR);
        $target = $this->makeUser('userbanlog_target', User::CLASS_PEASANT);
        $log = $this->makeBanLog($target->id, $target->username, $admin->id, 'ban reason');

        $this->asUser($admin)
            ->get('/nexusphp/system/user-ban-logs')
            ->assertOk()
            ->assertSee($target->username, false)
            ->assertSee('ban reason', false);
    }
}
