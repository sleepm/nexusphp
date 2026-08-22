<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/deletedisabled.php migration to the
 * Filament UserResource.
 *
 * The legacy procedural "delete all disabled accounts" page is replaced by the
 * admin panel's UserResource (disable/enable/delete actions). The legacy entry
 * point now redirects to the user list.
 */
class DeleteDisabledPageTest extends TestCase
{
    private array $createdUserIds = [];

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

    private function asUser(User $user)
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en');
    }

    // ------------------------------------------------------------------ legacy entry

    public function testLegacyDeleteDisabledRedirectsToFilament(): void
    {
        $this->get('/deletedisabled.php')
            ->assertRedirect(route('filament.admin.resources.user.users.index'));
    }

    // ---------------------------------------------------------- auth on panel

    public function testUsersResourceRequiresLogin(): void
    {
        $this->get('/nexusphp/user/users')
            ->assertStatus(302);
    }

    // ------------------------------------------------------------ panel pages

    public function testAdministratorCanAccessUsersResource(): void
    {
        $admin = $this->makeUser('deletedisabled_admin', User::CLASS_ADMINISTRATOR);

        $this->asUser($admin)
            ->get('/nexusphp/user/users')
            ->assertOk();
    }

    public function testDisabledUsersShownInList(): void
    {
        $admin = $this->makeUser('deletedisabled_list', User::CLASS_ADMINISTRATOR);
        $disabled = $this->makeUser('deletedisabled_target', User::CLASS_PEASANT);
        $disabled->enabled = 'no';
        $disabled->save();

        $this->asUser($admin)
            ->get('/nexusphp/user/users')
            ->assertOk()
            ->assertSee($disabled->username, false);
    }
}
