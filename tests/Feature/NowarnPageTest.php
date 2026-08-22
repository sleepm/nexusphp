<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the nowarn.php migration
 * (NowarnController::web) against a real database.
 */
class NowarnPageTest extends TestCase
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

    private function postNowarn(User $user, array $data = [])
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->post('/nowarn.php', array_merge(['nowarned' => 'nowarned'], $data));
    }

    // ------------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->post('/nowarn.php', ['nowarned' => 'nowarned'])->assertRedirect();
    }

    public function testDeniedBelowModerator()
    {
        $user = $this->makeUser('nowarn_user', User::CLASS_USER);

        $this->postNowarn($user)->assertForbidden();
    }

    // ------------------------------------------------------------------ actions

    public function testModeratorRemovesWarnings()
    {
        $mod = $this->makeUser('nowarn_mod', User::CLASS_MODERATOR);
        $target = $this->makeUser('nowarn_target', User::CLASS_USER, [
            'warned' => 'yes',
            'warneduntil' => now()->addWeek(),
        ]);

        $this->postNowarn($mod, ['usernw' => [$target->id]])
            ->assertRedirect('warned.php');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'warned' => 'no',
            'warneduntil' => null,
        ]);
    }

    public function testDisablesAccounts()
    {
        $mod = $this->makeUser('nowarn_disable_mod', User::CLASS_MODERATOR);
        $target = $this->makeUser('nowarn_disable_target', User::CLASS_USER);

        $this->postNowarn($mod, ['desact' => [$target->id]])
            ->assertRedirect('warned.php');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'enabled' => 'no',
        ]);
    }

    public function testRemoveWarningAndDisableTogether()
    {
        $mod = $this->makeUser('nowarn_combo_mod', User::CLASS_MODERATOR);
        $warned = $this->makeUser('nowarn_combo_warned', User::CLASS_USER, ['warned' => 'yes']);
        $disabled = $this->makeUser('nowarn_combo_disabled', User::CLASS_USER);

        $this->postNowarn($mod, [
            'usernw' => [$warned->id],
            'desact' => [$disabled->id],
        ])->assertRedirect('warned.php');

        $this->assertDatabaseHas('users', ['id' => $warned->id, 'warned' => 'no']);
        $this->assertDatabaseHas('users', ['id' => $disabled->id, 'enabled' => 'no']);
    }

    public function testNoSelectionShowsError()
    {
        $mod = $this->makeUser('nowarn_empty_mod', User::CLASS_MODERATOR);

        $this->postNowarn($mod)
            ->assertOk()
            ->assertSee('Update Has Failed !', false)
            ->assertSee('You Must Select A User To Edit.');
    }

    public function testMissingNowarnedFlagRedirectsBack()
    {
        $mod = $this->makeUser('nowarn_flag_mod', User::CLASS_MODERATOR);
        $target = $this->makeUser('nowarn_flag_target', User::CLASS_USER, ['warned' => 'yes']);

        app('auth')->forgetGuards();
        $this->withCookie('c_secure_pass', $this->cookieFor($mod))
            ->withCookie('c_lang_folder', 'en')
            ->post('/nowarn.php', ['usernw' => [$target->id]])
            ->assertRedirect('warned.php');

        $this->assertDatabaseHas('users', ['id' => $target->id, 'warned' => 'yes']);
    }
}
