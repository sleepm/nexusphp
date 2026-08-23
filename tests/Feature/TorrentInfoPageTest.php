<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Rhilip\Bencode\Bencode;
use Tests\TestCase;

/**
 * End-to-end coverage of the torrent_info.php migration
 * (TorrentInfoController::web) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class TorrentInfoPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdTorrentIds = [];

    private array $tempFiles = [];

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
        // torrentstructure defaults to class 8 (Ultimate User)
        $this->setSetting('authority.torrentstructure', '8');
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

    private function getTorrentInfo(User $user, int $id)
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/torrent_info.php?id=' . $id);
    }

    private function makeTorrent(User $owner, array $overrides = []): int
    {
        $id = DB::table('torrents')->insertGetId(array_merge([
            'name' => 'Structure E2E Torrent',
            'filename' => 'structure-e2e.torrent',
            'save_as' => 'structure-e2e',
            'category' => 401,
            'owner' => $owner->id,
            'size' => 1024 * 1024 * 1024,
            'added' => now()->subDays(30),
            'views' => 0,
            'hits' => 0,
        ], $overrides));
        $this->createdTorrentIds[] = $id;

        return $id;
    }

    private function createTorrentFile(int $torrentId, array $dict = []): void
    {
        $content = Bencode::encode($dict ?: [
            'announce' => 'http://tracker.example.com/announce',
            'info' => [
                'name' => 'structure-e2e',
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

    public function testRequiresLogin()
    {
        $this->get('/torrent_info.php?id=1')->assertRedirect();
    }

    // ----------------------------------------------------------- permissions

    public function testRejectsUserWithoutPermission()
    {
        $user = $this->makeUser('torinfo_noperm', User::CLASS_USER);
        $owner = $this->makeUser('torinfo_noperm_owner', User::CLASS_USER);
        $torrentId = $this->makeTorrent($owner);
        $this->createTorrentFile($torrentId);

        $this->getTorrentInfo($user, $torrentId)
            ->assertOk()
            ->assertSee('Permission denied', false);
    }

    public function testAllowsUserWithPermission()
    {
        $user = $this->makeUser('torinfo_perm', User::CLASS_ULTIMATE_USER);
        $owner = $this->makeUser('torinfo_perm_owner', User::CLASS_USER);
        $torrentId = $this->makeTorrent($owner);
        $this->createTorrentFile($torrentId);

        $this->getTorrentInfo($user, $torrentId)
            ->assertOk()
            ->assertSee('Structure E2E Torrent', false);
    }

    // ------------------------------------------------------------- validation

    public function testInvalidIdNotFound()
    {
        $user = $this->makeUser('torinfo_badid', User::CLASS_ULTIMATE_USER);

        $this->getTorrentInfo($user, 0)->assertStatus(404);
        $this->getTorrentInfo($user, -5)->assertStatus(404);
    }

    public function testNonExistingTorrentNotFound()
    {
        $user = $this->makeUser('torinfo_notorrent', User::CLASS_ULTIMATE_USER);

        $this->getTorrentInfo($user, 99999999)->assertStatus(404);
    }

    public function testMissingTorrentFileNotFound()
    {
        $user = $this->makeUser('torinfo_nofile', User::CLASS_ULTIMATE_USER);
        $owner = $this->makeUser('torinfo_nofile_owner', User::CLASS_USER);
        $torrentId = $this->makeTorrent($owner);

        $this->getTorrentInfo($user, $torrentId)->assertStatus(404);
    }

    // -------------------------------------------------------------- structure

    public function testShowsTorrentStructure()
    {
        $user = $this->makeUser('torinfo_ok', User::CLASS_ULTIMATE_USER);
        $owner = $this->makeUser('torinfo_ok_owner', User::CLASS_USER);
        $torrentId = $this->makeTorrent($owner);
        $this->createTorrentFile($torrentId);

        $response = $this->getTorrentInfo($user, $torrentId);
        $response->assertOk()
            ->assertSee('torrent-structure', false)
            ->assertSee('[root]', false)
            ->assertSee('[announce]', false)
            ->assertSee('[info]', false)
            ->assertSee('Dictionary', false)
            ->assertSee('String', false)
            ->assertSee('Integer', false)
            ->assertSee('0x', false);

        $this->assertStringContainsString("class='dictionary'", $response->getContent());
        $this->assertStringContainsString('class=string', $response->getContent());
        $this->assertStringContainsString('class=integer', $response->getContent());
    }
}
