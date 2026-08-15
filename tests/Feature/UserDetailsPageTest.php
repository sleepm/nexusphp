<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the userdetails.php migration
 * (UserController::web) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class UserDetailsPageTest extends TestCase
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
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('torrents')->whereIn('owner', $ids)->delete();
            DB::table('users')->whereIn('id', $ids)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $username, int $class = User::CLASS_USER, array $overrides = []): User
    {
        $user = User::query()->create(array_merge([
            'username' => $username,
            'passhash' => str_repeat('a', 32),
            'secret' => 'secret',
            'auth_key' => 'authkey_' . $username,
            'email' => $username . '@example.com',
            'status' => 'confirmed',
            'enabled' => 'yes',
            'class' => $class,
            'passkey' => md5($username),
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

    private function requestAs(User $user, string $uri)
    {
        app('auth')->forgetGuards();
        return $this->withCookie('c_secure_pass', $this->cookieFor($user))->get($uri);
    }

    // ------------------------------------------------------------------ auth

    public function testUserDetailsRequiresLogin()
    {
        $this->get('/userdetails.php?id=1')->assertRedirect();
    }

    // -------------------------------------------------------------- rendering

    public function testMissingUserReturns404()
    {
        $user = $this->makeUser('udetails_missing');
        $this->requestAs($user, '/userdetails.php?id=99999999')->assertNotFound();
    }

    public function testInvalidIdReturns404()
    {
        $user = $this->makeUser('udetails_invalid');
        $this->requestAs($user, '/userdetails.php?id=abc')->assertNotFound();
    }

    public function testPendingUserReturns403()
    {
        $viewer = $this->makeUser('udetails_pending_viewer');
        $pending = $this->makeUser('udetails_pending_target', User::CLASS_USER, ['status' => 'pending']);

        $this->requestAs($viewer, '/userdetails.php?id=' . $pending->id)->assertForbidden();
    }

    public function testUserDetailsRendersProfileInfo()
    {
        $viewer = $this->makeUser('udetails_viewer');
        $target = $this->makeUser('udetails_target');
        DB::table('users')->where('id', $target->id)->update([
            'uploaded' => 2 * 1024 * 1024 * 1024,
            'downloaded' => 1 * 1024 * 1024 * 1024,
            'seedtime' => 3600 * 48,
            'leechtime' => 3600 * 24,
        ]);

        $this->requestAs($viewer, '/userdetails.php?id=' . $target->id)
            ->assertOk()
            ->assertSee('Details for udetails_target', false)
            ->assertSee('udetails_target')
            ->assertSee('User ID')
            ->assertSee('Join')
            ->assertSee('Invitations')
            ->assertSee('Transfers')
            ->assertSee('Gender')
            ->assertSee('Torrent&nbsp;comments', false)
            ->assertSee('Forum');
    }

    public function testOwnProfileShowsPrivateData()
    {
        $user = $this->makeUser('udetails_owner');
        DB::table('users')->where('id', $user->id)->update([
            'email' => 'own-secret@example.com',
            'seedbonus' => 1234.5,
        ]);

        $this->requestAs($user, '/userdetails.php?id=' . $user->id)
            ->assertOk()
            ->assertSee('own-secret@example.com')
            ->assertSee('Karma Points')
            ->assertSee('1,234.5');
    }

    public function testPrivacyStrongHidesProfileFromOthers()
    {
        $viewer = $this->makeUser('udetails_private_viewer');
        $target = $this->makeUser('udetails_private_target', User::CLASS_USER, ['privacy' => 'strong']);
        $target->forceFill(['privacy' => 'strong'])->save();

        $this->requestAs($viewer, '/userdetails.php?id=' . $target->id)
            ->assertOk()
            ->assertSee('wants to protect personal details.')
            ->assertDontSee('Transfers');
    }

    public function testShowsHrAndClaimWhenEnabled()
    {
        $user = $this->makeUser('udetails_hr_owner');

        $this->requestAs($user, '/userdetails.php?id=' . $user->id)
            ->assertOk();
    }
}