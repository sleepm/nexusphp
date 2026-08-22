<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the warned.php migration
 * (WarnedController::web) against a real database.
 */
class WarnedPageTest extends TestCase
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

    private function makeUser(string $name, int $class, array $overrides = []): User
    {
        $user = User::query()->forceCreate(array_merge([
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
            'uploaded' => 1024 * 1024 * 1024,
            'downloaded' => 10 * 1024 * 1024 * 1024,
            'warned' => 'no',
            'showfb' => 'yes',
            'hidehb' => 'no',
            'added' => now(),
            'last_access' => now(),
            'commentpm' => 'no',
        ], $overrides));
        $this->createdUserIds[] = $user->id;

        return $user;
    }

    private function cookieFor(User $user): string
    {
        $tokenJson = json_encode(['user_id' => $user->id, 'expires' => time() + 3600]);
        $signature = hash_hmac('sha256', $tokenJson, $user->auth_key);
        return base64_encode($tokenJson . '.' . $signature);
    }

    private function getWarned(User $user)
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/warned.php');
    }

    // ------------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->get('/warned.php')->assertRedirect();
    }

    public function testDeniedBelowModerator()
    {
        $user = $this->makeUser('warned_user', User::CLASS_USER);

        $this->getWarned($user)->assertForbidden();
    }

    // ------------------------------------------------------------------ view

    public function testModeratorSeesWarnedUsers()
    {
        $mod = $this->makeUser('warned_mod', User::CLASS_MODERATOR);
        $warned = $this->makeUser('warned_someone', User::CLASS_USER, [
            'warned' => 'yes',
            'warneduntil' => now()->addWeek(),
        ]);
        $clean = $this->makeUser('warned_clean', User::CLASS_USER, [
            'warned' => 'no',
        ]);
        $disabled = $this->makeUser('warned_disabled', User::CLASS_USER, [
            'warned' => 'yes',
            'enabled' => 'no',
        ]);

        $this->getWarned($mod)
            ->assertOk()
            ->assertSee('Warned Users', false)
            ->assertSee('warned_someone', false)
            ->assertSee('usernw[]', false)
            ->assertSee('desact[]', false)
            ->assertDontSee('warned_clean', false)
            // disabled warned users are excluded from the list
            ->assertDontSee('warned_disabled', false);
    }

    public function testCountIncludesDisabledWarnedUsers()
    {
        $mod = $this->makeUser('warned_count_mod', User::CLASS_MODERATOR);
        $this->makeUser('warned_count_a', User::CLASS_USER, ['warned' => 'yes']);
        $this->makeUser('warned_count_b', User::CLASS_USER, ['warned' => 'yes', 'enabled' => 'no']);

        // header count mirrors get_row_count("users", "WHERE warned='yes'")
        $this->getWarned($mod)
            ->assertOk()
            ->assertSee('Warned Users: (2)', false);
    }

    public function testApplyChangesButtonOnlyForAdministrators()
    {
        $mod = $this->makeUser('warned_btn_mod', User::CLASS_MODERATOR);
        $this->makeUser('warned_btn_target', User::CLASS_USER, ['warned' => 'yes']);

        $this->getWarned($mod)
            ->assertOk()
            ->assertDontSee('Apply Changes', false);

        $admin = $this->makeUser('warned_btn_admin', User::CLASS_ADMINISTRATOR);
        $this->getWarned($admin)
            ->assertOk()
            ->assertSee('Apply Changes', false)
            ->assertSee('name="nowarned" value="nowarned"', false);
    }
}
