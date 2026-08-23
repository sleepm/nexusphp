<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the legacy public/increment-bulk.php and
 * public/take-increment-bulk.php migration to the UserResource bulk action.
 *
 * Legacy: SYSOP-only batch increment/decrement of uploaded/downloaded/bonus/
 * invites/attendance_card/tmp_invites by class filter.
 * Modern: Filament UserResource bulk action "change_bonus_etc" on selected records.
 */
class IncrementBulkPageTest extends TestCase
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
            'invites' => 5,
            'attendance_card' => 0,
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

    // ------------------------------------------------------------------ legacy redirects

    public function testLegacyIncrementBulkRedirectsToFilament(): void
    {
        $this->get('/increment-bulk.php')
            ->assertRedirect(route('filament.admin.resources.user.users.index'));
    }

    public function testLegacyTakeIncrementBulkRedirectsToFilament(): void
    {
        $this->get('/take-increment-bulk.php')
            ->assertRedirect(route('filament.admin.resources.user.users.index'));
    }

    // ---------------------------------------------------------- auth on panel

    public function testIncrementBulkRequiresLogin(): void
    {
        $this->get('/nexusphp/user/users')
            ->assertStatus(302);
    }

    public function testIncrementBulkDeniedBelowAdministrator(): void
    {
        $user = $this->makeUser('ib_moderator', User::CLASS_MODERATOR);

        $this->asUser($user)->get('/nexusphp/user/users')->assertForbidden();
    }

    // ------------------------------------------------------------ panel access

    public function testSysopCanAccessUserResource(): void
    {
        $admin = $this->makeUser('ib_sysop', User::CLASS_SYSOP);

        $this->asUser($admin)
            ->get('/nexusphp/user/users')
            ->assertOk();
    }

    // --------------------------------------------------------- repository method

    public function testIncrementDecrementUpdatesSeedbonus(): void
    {
        $user = $this->makeUser('ib_target', User::CLASS_PEASANT);
        $operator = $this->makeUser('ib_operator', User::CLASS_SYSOP);

        $repo = new \App\Repositories\UserRepository();
        $repo->incrementDecrement($operator, $user->id, 'Increment', 'seedbonus', 50, 'test bulk increment');

        $fresh = User::query()->find($user->id);
        $this->assertEquals(150, $fresh->seedbonus);
    }

    public function testIncrementDecrementHandlesUploadedGb(): void
    {
        $user = $this->makeUser('ib_up', User::CLASS_PEASANT);
        $operator = $this->makeUser('ib_up_op', User::CLASS_SYSOP);

        $repo = new \App\Repositories\UserRepository();
        $repo->incrementDecrement($operator, $user->id, 'Increment', 'uploaded', 1, 'test 1 GB');

        $fresh = User::query()->find($user->id);
        $this->assertEquals(1 * 1024 * 1024 * 1024, $fresh->uploaded);
    }
}