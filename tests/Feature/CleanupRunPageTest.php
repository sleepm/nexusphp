<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/docleanup.php migration to the
 * cleanup:run Laravel command.
 *
 * The legacy SYSOP page is replaced by `php artisan cleanup:run [--forceall]`;
 * the legacy URL now redirects to the staff control panel.
 */
class CleanupRunPageTest extends TestCase
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

    private function getDocleanup(User $user)
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/docleanup.php');
    }

    // ------------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->get('/docleanup.php')->assertRedirect();
    }

    // ------------------------------------------------------------- redirect

    public function testLoggedInUserRedirectsToStaffPanel()
    {
        $user = $this->makeUser('cleanup_user', User::CLASS_MODERATOR);

        $this->getDocleanup($user)->assertRedirect('/staffpanel.php');
    }

    public function testSysopRedirectsToStaffPanel()
    {
        $sysop = $this->makeUser('cleanup_sysop', User::CLASS_SYSOP);

        $this->getDocleanup($sysop)->assertRedirect('/staffpanel.php');
    }

    // --------------------------------------------------------------- command

    public function testCleanupRunCommandRegistered()
    {
        $this->assertArrayHasKey('cleanup:run', Artisan::all());
    }

    public function testCleanupRunCommandDescription()
    {
        $commands = Artisan::all();
        $command = $commands['cleanup:run'] ?? null;
        $this->assertNotNull($command);
        $this->assertTrue($command->getDefinition()->hasOption('forceall'));
    }
}
