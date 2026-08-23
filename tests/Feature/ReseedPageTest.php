<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the takereseed.php migration
 * (ReseedController::web) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class ReseedPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdTorrentIds = [];

    private array $createdSnatchIds = [];

    private array $createdPeerIds = [];

    private array $originalSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';

        // Pre-set the required settings so the first get_setting() call in
        // the controller loads them.  The get_setting() static cache is
        // per-process so we can only mutate the DB before the first fetch.
        $this->setSetting('basic.SITENAME', 'NexusPHP');
        $this->setSetting('basic.BASEURL', 'localhost');
        $this->setSetting('authority.askreseed', '2');
    }

    protected function tearDown(): void
    {
        foreach ($this->originalSettings as $name => $value) {
            if ($value === null) {
                DB::table('settings')->where('name', $name)->delete();
            } else {
                DB::table('settings')->where('name', $name)->update(['value' => $value]);
            }
        }
        app('cache')->forget('nexus_settings_in_laravel');
        app('cache')->forget('nexus_settings_in_nexus');
        \Nexus\Database\NexusDB::cache_del('nexus_settings_in_laravel');
        \Nexus\Database\NexusDB::cache_del('nexus_settings_in_nexus');
        if ($this->createdPeerIds !== []) {
            DB::table('peers')->whereIn('id', $this->createdPeerIds)->delete();
        }
        if ($this->createdSnatchIds !== []) {
            DB::table('snatched')->whereIn('id', $this->createdSnatchIds)->delete();
        }
        if ($this->createdTorrentIds !== []) {
            DB::table('torrents')->whereIn('id', $this->createdTorrentIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('messages')->whereIn('receiver', $ids)->delete();
            DB::table('torrents')->whereIn('owner', $ids)->delete();
            DB::table('users')->whereIn('id', $ids)->delete();
        }
        parent::tearDown();
    }

    private function setSetting(string $name, $value): void
    {
        $row = DB::table('settings')->where('name', $name)->first();
        $this->originalSettings[$name] = $row ? $row->value : null;
        $stored = is_array($value) ? json_encode($value) : $value;
        if ($row) {
            DB::table('settings')->where('name', $name)->update(['value' => $stored]);
        } else {
            DB::table('settings')->insert([
                'name' => $name,
                'value' => $stored,
                'created_at' => now(),
                'updated_at' => now(),
                'autoload' => 'yes',
            ]);
        }
        app('cache')->forget('nexus_settings_in_laravel');
        app('cache')->forget('nexus_settings_in_nexus');
        \Nexus\Database\NexusDB::cache_del('nexus_settings_in_laravel');
        \Nexus\Database\NexusDB::cache_del('nexus_settings_in_nexus');
    }

    private function makeUser(string $name, int $class, array $overrides = []): User
    {
        $user = User::query()->forceCreate(array_merge([
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
            'warned' => 'no',
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

    private function getReseed(User $user, int $torrentId)
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/takereseed.php?reseedid=' . $torrentId);
    }

    private function makeTorrent(User $owner, array $overrides = []): int
    {
        $id = DB::table('torrents')->insertGetId(array_merge([
            'name' => 'Reseed E2E Torrent',
            'filename' => 'reseed-e2e.torrent',
            'save_as' => 'reseed-e2e',
            'category' => 401,
            'owner' => $owner->id,
            'size' => 1024 * 1024 * 1024,
            'added' => now()->subDays(30),
            'views' => 0,
            'seeders' => 0,
            'leechers' => 0,
            'last_reseed' => null,
        ], $overrides));
        $this->createdTorrentIds[] = $id;

        return $id;
    }

    private function makeSnatch(int $torrentId, User $user, array $overrides = []): int
    {
        $id = DB::table('snatched')->insertGetId(array_merge([
            'torrentid' => $torrentId,
            'userid' => $user->id,
            'ip' => '127.0.0.1',
            'port' => 51413,
            'uploaded' => 1024,
            'downloaded' => 1024,
            'to_go' => 0,
            'seedtime' => 3600,
            'leechtime' => 3600,
            'last_action' => now(),
            'startdat' => now()->subDay(),
            'completedat' => now()->subHours(2),
            'finished' => 'yes',
        ], $overrides));
        $this->createdSnatchIds[] = $id;

        return $id;
    }

    private function makePeer(int $torrentId, User $user): int
    {
        $id = DB::table('peers')->insertGetId([
            'torrent' => $torrentId,
            'peer_id' => random_bytes(20),
            'ip' => '127.0.0.1',
            'port' => 51413,
            'uploaded' => 0,
            'downloaded' => 0,
            'to_go' => 0,
            'seeder' => 'yes',
            'started' => now(),
            'last_action' => now(),
            'prev_action' => now(),
            'connectable' => 'yes',
            'userid' => $user->id,
            'agent' => 'PHPUnit',
            'finishedat' => 0,
            'downloadoffset' => 0,
            'uploadoffset' => 0,
            'passkey' => md5((string) $user->id),
        ]);
        $this->createdPeerIds[] = $id;

        return $id;
    }

    // ------------------------------------------------------------------ auth

    public function testReseedRequiresLogin()
    {
        $this->get('/takereseed.php?reseedid=1')->assertRedirect();
    }

    // ----------------------------------------------------------- permissions

    public function testReseedRejectsUserWithoutPermission()
    {
        $user = $this->makeUser('reseed_noperm', User::CLASS_USER);
        $owner = $this->makeUser('reseed_noperm_owner', User::CLASS_USER);
        $torrentId = $this->makeTorrent($owner);

        $response = $this->getReseed($user, $torrentId);
        $response->assertOk()->assertSee('Permission denied', false);
        $this->assertSame(0, DB::table('messages')->where('sender', 0)->count());
        $this->assertNull(DB::table('torrents')->where('id', $torrentId)->value('last_reseed'));
    }

    public function testReseedAllowsPowerUser()
    {
        $user = $this->makeUser('reseed_power', User::CLASS_POWER_USER);
        $owner = $this->makeUser('reseed_power_owner', User::CLASS_USER);
        $torrentId = $this->makeTorrent($owner);

        $this->getReseed($user, $torrentId)
            ->assertOk()
            ->assertSee('It worked!', false);
    }

    // ------------------------------------------------------------- validation

    public function testReseedRejectsMissingId()
    {
        $user = $this->makeUser('reseed_noid', User::CLASS_POWER_USER);

        $this->getReseed($user, 0)->assertOk()->assertSee('Error', false);
    }

    public function testReseedRejectsNonExistingTorrent()
    {
        $user = $this->makeUser('reseed_notorrent', User::CLASS_POWER_USER);

        $this->getReseed($user, 99999999)->assertOk()->assertSee('Error', false);
    }

    public function testReseedRejectsLiveTorrent()
    {
        $user = $this->makeUser('reseed_live', User::CLASS_POWER_USER);
        $owner = $this->makeUser('reseed_live_owner', User::CLASS_USER);
        $torrentId = $this->makeTorrent($owner);
        $this->makePeer($torrentId, $owner);

        $this->getReseed($user, $torrentId)
            ->assertOk()
            ->assertSee('The torrent is not dead.', false);

        $this->assertSame(0, DB::table('messages')->where('sender', 0)->count());
        $this->assertNull(DB::table('torrents')->where('id', $torrentId)->value('last_reseed'));
    }

    public function testReseedRejectsRecentRequest()
    {
        $user = $this->makeUser('reseed_recent', User::CLASS_POWER_USER);
        $owner = $this->makeUser('reseed_recent_owner', User::CLASS_USER);
        $torrentId = $this->makeTorrent($owner, ['last_reseed' => now()->subMinutes(5)]);

        $this->getReseed($user, $torrentId)
            ->assertOk()
            ->assertSee('Someone already asked for reseed of this torrent in last 15 minutes.', false);

        $this->assertSame(0, DB::table('messages')->where('sender', 0)->count());
    }

    // -------------------------------------------------------------- success

    public function testReseedPmsCompletedUsers()
    {
        $requester = $this->makeUser('reseed_ok_requester', User::CLASS_POWER_USER);
        $owner = $this->makeUser('reseed_ok_owner', User::CLASS_USER);
        $completed = $this->makeUser('reseed_ok_completed', User::CLASS_USER);
        $incomplete = $this->makeUser('reseed_ok_incomplete', User::CLASS_USER);
        $torrentId = $this->makeTorrent($owner);
        $this->makeSnatch($torrentId, $completed);
        $this->makeSnatch($torrentId, $incomplete, ['finished' => 'no']);

        $this->getReseed($requester, $torrentId)
            ->assertOk()
            ->assertSee('It worked!', false);

        $messages = DB::table('messages')->where('sender', 0)->where('receiver', $completed->id)->get();
        $this->assertCount(1, $messages);
        $this->assertEquals('Reseed Request', $messages->first()->subject);
        $this->assertStringContainsString('reseed_ok_requester', $messages->first()->msg);
        $this->assertStringContainsString('[url=', $messages->first()->msg);
        $this->assertStringContainsString('Reseed E2E Torrent', $messages->first()->msg);
        $this->assertStringContainsString('Thank You!', $messages->first()->msg);
        $this->assertSame(0, DB::table('messages')->where('sender', 0)->where('receiver', $incomplete->id)->count());

        $updated = DB::table('torrents')->where('id', $torrentId)->first();
        $this->assertNotNull($updated->last_reseed);
        $this->assertEquals(0, (int) $updated->seeders);
    }
}
