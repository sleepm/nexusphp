<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the stats.php migration
 * (StatsController::web) against a real database.
 */
class StatsPageTest extends TestCase
{
    protected array $createdUserIds = [];

    protected array $createdTorrentIds = [];

    protected array $createdPeerIds = [];

    protected array $createdCategoryIds = [];

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
        if ($this->createdPeerIds !== []) {
            DB::table('peers')->whereIn('id', $this->createdPeerIds)->delete();
        }
        if ($this->createdTorrentIds !== []) {
            DB::table('torrents')->whereIn('id', $this->createdTorrentIds)->delete();
        }
        if ($this->createdCategoryIds !== []) {
            DB::table('categories')->whereIn('id', $this->createdCategoryIds)->delete();
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
            'added' => now()->format('Y-m-d H:i:s'),
            'last_access' => now()->format('Y-m-d H:i:s'),
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

    private function getStats(User $user, string $query = '')
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/stats.php' . $query);
    }

    private function makeCategory(string $name): int
    {
        $id = DB::table('categories')->insertGetId([
            'name' => $name,
            'mode' => 1,
            'sort_index' => 0,
            'icon_id' => 0,
        ]);
        $this->createdCategoryIds[] = $id;

        return $id;
    }

    private function makeTorrent(User $owner, int $categoryId): int
    {
        $id = DB::table('torrents')->insertGetId([
            'name' => 'Stats Torrent ' . $owner->username,
            'small_descr' => 'a small description',
            'category' => $categoryId,
            'owner' => $owner->id,
            'added' => now()->format('Y-m-d H:i:s'),
            'visible' => 'yes',
            'banned' => 'no',
            'anonymous' => 'no',
            'sp_state' => 1,
            'promotion_time_type' => 0,
            'pos_state' => 'normal',
            'picktype' => 'normal',
        ]);
        $this->createdTorrentIds[] = $id;

        return $id;
    }

    private function makePeer(int $torrentId, int $userId, bool $seeder = true): int
    {
        $id = DB::table('peers')->insertGetId([
            'torrent' => $torrentId,
            'peer_id' => str_repeat('A', 20),
            'ip' => '127.0.0.1',
            'port' => 6881,
            'uploaded' => 0,
            'downloaded' => 0,
            'to_go' => 0,
            'seeder' => $seeder ? 'yes' : 'no',
            'connectable' => 'yes',
            'userid' => $userId,
            'agent' => 'PHPUnit',
            'finishedat' => 0,
            'downloadoffset' => 0,
            'uploadoffset' => 0,
            'passkey' => str_repeat('B', 32),
        ]);
        $this->createdPeerIds[] = $id;

        return $id;
    }

    // ------------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->get('/stats.php')->assertRedirect();
    }

    public function testDeniedBelowModerator()
    {
        $user = $this->makeUser('stats_user', User::CLASS_USER);

        $this->getStats($user)->assertForbidden();
    }

    // ------------------------------------------------------------------ view

    public function testModeratorSeesUploaderAndCategoryActivity()
    {
        $mod = $this->makeUser('stats_mod', User::CLASS_MODERATOR);
        $uploader = $this->makeUser('stats_uploader', User::CLASS_ELITE_USER);
        $categoryId = $this->makeCategory('Stats Test Category');
        $torrentId = $this->makeTorrent($uploader, $categoryId);
        $this->makePeer($torrentId, $uploader->id);

        $this->getStats($mod)
            ->assertOk()
            ->assertSee('Uploader Activity', false)
            ->assertSee('stats_uploader', false)
            ->assertSee('Category Activity', false)
            ->assertSee('Stats Test Category', false)
            ->assertSee('100.0%', false);
    }

    public function testNoCategoriesWhenNoTorrents()
    {
        $mod = $this->makeUser('stats_nocat_mod', User::CLASS_MODERATOR);

        $this->getStats($mod)
            ->assertOk()
            ->assertSee('No categories defined!', false)
            ->assertDontSee('Category Activity', false);
    }

    public function testSortingParamsRender()
    {
        $mod = $this->makeUser('stats_sort_mod', User::CLASS_MODERATOR);

        foreach (['uporder=torrents', 'uporder=lastul', 'uporder=peers', 'catorder=torrents', 'catorder=lastul', 'catorder=peers'] as $query) {
            $this->getStats($mod, '?' . $query)
                ->assertOk()
                ->assertSee('Stats', false);
        }
    }
}
