<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Peer;
use App\Models\Snatch;
use App\Models\Torrent;
use App\Models\TorrentExtra;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the Phase 2 P3 migration
 * (viewsnatches.php / viewpeerlist.php / viewfilelist.php / viewnfo.php)
 * against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class ViewPagesTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdTorrentIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
    }

    protected function tearDown(): void
    {
        if ($this->createdTorrentIds !== []) {
            $tids = array_values(array_unique($this->createdTorrentIds));
            DB::table('snatched')->whereIn('torrentid', $tids)->delete();
            DB::table('peers')->whereIn('torrent', $tids)->delete();
            DB::table('files')->whereIn('torrent', $tids)->delete();
            DB::table('torrent_extras')->whereIn('torrent_id', $tids)->delete();
            DB::table('torrents')->whereIn('id', $tids)->delete();
        }
        if ($this->createdUserIds !== []) {
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('users')->whereIn('id', $ids)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $username = 'tester', int $class = User::CLASS_USER): User
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
            'added' => now(),
            'last_access' => now(),
            'commentpm' => 'no',
        ]);
        $this->createdUserIds[] = $user->id;

        return $user;
    }

    private function makeTorrent(User $owner): Torrent
    {
        $torrent = Torrent::query()->create([
            'name' => 'P3 View Torrent',
            'filename' => 'p3.torrent',
            'save_as' => 'p3',
            'category' => 1,
            'owner' => $owner->id,
            'size' => 1000 * 1048576,
            'anonymous' => 'no',
            'added' => now(),
        ]);
        $this->createdTorrentIds[] = $torrent->id;

        return $torrent;
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

    // ------------------------------------------------------------------ viewsnatches

    public function testViewsnatchesRequiresLogin()
    {
        $this->get('/viewsnatches.php?id=1')->assertRedirect();
    }

    public function testViewsnatchesInvalidId()
    {
        $user = $this->makeUser('vsn_badid');
        $this->requestAs($user, 'get', '/viewsnatches.php?id=0')->assertNotFound();
    }

    public function testViewsnatchesListsCompletedSnatches()
    {
        $owner = $this->makeUser('vsn_owner');
        $torrent = $this->makeTorrent($owner);
        $snatcher = $this->makeUser('vsn_snatcher');

        $snatch = Snatch::query()->create([
            'torrentid' => $torrent->id,
            'userid' => $snatcher->id,
            'ip' => '127.0.0.1',
            'uploaded' => 1024 * 1024 * 50,
            'downloaded' => 1024 * 1024 * 25,
            'seedtime' => 3600,
            'leechtime' => 1800,
            'finished' => 'yes',
            'completedat' => now(),
            'last_action' => now(),
        ]);

        $this->requestAs($owner, 'get', '/viewsnatches.php?id=' . $torrent->id)
            ->assertOk()
            ->assertSee('P3 View Torrent')
            ->assertSee('vsn_snatcher');
        $this->assertNotEmpty($snatch->id);
    }

    public function testViewsnatchesEmptyMessage()
    {
        $owner = $this->makeUser('vsn_empty');
        $torrent = $this->makeTorrent($owner);

        $this->requestAs($owner, 'get', '/viewsnatches.php?id=' . $torrent->id)
            ->assertOk()
            ->assertSee('No user has snatched this torrent yet.');
    }

    // ------------------------------------------------------------------ viewpeerlist

    public function testViewpeerlistGuestReturnsEmpty()
    {
        $this->get('/viewpeerlist.php?id=1')->assertOk()->assertContent('');
    }

    public function testViewpeerlistRendersSeedersAndLeechers()
    {
        $owner = $this->makeUser('vpl_owner', User::CLASS_STAFF_LEADER);
        $seeder = $this->makeUser('vpl_seeder');
        $leecher = $this->makeUser('vpl_leecher');
        $torrent = $this->makeTorrent($owner);

        $this->makePeer($torrent, $seeder, 'yes');
        $this->makePeer($torrent, $leecher, 'no');

        $this->requestAs($owner, 'get', '/viewpeerlist.php?id=' . $torrent->id)
            ->assertOk()
            ->assertSee('Seeders')
            ->assertSee('Leechers')
            ->assertSee('vpl_seeder')
            ->assertSee('vpl_leecher');
    }

    private function makePeer(Torrent $torrent, User $user, string $seeder): Peer
    {
        $now = now();
        return Peer::query()->create([
            'torrent' => $torrent->id,
            'peer_id' => str_pad(substr(md5($user->id . $seeder), 0, 20), 20, 'x'),
            'ip' => '127.0.0.1',
            'ipv4' => '127.0.0.1',
            'ipv6' => '',
            'port' => 6881,
            'uploaded' => 1024 * 1024,
            'downloaded' => 1024 * 512,
            'to_go' => $seeder == 'yes' ? 0 : 100 * 1024,
            'seeder' => $seeder,
            'started' => $now,
            'last_action' => $now,
            'connectable' => 'yes',
            'userid' => $user->id,
            'agent' => 'qBittorrent/4.3.0;',
            'finishedat' => $seeder == 'yes' ? time() : 0,
            'downloadoffset' => 0,
            'uploadoffset' => 0,
            'passkey' => md5('peer' . $user->id),
        ]);
    }

    // ------------------------------------------------------------------ viewfilelist

    public function testViewfilelistGuestReturnsEmpty()
    {
        $this->get('/viewfilelist.php?id=1')->assertOk()->assertContent('');
    }

    public function testViewfilelistRendersFiles()
    {
        $owner = $this->makeUser('vfl_owner');
        $torrent = $this->makeTorrent($owner);

        File::query()->create([
            'torrent' => $torrent->id,
            'filename' => '/movie/example.mkv',
            'size' => 1024 * 1024 * 700,
        ]);

        $this->requestAs($owner, 'get', '/viewfilelist.php?id=' . $torrent->id)
            ->assertOk()
            ->assertSee('/movie/example.mkv');
    }

    // ------------------------------------------------------------------ viewnfo

    public function testViewnfoRequiresLogin()
    {
        $this->get('/viewnfo.php?id=1')->assertRedirect();
    }

    public function testViewnfoRequiresPermission()
    {
        $user = $this->makeUser('vnf_denied');
        $owner = $this->makeUser('vnf_owner', User::CLASS_STAFF_LEADER);
        $torrent = $this->makeTorrent($owner);

        $this->requestAs($user, 'get', '/viewnfo.php?id=' . $torrent->id)
            ->assertForbidden();
    }

    public function testViewnfoRenders()
    {
        $owner = $this->makeUser('vnf_owner', User::CLASS_STAFF_LEADER);
        $torrent = $this->makeTorrent($owner);

        TorrentExtra::query()->create([
            'torrent_id' => $torrent->id,
            'descr' => '',
            'nfo' => "VCD-TEST\n====\nsome nfo content",
        ]);

        $this->requestAs($owner, 'get', '/viewnfo.php?id=' . $torrent->id)
            ->assertOk()
            ->assertSee('VCD-TEST')
            ->assertSee('View NFO File');
    }

    public function testViewnfoMagicAndLatin1Views()
    {
        $owner = $this->makeUser('vnf_views', User::CLASS_STAFF_LEADER);
        $torrent = $this->makeTorrent($owner);

        TorrentExtra::query()->create([
            'torrent_id' => $torrent->id,
            'descr' => '',
            'nfo' => "abc \x01 test",
        ]);

        $this->requestAs($owner, 'get', '/viewnfo.php?id=' . $torrent->id . '&view=magic')
            ->assertOk();
        $this->requestAs($owner, 'get', '/viewnfo.php?id=' . $torrent->id . '&view=latin-1')
            ->assertOk();
    }
}
