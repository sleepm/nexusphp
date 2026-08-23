<?php

namespace Tests\Feature;

use App\Models\Torrent;
use App\Models\User;
use App\Repositories\TorrentRepository;
use Illuminate\Support\Facades\DB;
use Rhilip\Bencode\Bencode;
use Tests\TestCase;

/**
 * End-to-end coverage of the download.php migration
 * (DownloadController::web) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class DownloadPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdTorrentIds = [];

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        $_SERVER['REQUEST_URI'] = '/download.php';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $tempFile) {
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        }
        if ($this->createdTorrentIds !== []) {
            $saveDir = getFullDirectory(get_setting('main.torrent_dir'));
            foreach ($this->createdTorrentIds as $id) {
                @unlink("$saveDir/$id.torrent");
            }
            DB::table('torrents')->whereIn('id', $this->createdTorrentIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('torrents')->whereIn('owner', $ids)->delete();
            DB::table('users')->whereIn('id', $ids)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $username = 'dl_tester', int $class = User::CLASS_USER, array $overrides = []): User
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

        // non-fillable columns: set them directly
        DB::table('users')->where('id', $user->id)->update([
            'ip' => $overrides['ip'] ?? '127.0.0.1',
            'parked' => $overrides['parked'] ?? 'no',
            'showdlnotice' => $overrides['showdlnotice'] ?? 0,
            'showclienterror' => $overrides['showclienterror'] ?? 'no',
            'leechwarn' => $overrides['leechwarn'] ?? 'no',
            'downloadpos' => $overrides['downloadpos'] ?? 'yes',
        ]);

        return $user;
    }

    private function cookieFor(User $user): string
    {
        $tokenJson = json_encode(['user_id' => $user->id, 'expires' => time() + 3600]);
        $signature = hash_hmac('sha256', $tokenJson, $user->auth_key);
        return base64_encode($tokenJson . '.' . $signature);
    }

    private function requestAs(User $user, string $method, string $uri, array $data = [])
    {
        app('auth')->forgetGuards();
        $request = $this->withCookie('c_secure_pass', $this->cookieFor($user));
        if ($method === 'get') {
            return $request->get($uri);
        }
        return $request->post($uri, $data);
    }

    private function makeTorrent(User $owner, array $overrides = []): Torrent
    {
        $torrent = Torrent::query()->create(array_merge([
            'name' => 'Download E2E Torrent',
            'filename' => 'download-e2e.torrent',
            'save_as' => 'download-e2e',
            'category' => 401,
            'owner' => $owner->id,
            'size' => 1024 * 1024 * 1024,
            'added' => now()->subDays(30),
            'views' => 0,
            'hits' => 0,
            'approval_status' => Torrent::APPROVAL_STATUS_ALLOW,
        ], $overrides));
        $this->createdTorrentIds[] = $torrent->id;

        $this->createTorrentFile($torrent->id);

        return $torrent;
    }

    private function createTorrentFile(int $torrentId): void
    {
        $content = Bencode::encode([
            'announce' => 'http://tracker.example.com/announce',
            'info' => [
                'name' => 'e2e-download',
                'piece length' => 262144,
                'pieces' => str_repeat('a', 60),
                'length' => 786432,
            ],
        ]);
        $saveDir = getFullDirectory(get_setting('main.torrent_dir'));
        $path = "$saveDir/$torrentId.torrent";
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
    }

    // ------------------------------------------------------------------ auth

    public function testDownloadRequiresLogin()
    {
        $this->get('/download.php?id=1')->assertRedirect();
    }

    public function testDownloadWithInvalidIdNotFound()
    {
        $user = $this->makeUser('dl_noid_user');

        $this->requestAs($user, 'get', '/download.php?id=0&letdown=1')->assertStatus(404);
        $this->requestAs($user, 'get', '/download.php?id=9999999&letdown=1')->assertStatus(404);
    }

    // ------------------------------------------------------------- notice redirects

    public function testDownloadFirstTimeNoticeRedirects()
    {
        $owner = $this->makeUser('dl_firsttime_owner');
        $user = $this->makeUser('dl_firsttime_user', User::CLASS_USER, ['showdlnotice' => 1]);
        $torrent = $this->makeTorrent($owner);

        $this->requestAs($user, 'get', '/download.php?id=' . $torrent->id)
            ->assertRedirect('/downloadnotice.php?torrentid=' . $torrent->id . '&type=firsttime');
    }

    public function testDownloadClientNoticeRedirects()
    {
        $owner = $this->makeUser('dl_client_owner');
        $user = $this->makeUser('dl_client_user', User::CLASS_USER, ['showclienterror' => 'yes']);
        $torrent = $this->makeTorrent($owner);

        $this->requestAs($user, 'get', '/download.php?id=' . $torrent->id)
            ->assertRedirect('/downloadnotice.php?torrentid=' . $torrent->id . '&type=client');
    }

    public function testDownloadRatioNoticeRedirects()
    {
        $owner = $this->makeUser('dl_ratio_owner');
        $user = $this->makeUser('dl_ratio_user', User::CLASS_USER, ['leechwarn' => 'yes']);
        $torrent = $this->makeTorrent($owner);

        $this->requestAs($user, 'get', '/download.php?id=' . $torrent->id)
            ->assertRedirect('/downloadnotice.php?torrentid=' . $torrent->id . '&type=ratio');
    }

    public function testDownloadWithLetdownSkipsNotices()
    {
        $owner = $this->makeUser('dl_letdown_owner');
        $user = $this->makeUser('dl_letdown_user', User::CLASS_USER, ['showdlnotice' => 1]);
        $torrent = $this->makeTorrent($owner);

        $this->requestAs($user, 'get', '/download.php?id=' . $torrent->id . '&letdown=1')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/x-bittorrent');
    }

    // ------------------------------------------------------------- downloadpos denial

    public function testDownloadWithDownloadposNoDenies()
    {
        $owner = $this->makeUser('dl_dpos_owner');
        $user = $this->makeUser('dl_dpos_user', User::CLASS_USER, ['downloadpos' => 'no']);
        $torrent = $this->makeTorrent($owner);

        $this->requestAs($user, 'get', '/download.php?id=' . $torrent->id . '&letdown=1')
            ->assertStatus(403);
    }

    // ------------------------------------------------------------- banned torrent

    public function testDownloadBannedTorrentDeniedForRegularUser()
    {
        $owner = $this->makeUser('dl_banned_owner');
        $user = $this->makeUser('dl_banned_user');
        $torrent = $this->makeTorrent($owner, ['banned' => 'yes']);

        $this->requestAs($user, 'get', '/download.php?id=' . $torrent->id . '&letdown=1')
            ->assertStatus(403);
    }

    // ------------------------------------------------------------- successful download

    public function testDownloadTorrentFileSuccessfully()
    {
        $owner = $this->makeUser('dl_ok_owner');
        $user = $this->makeUser('dl_ok_user');
        $torrent = $this->makeTorrent($owner);

        $response = $this->requestAs($user, 'get', '/download.php?id=' . $torrent->id . '&letdown=1');
        $response->assertOk()
            ->assertHeader('Content-Type', 'application/x-bittorrent');

        $contentDisposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('.torrent', $contentDisposition);

        // verify the torrent content includes the announce URL with the user's passkey
        $content = $response->getContent();
        $this->assertStringContainsString($user->passkey, $content);
        $this->assertStringContainsString('details.php?id=' . $torrent->id, $content);

        // verify hits were incremented
        $this->assertEquals(1, DB::table('torrents')->where('id', $torrent->id)->value('hits'));
    }

    // ------------------------------------------------------------- downhash (anonymous)

    public function testDownloadByDownhash()
    {
        $owner = $this->makeUser('dl_downhash_owner');
        $torrent = $this->makeTorrent($owner);
        $torrentRep = new TorrentRepository();
        $encrypted = $torrentRep->encryptDownHash($torrent->id, $owner);
        $downhash = $owner->id . '.' . $encrypted;

        $response = $this->get('/download.php?downhash=' . $downhash);
        $response->assertOk()
            ->assertHeader('Content-Type', 'application/x-bittorrent');

        $content = $response->getContent();
        $this->assertStringContainsString($owner->passkey, $content);
    }

    public function testDownloadByDownhashInvalidUid()
    {
        $this->get('/download.php?downhash=99999.invalid')->assertOk()
            ->assertSee('invalid uid');
    }

    public function testDownloadByDownhashInvalidHash()
    {
        $owner = $this->makeUser('dl_downhash_bad');
        $this->get('/download.php?downhash=' . $owner->id . '.badsignature')
            ->assertOk()
            ->assertSee('invalid downhash');
    }

    // ------------------------------------------------------------- passkey (anonymous)

    public function testDownloadByPasskey()
    {
        if (get_setting('torrent.download_support_passkey') != 'yes') {
            $this->markTestSkipped('torrent.download_support_passkey is not enabled');
        }

        $owner = $this->makeUser('dl_passkey_owner');
        $torrent = $this->makeTorrent($owner);

        $response = $this->get('/download.php?passkey=' . $owner->passkey . '&id=' . $torrent->id);
        $response->assertOk()
            ->assertHeader('Content-Type', 'application/x-bittorrent');

        $content = $response->getContent();
        $this->assertStringContainsString($owner->passkey, $content);
    }

    public function testDownloadByPasskeyInvalidKey()
    {
        $this->get('/download.php?passkey=invalidpasskey123456789012345&id=1')
            ->assertOk()
            ->assertSee('invalid passkey');
    }

    // ------------------------------------------------------------- parked account

    public function testDownloadByDownhashParkedAccount()
    {
        $owner = $this->makeUser('dl_parked', User::CLASS_USER, ['parked' => 'yes']);
        $torrent = $this->makeTorrent($owner);
        $torrentRep = new TorrentRepository();
        $encrypted = $torrentRep->encryptDownHash($torrent->id, $owner);
        $downhash = $owner->id . '.' . $encrypted;

        $this->get('/download.php?downhash=' . $downhash)
            ->assertOk()
            ->assertSee('account disabed or parked');
    }
}