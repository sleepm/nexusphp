<?php

namespace Tests\Feature;

use App\Models\Torrent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the details.php migration (TorrentController::web)
 * against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class TorrentDetailsPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdTorrentIds = [];

    private array $createdCommentIds = [];

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
        if ($this->createdCommentIds !== []) {
            DB::table('comments')->whereIn('id', $this->createdCommentIds)->delete();
        }
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
            'name' => 'Details E2E Torrent',
            'filename' => 'details-e2e.torrent',
            'save_as' => 'details-e2e',
            'category' => 401,
            'owner' => $owner->id,
            'size' => 1024 * 1024 * 1024,
            'added' => now()->subDays(30),
            'views' => 0,
        ], $overrides));
        $this->createdTorrentIds[] = $torrent->id;

        return $torrent;
    }

    // ------------------------------------------------------------------ auth

    public function testDetailsRequiresLogin()
    {
        $this->get('/details.php?id=1')->assertRedirect();
    }

    // -------------------------------------------------------------- rendering

    public function testDetailsRendersTorrentInfo()
    {
        $owner = $this->makeUser('details_owner');
        $viewer = $this->makeUser('details_viewer');
        $torrent = $this->makeTorrent($owner);

        $this->requestAs($viewer, 'get', '/details.php?id=' . $torrent->id)
            ->assertOk()
            ->assertSee('Details E2E Torrent')
            ->assertSee('Download')
            ->assertSee('Basic Info')
            ->assertSee('1.00 GB')
            ->assertSee('Action')
            ->assertSee('Quick Comment');
    }

    public function testLegacyHitQueryParamIncrementsViews()
    {
        $owner = $this->makeUser('hit_owner');
        $viewer = $this->makeUser('hit_viewer');
        $torrent = $this->makeTorrent($owner);

        $this->requestAs($viewer, 'get', '/details.php?id=' . $torrent->id . '&hit=1')->assertOk();

        $this->assertSame(1, (int) DB::table('torrents')->where('id', $torrent->id)->value('views'));
    }

    public function testMissingTorrentReturns404()
    {
        $user = $this->makeUser('missing_viewer');
        $this->requestAs($user, 'get', '/details.php?id=99999999')->assertNotFound();
    }

    public function testBannedTorrentIsForbiddenForRegularViewer()
    {
        $owner = $this->makeUser('banned_owner');
        $viewer = $this->makeUser('banned_viewer');
        $this->makeTorrent($owner, ['banned' => 'yes']);

        $this->requestAs($viewer, 'get', '/details.php?id=' . $this->createdTorrentIds[0])->assertForbidden();
    }

    public function testDescriptionIsRendered()
    {
        $owner = $this->makeUser('desc_owner');
        $viewer = $this->makeUser('desc_viewer');
        $torrent = $this->makeTorrent($owner);
        DB::table('torrent_extras')->insertOrIgnore([
            'torrent_id' => $torrent->id,
            'descr' => 'A rich <b>HTML</b> description for the torrent.',
        ]);

        $this->requestAs($viewer, 'get', '/details.php?id=' . $torrent->id)
            ->assertOk()
            ->assertSee('Description')
            ->assertSee('A rich &lt;b&gt;HTML&lt;/b&gt; description for the torrent.', false);
    }

    public function testCommentsAreRendered()
    {
        $owner = $this->makeUser('comment_owner');
        $viewer = $this->makeUser('comment_viewer');
        $torrent = $this->makeTorrent($owner);
        $commentId = DB::table('comments')->insertGetId([
            'user' => $owner->id,
            'torrent' => $torrent->id,
            'added' => now(),
            'text' => 'A first user comment on this torrent',
            'ori_text' => 'A first user comment on this torrent',
        ]);
        $this->createdCommentIds[] = $commentId;

        $this->requestAs($viewer, 'get', '/details.php?id=' . $torrent->id)
            ->assertOk()
            ->assertSee('User Comments')
            ->assertSee('A first user comment on this torrent')
            ->assertSee('Quick Comment');
    }
}
