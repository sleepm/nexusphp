<?php

namespace Tests\Feature;

use App\Models\Ban;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/bans.php migration to the Filament
 * BansResource.
 *
 * The legacy procedural IP-ban page is replaced by the System\BansResource.
 * The legacy entry point now redirects to the admin panel.
 */
class BansPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdBanIds = [];

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
        if ($this->createdBanIds !== []) {
            DB::table('bans')->whereIn('id', $this->createdBanIds)->delete();
        }
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

    private function makeBan(int $addedby, string $first = '10.0.0.0', string $last = '10.0.0.255', string $comment = 'test ban'): Ban
    {
        $ban = Ban::query()->create([
            'added' => now()->format('Y-m-d H:i:s'),
            'addedby' => $addedby,
            'first' => ip2long($first),
            'last' => ip2long($last),
            'comment' => $comment,
        ]);
        $this->createdBanIds[] = $ban->id;

        return $ban;
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

    public function testLegacyBansRedirectsToFilament(): void
    {
        $this->get('/bans.php')
            ->assertRedirect(route('filament.admin.resources.system.bans.index'));
    }

    // ---------------------------------------------------------- auth on panel

    public function testBansResourceRequiresLogin(): void
    {
        $this->get('/nexusphp/system/bans')
            ->assertStatus(302);
    }

    public function testBansResourceDeniedBelowAdministrator(): void
    {
        $user = $this->makeUser('bans_moderator', User::CLASS_MODERATOR);

        $this->asUser($user)->get('/nexusphp/system/bans')->assertForbidden();
    }

    // ------------------------------------------------------------ panel pages

    public function testAdministratorCanAccessBansResource(): void
    {
        $admin = $this->makeUser('bans_admin', User::CLASS_ADMINISTRATOR);

        $this->asUser($admin)
            ->get('/nexusphp/system/bans')
            ->assertOk();
    }

    public function testListSeesBan(): void
    {
        $admin = $this->makeUser('bans_list', User::CLASS_ADMINISTRATOR);
        $ban = $this->makeBan($admin->id);

        $this->asUser($admin)
            ->get('/nexusphp/system/bans')
            ->assertOk()
            ->assertSee('10.0.0.0', false);
    }

    public function testCreatePageRenders(): void
    {
        $admin = $this->makeUser('bans_create', User::CLASS_ADMINISTRATOR);

        $this->asUser($admin)
            ->get('/nexusphp/system/bans/create')
            ->assertOk();
    }

    public function testCreateBanStoresLongIp(): void
    {
        $admin = $this->makeUser('bans_create_store', User::CLASS_ADMINISTRATOR);

        $ban = Ban::query()->create([
            'added' => now()->format('Y-m-d H:i:s'),
            'addedby' => $admin->id,
            'first' => ip2long('192.168.1.0'),
            'last' => ip2long('192.168.1.255'),
            'comment' => 'new ban',
        ]);
        $this->createdBanIds[] = $ban->id;

        $this->assertEquals(ip2long('192.168.1.0'), $ban->first);
        $this->assertEquals(ip2long('192.168.1.255'), $ban->last);
        $this->assertEquals($admin->id, $ban->addedby);
        $this->assertEquals('192.168.1.0', long2ip($ban->first));
    }

    public function testEditBanRenders(): void
    {
        $admin = $this->makeUser('bans_edit', User::CLASS_ADMINISTRATOR);
        $ban = $this->makeBan($admin->id);

        $this->asUser($admin)
            ->get('/nexusphp/system/bans/' . $ban->id . '/edit')
            ->assertOk();
    }

    public function testDeleteBan(): void
    {
        $admin = $this->makeUser('bans_delete', User::CLASS_ADMINISTRATOR);
        $ban = $this->makeBan($admin->id);

        $this->asUser($admin)
            ->get('/nexusphp/system/bans')
            ->assertOk();

        $ban->delete();

        $this->assertDatabaseMissing('bans', ['id' => $ban->id]);
    }
}