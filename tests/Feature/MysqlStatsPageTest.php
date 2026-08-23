<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the mysql_stats.php migration
 * (MysqlStatsController::web) against a real database.
 */
class MysqlStatsPageTest extends TestCase
{
    protected array $createdUserIds = [];

    protected array $originalSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        // basic.SITENAME is needed by Setting::getSiteName() when rendering
        // the guest layout; seed it before the first request so the Setting
        // static cache sees it.
        $this->setSetting('basic.SITENAME', 'TestSite');
    }

    protected function tearDown(): void
    {
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        if ($this->originalSettings !== []) {
            foreach ($this->originalSettings as $name => $value) {
                if ($value === null) {
                    DB::table('settings')->where('name', $name)->delete();
                } else {
                    DB::table('settings')->where('name', $name)->update(['value' => $value]);
                }
            }
            app('cache')->forget('nexus_settings_in_laravel');
            app('cache')->forget('nexus_settings_in_nexus');
            \Nexus\Database\NexusDB::cache_del('nexus_settings_in_laravel');
            \Nexus\Database\NexusDB::cache_del('nexus_settings_in_nexus');
        }
        parent::tearDown();
    }

    private function setSetting(string $name, $value): void
    {
        $row = DB::table('settings')->where('name', $name)->first();
        $this->originalSettings[$name] = $row ? $row->value : null;
        $stored = is_array($value) ? json_encode($value) : $value;
        if ($row) {
            DB::table('settings')->where('name', $name)->update(['value' => $stored]);
        } else {
            DB::table('settings')->insert([
                'name' => $name,
                'value' => $stored,
                'created_at' => now(),
                'updated_at' => now(),
                'autoload' => 'yes',
            ]);
        }
        app('cache')->forget('nexus_settings_in_laravel');
        app('cache')->forget('nexus_settings_in_nexus');
        \Nexus\Database\NexusDB::cache_del('nexus_settings_in_laravel');
        \Nexus\Database\NexusDB::cache_del('nexus_settings_in_nexus');
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

    private function getMysqlStats(User $user)
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/mysql_stats.php');
    }

    // ------------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->get('/mysql_stats.php')->assertRedirect();
    }

    public function testDeniedBelowSysop()
    {
        $user = $this->makeUser('mysqlstats_user', User::CLASS_USER);

        $this->getMysqlStats($user)->assertForbidden();
    }

    // ------------------------------------------------------------------ view

    public function testSysopSeesServerStatus()
    {
        $sysop = $this->makeUser('mysqlstats_sysop', User::CLASS_SYSOP);

        $this->getMysqlStats($sysop)
            ->assertOk()
            ->assertSee('Mysql Server Status', false)
            ->assertSee('has been running for', false)
            ->assertSee('Server traffic', false)
            ->assertSee('Query Statistics', false)
            ->assertSee('More status variables', false)
            ->assertSee('Bytes', false);
    }

    public function testUptimeAndTrafficRendered()
    {
        $sysop = $this->makeUser('mysqlstats_sysop2', User::CLASS_SYSOP);

        $this->getMysqlStats($sysop)
            ->assertOk()
            ->assertSee('Days', false)
            ->assertSee('Connections', false);
    }
}
