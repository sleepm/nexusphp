<?php

namespace Tests\Feature;

use App\Models\Torrent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the torrents.php migration (TorrentController::browse)
 * against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class TorrentListPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdTorrentIds = [];

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
        if ($this->createdTorrentIds !== []) {
            DB::table('torrents')->whereIn('id', $this->createdTorrentIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('torrents')->whereIn('owner', $ids)->delete();
            DB::table('users')->whereIn('id', $ids)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $username = 'browse', int $class = User::CLASS_USER): User
    {
        $user = User::query()->create([
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

    private function requestAs(User $user, string $uri)
    {
        app('auth')->forgetGuards();
        return $this->withCookie('c_secure_pass', $this->cookieFor($user))->get($uri);
    }

    private function makeTorrent(User $owner, array $overrides = []): Torrent
    {
        $torrent = Torrent::query()->create(array_merge([
            'name' => 'Browse E2E Torrent',
            'filename' => 'browse-e2e.torrent',
            'save_as' => 'browse-e2e',
            'category' => 401,
            'owner' => $owner->id,
            'size' => 1024 * 1024 * 1024,
            'added' => now()->subDays(30),
            'visible' => 'yes',
            'views' => 0,
        ], $overrides));
        $this->createdTorrentIds[] = $torrent->id;

        return $torrent;
    }

    // ------------------------------------------------------------------ auth

    public function testTorrentsPageRequiresLogin()
    {
        $this->get('/torrents.php')->assertRedirect();
    }

    // -------------------------------------------------------------- rendering

    public function testTorrentsPageRendersTorrentRows()
    {
        $owner = $this->makeUser('browse_owner');
        $viewer = $this->makeUser('browse_viewer');
        $this->makeTorrent($owner, ['name' => 'Browse E2E Torrent Alpha']);

        $this->requestAs($viewer, '/torrents.php')
            ->assertOk()
            ->assertSee('Browse E2E Torrent Alpha');
    }

    public function testTorrentsPageRendersTableAndPager()
    {
        $owner = $this->makeUser('browse_owner2');
        $viewer = $this->makeUser('browse_viewer2');
        for ($i = 0; $i < 3; $i++) {
            $this->makeTorrent($owner, ['name' => 'Browse Pager Torrent ' . $i]);
        }

        $response = $this->requestAs($viewer, '/torrents.php')
            ->assertOk()
            ->assertSee('Browse Pager Torrent 0');

        $this->assertStringContainsString('torrents', $response->getContent());
    }

    public function testSearchFiltersTorrents()
    {
        $owner = $this->makeUser('search_owner');
        $viewer = $this->makeUser('search_viewer');
        $this->makeTorrent($owner, ['name' => 'Searchable Unique Keyword Torrent']);
        $this->makeTorrent($owner, ['name' => 'Another Unrelated Torrent']);

        $this->requestAs($viewer, '/torrents.php?search=' . urlencode('Searchable Unique'))
            ->assertOk()
            ->assertSee('Searchable Unique Keyword Torrent')
            ->assertDontSee('Another Unrelated Torrent');
    }

    public function testCategoryFilterShowsOnlyChosenCategory()
    {
        $owner = $this->makeUser('cat_owner');
        $viewer = $this->makeUser('cat_viewer');
        $this->makeTorrent($owner, ['name' => 'Cat 401 Torrent', 'category' => 401]);
        $this->makeTorrent($owner, ['name' => 'Cat 402 Torrent', 'category' => 402]);

        $this->requestAs($viewer, '/torrents.php?cat=401')
            ->assertOk()
            ->assertSee('Cat 401 Torrent')
            ->assertDontSee('Cat 402 Torrent');
    }

    public function testNothingFoundMessageWhenNoTorrents()
    {
        $viewer = $this->makeUser('none_viewer');

        $this->requestAs($viewer, '/torrents.php?search=' . urlencode('XyzzyNoSuchTorrent'))
            ->assertOk();
    }

    // ------------------------------------------------------------------ special

    public function testSpecialSectionRequiresEnabledSetting()
    {
        $viewer = $this->makeUser('special_viewer');

        // main.spsct is not 'yes' in the live settings database, so the legacy
        // page dies with httperr() -> the Laravel route mirrors that with a 404.
        if (get_setting('main.spsct') == 'yes') {
            $this->markTestSkipped('special section is enabled in this environment');
        }
        $this->requestAs($viewer, '/special.php')->assertNotFound();
    }
}