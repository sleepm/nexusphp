<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the staffpanel.php migration
 * (StaffPanelController::web) against a real database.
 */
class StaffPanelPageTest extends TestCase
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
            'added' => now(),
            'last_access' => now(),
            'commentpm' => 'no',
            'last_pm' => now()->subMinutes(5),
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

    private function getStaffPanel(User $user)
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/staffpanel.php');
    }

    private function seedPanel(string $table, string $name, string $url)
    {
        DB::table($table)->insert(['name' => $name, 'url' => $url, 'info' => 'panel info']);
    }

    // ------------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->get('/staffpanel.php')->assertRedirect();
    }

    public function testDeniedBelowModerator()
    {
        $user = $this->makeUser('staffpanel_user', User::CLASS_USER);

        $this->getStaffPanel($user)->assertForbidden();
    }

    // ------------------------------------------------------------------ view

    public function testModeratorSeesOnlyModPanel()
    {
        $mod = $this->makeUser('staffpanel_mod', User::CLASS_MODERATOR);
        $this->seedPanel('modpanel', 'Mod Tool', '/modtools.php');

        $this->getStaffPanel($mod)
            ->assertOk()
            ->assertSee('Administration', false)
            ->assertSee('For Moderator Only', false)
            ->assertSee('Mod Tool', false)
            ->assertDontSee('For SysOp Only', false)
            ->assertDontSee('For Administrator Only', false);
    }

    public function testAdminSeesAdminAndModPanels()
    {
        $admin = $this->makeUser('staffpanel_admin', User::CLASS_ADMINISTRATOR);

        $this->getStaffPanel($admin)
            ->assertOk()
            ->assertSee('For Administrator Only', false)
            ->assertSee('For Moderator Only', false)
            ->assertDontSee('For SysOp Only', false);
    }

    public function testSysOpSeesAllPanels()
    {
        $sysop = $this->makeUser('staffpanel_sysop', User::CLASS_SYSOP);
        $this->seedPanel('sysoppanel', 'SysOp Tool', '/sysoppanel.php');

        $this->getStaffPanel($sysop)
            ->assertOk()
            ->assertSee('For SysOp Only', false)
            ->assertSee('For Administrator Only', false)
            ->assertSee('For Moderator Only', false)
            ->assertSee('SysOp Tool', false);
    }
}