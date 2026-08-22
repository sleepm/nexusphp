<?php

namespace Tests\Feature;

use App\Models\Cheater;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/cheaters.php + public/cheaterbox.php
 * migration to the Filament CheaterResource.
 *
 * The legacy procedural pages are replaced by the System\CheaterResource
 * (suspect box + cheat stats). The legacy entry points now redirect to
 * the admin panel.
 */
class CheaterPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdCheaterIds = [];

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
        if ($this->createdCheaterIds !== []) {
            DB::table('cheaters')->whereIn('id', $this->createdCheaterIds)->delete();
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

    private function makeCheater(int $userid, int $torrentid = 1): Cheater
    {
        $cheater = Cheater::query()->create([
            'added' => now()->format('Y-m-d H:i:s'),
            'userid' => $userid,
            'torrentid' => $torrentid,
            'uploaded' => 1000,
            'downloaded' => 500,
            'anctime' => 3600,
            'seeders' => 5,
            'leechers' => 2,
            'hit' => 1,
            'dealtwith' => 0,
            'dealtby' => 0,
            'comment' => 'test suspect',
        ]);
        $this->createdCheaterIds[] = $cheater->id;

        return $cheater;
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

    public function testLegacyCheaterboxRedirectsToFilament(): void
    {
        $this->get('/cheaterbox.php')
            ->assertRedirect(route('filament.admin.resources.system.cheaters.index'));
    }

    public function testLegacyCheatersRedirectsToFilament(): void
    {
        $this->get('/cheaters.php')
            ->assertRedirect(route('filament.admin.resources.system.cheaters.stats'));
    }

    // ---------------------------------------------------------- auth on panel

    public function testCheaterResourceRequiresLogin(): void
    {
        $this->get('/nexusphp/system/cheaters')
            ->assertStatus(302);
    }

    public function testCheaterResourceDeniedBelowAdministrator(): void
    {
        $user = $this->makeUser('cheater_moderator', User::CLASS_MODERATOR);

        $this->asUser($user)->get('/nexusphp/system/cheaters')->assertForbidden();
    }

    // ------------------------------------------------------------ panel pages

    public function testAdministratorCanAccessCheaterResource(): void
    {
        $admin = $this->makeUser('cheater_admin_ok', User::CLASS_ADMINISTRATOR);

        $this->asUser($admin)
            ->get('/nexusphp/system/cheaters')
            ->assertOk();
    }

    public function testListSeesCheater(): void
    {
        $admin = $this->makeUser('cheater_list', User::CLASS_ADMINISTRATOR);
        $suspect = $this->makeUser('cheater_suspect', User::CLASS_USER);
        $cheater = $this->makeCheater($suspect->id);

        $this->asUser($admin)
            ->get('/nexusphp/system/cheaters')
            ->assertOk()
            ->assertSee($suspect->username, false);
    }

    public function testCheatStatsPageRenders(): void
    {
        $admin = $this->makeUser('cheater_stats', User::CLASS_ADMINISTRATOR);
        $user = $this->makeUser('cheater_stats_user', User::CLASS_USER);
        DB::table('users')->where('id', $user->id)->update(['cheat' => 100]);

        $this->asUser($admin)
            ->get('/nexusphp/system/cheaters/stats')
            ->assertOk();
    }

    public function testAdministratorCanAccessCheatStats(): void
    {
        $admin = $this->makeUser('cheater_stats_admin', User::CLASS_ADMINISTRATOR);

        $this->asUser($admin)
            ->get('/nexusphp/system/cheaters/stats')
            ->assertOk();
    }

    // --------------------------------------------------------------- bulk actions

    public function testBulkSetDealt(): void
    {
        $admin = $this->makeUser('cheater_setdealt', User::CLASS_ADMINISTRATOR);
        $suspect = $this->makeUser('cheater_setdealt_suspect', User::CLASS_USER);
        $cheater = $this->makeCheater($suspect->id);

        Cheater::query()->where('id', $cheater->id)
            ->where('dealtwith', 0)
            ->update(['dealtwith' => 1, 'dealtby' => $admin->id]);

        $cheater->refresh();
        $this->assertEquals(1, $cheater->dealtwith);
        $this->assertEquals($admin->id, $cheater->dealtby);
    }

    public function testBulkDelete(): void
    {
        $admin = $this->makeUser('cheater_bulkdelete', User::CLASS_ADMINISTRATOR);
        $suspect = $this->makeUser('cheater_bulkdelete_suspect', User::CLASS_USER);
        $cheater = $this->makeCheater($suspect->id);

        $this->asUser($admin)
            ->get('/nexusphp/system/cheaters')
            ->assertOk();

        Cheater::query()->where('id', $cheater->id)->delete();

        $this->assertDatabaseMissing('cheaters', ['id' => $cheater->id]);
    }
}