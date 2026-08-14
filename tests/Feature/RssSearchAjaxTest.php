<?php

namespace Tests\Feature;

use App\Models\Bookmark;
use App\Models\Category;
use App\Models\Torrent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the Phase 2 P2 migration
 * (getrss.php / torrentrss.php / search.php / ajax.php / getusertorrentlistajax.php)
 * against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class RssSearchAjaxTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdTorrentIds = [];

    private array $createdBookmarkIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'phpunit';
        if (class_exists('class_cache_redis') && !empty($GLOBALS['Cache']) && $GLOBALS['Cache'] instanceof class_cache_redis && $GLOBALS['Cache']->getIsEnabled()) {
            $legacyKeys = $GLOBALS['Cache']->redis->keys('nexus_rss:*');
            $legacyKeys = array_merge($legacyKeys, $GLOBALS['Cache']->redis->keys('user_passkey_*_rss'));
            if ($legacyKeys) {
                $GLOBALS['Cache']->redis->del($legacyKeys);
            }
        }
        $redis = app('redis')->connection('cache')->client();
        $keys = array_merge($redis->keys('nexus_rss:*'), $redis->keys('user_passkey_*'));
        if ($keys) {
            $redis->del($keys);
        }
    }

    protected function tearDown(): void
    {
        if ($this->createdBookmarkIds !== []) {
            DB::table('bookmarks')->whereIn('id', $this->createdBookmarkIds)->delete();
        }
        if ($this->createdTorrentIds !== []) {
            $tids = array_values(array_unique($this->createdTorrentIds));
            DB::table('snatched')->whereIn('torrentid', $tids)->delete();
            DB::table('peers')->whereIn('torrent', $tids)->delete();
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

    private function makeTorrent(User $owner, string $name = 'P2 Search Torrent'): Torrent
    {
        $categoryId = Category::query()->value('id');
        if (!$categoryId) {
            $categoryId = 1;
        }
        $torrent = Torrent::query()->create([
            'name' => $name,
            'filename' => 'p2.torrent',
            'save_as' => 'p2',
            'category' => $categoryId,
            'owner' => $owner->id,
            'size' => 1000 * 1048576,
            'anonymous' => 'no',
            'banned' => 'no',
            'visible' => 'yes',
            'sp_state' => 1,
            'pos_state' => Torrent::POS_STATE_STICKY_NONE,
            'approval_status' => Torrent::APPROVAL_STATUS_ALLOW,
            'price' => 0,
            'info_hash' => md5($name, true),
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

    // ------------------------------------------------------------------ getrss.php

    public function testGetrssRequiresLogin()
    {
        $this->get('/getrss.php')->assertRedirect();
    }

    public function testGetrssRendersForm()
    {
        $user = $this->makeUser('getrss_form');
        $this->requestAs($user, 'get', '/getrss.php')
            ->assertOk()
            ->assertSee('RSS Feeds');
    }

    public function testGetrssPostGeneratesFeedLink()
    {
        $user = $this->makeUser('getrss_post');
        $this->requestAs($user, 'post', '/getrss.php', [
            'showrows' => '10',
            'cat1' => '1',
            'incldesc' => '1',
            'search' => 'test',
            'search_mode' => '0',
            'itemcategory' => '1',
            'itemsize' => '1',
        ])
            ->assertOk()
            ->assertSee('/torrentrss.php?')
            ->assertSee('passkey=' . $user->passkey);
    }

    // ------------------------------------------------------------------ torrentrss.php

    public function testTorrentrssRequiresPasskey()
    {
        $this->get('/torrentrss.php')->assertSee('require passkey');
    }

    public function testTorrentrssInvalidPasskey()
    {
        $this->get('/torrentrss.php?passkey=' . str_repeat('f', 32))->assertSee('invalid passkey');
    }

    public function testTorrentrssRendersXml()
    {
        $owner = $this->makeUser('rss_owner');
        $this->makeTorrent($owner, 'RSS Featured Torrent');
        $this->get('/torrentrss.php?passkey=' . $owner->passkey)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/xml; charset=utf-8')
            ->assertSee('RSS Featured Torrent')
            ->assertSee('<rss version="2.0">', false);
    }

    public function testTorrentrssRespectsDownloadLinkType()
    {
        $owner = $this->makeUser('rss_dl');
        $torrent = $this->makeTorrent($owner, 'RSS Download Link Torrent');
        $this->get('/torrentrss.php?passkey=' . $owner->passkey . '&linktype=dl')
            ->assertOk()
            ->assertSee('download.php?downhash=', false);
    }

    // ------------------------------------------------------------------ search.php

    public function testSearchRequiresLogin()
    {
        $this->get('/search.php')->assertRedirect();
    }

    public function testSearchWithoutKeywordsRendersNoResult()
    {
        $user = $this->makeUser('search_empty');
        $this->requestAs($user, 'get', '/search.php?search=')
            ->assertOk()
            ->assertSee('Search results for');
    }

    public function testSearchFindsTorrentByName()
    {
        $owner = $this->makeUser('search_owner');
        $this->makeTorrent($owner, 'UniqueSearchableTorrentName');
        $user = $this->makeUser('search_user');
        $this->requestAs($user, 'get', '/search.php?search=' . rawurlencode('UniqueSearchableTorrentName'))
            ->assertOk()
            ->assertSee('UniqueSearchableTorrentName');
    }

    public function testSearchNoMatchShowsMessage()
    {
        $user = $this->makeUser('search_nomatch');
        $this->requestAs($user, 'get', '/search.php?search=' . rawurlencode('NoSuchTorrentExistsXYZ'))
            ->assertOk()
            ->assertSee('Nothing found');
    }

    // ------------------------------------------------------------------ ajax.php

    public function testAjaxInvalidActionReturnsFailed()
    {
        $user = $this->makeUser('ajax_bad');
        $this->requestAs($user, 'post', '/ajax.php', ['action' => 'noSuchAction', 'params' => []])
            ->assertOk()
            ->assertJsonPath('ret', -1);
    }

    public function testAjaxRequiresLoginForProtectedActions()
    {
        $this->post('/ajax.php', ['action' => 'getPasskeyList', 'params' => []])
            ->assertOk()
            ->assertJsonPath('ret', -1);
    }

    // ------------------------------------------------------------------ getusertorrentlistajax.php

    public function testGetUserTorrentListRequiresLogin()
    {
        $this->get('/getusertorrentlistajax.php?userid=1&type=uploaded')->assertStatus(403);
    }

    public function testGetUserTorrentListUploaded()
    {
        $owner = $this->makeUser('utl_owner');
        $torrent = $this->makeTorrent($owner, 'Uploaded List Torrent');

        $this->requestAs($owner, 'get', '/getusertorrentlistajax.php?userid=' . $owner->id . '&type=uploaded')
            ->assertOk()
            ->assertSee('Uploaded List Torrent');
    }

    public function testGetUserTorrentListOtherUserForbidden()
    {
        $owner = $this->makeUser('utl_owner2');
        $viewer = $this->makeUser('utl_plain');
        $this->requestAs($viewer, 'get', '/getusertorrentlistajax.php?userid=' . $owner->id . '&type=uploaded')
            ->assertStatus(403);
    }

    public function testGetUserTorrentListNoRecord()
    {
        $user = $this->makeUser('utl_norec');
        $this->requestAs($user, 'get', '/getusertorrentlistajax.php?userid=' . $user->id . '&type=completed')
            ->assertOk()
            ->assertSee('No record');
    }
}
