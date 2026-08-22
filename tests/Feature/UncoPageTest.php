<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the unco.php migration
 * (UncoController::web) against a real database.
 */
class UncoPageTest extends TestCase
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

    private function getUnco(User $user, string $query = '')
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/unco.php' . $query);
    }

    // ------------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->get('/unco.php')->assertRedirect();
    }

    public function testDeniedBelowModerator()
    {
        $user = $this->makeUser('unco_user', User::CLASS_USER);

        $this->getUnco($user)->assertForbidden();
    }

    // ------------------------------------------------------------------ view

    public function testListsPendingUsers()
    {
        $mod = $this->makeUser('unco_mod', User::CLASS_MODERATOR);
        $pending = $this->makeUser('unco_pending', User::CLASS_USER, ['status' => 'pending']);
        $confirmed = $this->makeUser('unco_confirmed', User::CLASS_USER, ['status' => 'confirmed']);

        $this->getUnco($mod)
            ->assertOk()
            ->assertSee('Unconfirmed Users', false)
            ->assertSee('unco_pending', false)
            ->assertSee('unco_pending@example.com', false)
            ->assertSee('action="modtask.php"', false)
            ->assertSee('value="confirmuser"', false)
            ->assertSee('value="pending"', false)
            ->assertSee('value="confirmed"', false)
            ->assertDontSee('unco_confirmed', false);
    }

    public function testNoPendingUsersShowsNothingFound()
    {
        $mod = $this->makeUser('unco_empty_mod', User::CLASS_MODERATOR);

        $this->getUnco($mod)
            ->assertOk()
            ->assertSee('Ups!', false)
            ->assertSee('Nothing Found...');
    }

    public function testStatusShowsUpdatedMessage()
    {
        $mod = $this->makeUser('unco_status_mod', User::CLASS_MODERATOR);
        $this->makeUser('unco_status_pending', User::CLASS_USER, ['status' => 'pending']);

        $this->getUnco($mod, '?status=1')
            ->assertOk()
            ->assertSee('The User account has been updated!', false);
    }

    public function testNoPendingUsersWithStatusShowsUpdated()
    {
        $mod = $this->makeUser('unco_status_empty_mod', User::CLASS_MODERATOR);

        $this->getUnco($mod, '?status=1')
            ->assertOk()
            ->assertSee('Updated!', false)
            ->assertSee('The user account has been updated.');
    }

    public function testInvalidStatusRejected()
    {
        $mod = $this->makeUser('unco_bad_status_mod', User::CLASS_MODERATOR);

        $this->getUnco($mod, '?status=abc')->assertStatus(400);
    }

    public function testConfirmFlowEndToEnd()
    {
        $mod = $this->makeUser('unco_flow_mod', User::CLASS_MODERATOR);
        $pending = $this->makeUser('unco_flow_pending', User::CLASS_USER, ['status' => 'pending', 'info' => 'please confirm']);

        // the per-row form posts to modtask.php which redirects back to unco.php?status=1
        $this->getUnco($mod)->assertSee('userdetails.php?id=' . $pending->id, false);

        app('auth')->forgetGuards();
        $this->withCookie('c_secure_pass', $this->cookieFor($mod))
            ->withCookie('c_lang_folder', 'en')
            ->post('/modtask.php', [
                'action' => 'confirmuser',
                'userid' => $pending->id,
                'confirm' => 'confirmed',
            ])->assertRedirect('unco.php?status=1');

        $this->assertDatabaseHas('users', [
            'id' => $pending->id,
            'status' => 'confirmed',
            'info' => null,
        ]);
    }
}
