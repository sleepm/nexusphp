<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/adduser.php migration to the Filament
 * UserResource create page.
 *
 * The legacy procedural "Add user" form is replaced by the admin panel's
 * UserResource create page. The legacy entry point now redirects there.
 */
class AddUserPageTest extends TestCase
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

    public function testLegacyAddUserRedirectsToFilament(): void
    {
        $this->get('/adduser.php')
            ->assertRedirect(route('filament.admin.resources.user.users.create'));
    }

    // ---------------------------------------------------------- auth on panel

    public function testCreateUserRequiresLogin(): void
    {
        $this->get('/nexusphp/user/users/create')
            ->assertStatus(302);
    }

    public function testCreateUserDeniedBelowAdministrator(): void
    {
        $user = $this->makeUser('adduser_moderator', User::CLASS_MODERATOR);

        $this->asUser($user)->get('/nexusphp/user/users/create')->assertForbidden();
    }

    // ------------------------------------------------------------ panel pages

    public function testAdministratorCanAccessCreateUserPage(): void
    {
        $admin = $this->makeUser('adduser_admin', User::CLASS_ADMINISTRATOR);

        $this->asUser($admin)
            ->get('/nexusphp/user/users/create')
            ->assertOk();
    }

    public function testAdministratorCanCreateUser(): void
    {
        $admin = $this->makeUser('adduser_create', User::CLASS_ADMINISTRATOR);
        $userRep = new \App\Repositories\UserRepository();

        $newUser = $userRep->store([
            'username' => 'freshcreated',
            'email' => 'freshcreated@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);
        $this->createdUserIds[] = $newUser->id;

        $this->assertDatabaseHas('users', ['username' => 'freshcreated']);
        $this->assertEquals(User::STATUS_CONFIRMED, $newUser->status);
    }
}
