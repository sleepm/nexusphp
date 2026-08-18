<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the freeleech.php migration
 * (FreeleechController::web) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() restores the original torrents_state contents and removes the
 * users this test created.
 */
class FreeleechPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $stateSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';

        $this->stateSnapshot = DB::table('torrents_state')->get()->toArray();
    }

    protected function tearDown(): void
    {
        DB::table('torrents_state')->delete();
        foreach ($this->stateSnapshot as $row) {
            DB::table('torrents_state')->insert((array) $row);
        }
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $username, $class = User::CLASS_ADMINISTRATOR, array $overrides = []): User
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

    private function getFreeleech(User $user, string $query = '')
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/freeleech.php' . $query);
    }

    private function makeStateRow(int $globalSpState): void
    {
        DB::table('torrents_state')->insert(['global_sp_state' => $globalSpState]);
    }

    private function resetStates(array $states): void
    {
        DB::table('torrents_state')->delete();
        foreach ($states as $state) {
            $this->makeStateRow($state);
        }
    }

    private function globalSpStates(): array
    {
        return DB::table('torrents_state')->pluck('global_sp_state')->map(fn ($v) => (int) $v)->all();
    }

    // ------------------------------------------------------------------ auth

    public function testFreeleechRequiresLogin()
    {
        $this->get('/freeleech.php')->assertRedirect();
    }

    public function testFreeleechRejectsNonAdmin()
    {
        $mod = $this->makeUser('freeleech_mod', User::CLASS_MODERATOR);

        $this->getFreeleech($mod)->assertForbidden();
    }

    // -------------------------------------------------------------- rendering

    public function testFreeleechRendersMenu()
    {
        $admin = $this->makeUser('freeleech_menu_admin');

        $this->getFreeleech($admin)
            ->assertOk()
            ->assertSee('set all torrents free')
            ->assertSee('set all torrents 2x up')
            ->assertSee('set all torrents 2x up and free')
            ->assertSee('set all torrents half down')
            ->assertSee('set all torrents 2x up and half down')
            ->assertSee('set all torrents normal')
            ->assertSee('freeleech.php?action=setallfree', false);
    }

    // ------------------------------------------------------------ actions

    public function testFreeleechSetAllFree()
    {
        $admin = $this->makeUser('freeleech_set_free_admin');
        $this->resetStates([1, 3]);

        $this->getFreeleech($admin, '?action=setallfree')
            ->assertOk()
            ->assertSee('All torrents have been set free..');

        $this->assertSame([2, 2], $this->globalSpStates());
    }

    public function testFreeleechSetAll2up()
    {
        $admin = $this->makeUser('freeleech_set_2up_admin');
        $this->resetStates([2]);

        $this->getFreeleech($admin, '?action=setall2up')
            ->assertOk()
            ->assertSee('All torrents have been set 2x up..');

        $this->assertSame([3], $this->globalSpStates());
    }

    public function testFreeleechSetAll2upFree()
    {
        $admin = $this->makeUser('freeleech_set_2up_free_admin');
        $this->resetStates([2]);

        $this->getFreeleech($admin, '?action=setall2up_free')
            ->assertOk()
            ->assertSee('All torrents have been set 2x up and free..');

        $this->assertSame([4], $this->globalSpStates());
    }

    public function testFreeleechSetAllHalfDown()
    {
        $admin = $this->makeUser('freeleech_set_half_admin');
        $this->resetStates([2]);

        $this->getFreeleech($admin, '?action=setallhalf_down')
            ->assertOk()
            ->assertSee('All torrents have been set half down..');

        $this->assertSame([5], $this->globalSpStates());
    }

    public function testFreeleechSetAll2upHalfDown()
    {
        $admin = $this->makeUser('freeleech_set_2up_half_admin');
        $this->resetStates([2]);

        $this->getFreeleech($admin, '?action=setall2up_half_down')
            ->assertOk()
            ->assertSee('All torrents have been set 2x up and half down..');

        $this->assertSame([6], $this->globalSpStates());
    }

    public function testFreeleechSetAllNormal()
    {
        $admin = $this->makeUser('freeleech_set_normal_admin');
        $this->resetStates([2, 4]);

        $this->getFreeleech($admin, '?action=setallnormal')
            ->assertOk()
            ->assertSee('All torrents have been set normal..');

        $this->assertSame([1, 1], $this->globalSpStates());
    }
}
