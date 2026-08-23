<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetriverPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdTorrentIds = [];

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';

        $this->categoryId = DB::table('categories')->insertGetId([
            'mode' => 'single',
            'name' => 'retriver_test_cat',
            'class_name' => 'retriver_test',
            'image' => '',
            'sort_index' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->createdTorrentIds !== []) {
            DB::table('torrent_extras')->whereIn('torrent_id', $this->createdTorrentIds)->delete();
            DB::table('torrents')->whereIn('id', $this->createdTorrentIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        DB::table('categories')->where('id', $this->categoryId)->delete();
        parent::tearDown();
    }

    private function makeUser(string $username, $class = User::CLASS_STAFF_LEADER, array $overrides = []): User
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

        return $user;
    }

    private function makeTorrent(User $owner, array $overrides = []): int
    {
        $id = DB::table('torrents')->insertGetId(array_merge([
            'name' => 'Retriver Test Torrent',
            'small_descr' => 'a small description',
            'category' => $this->categoryId,
            'owner' => $owner->id,
            'added' => now(),
            'visible' => 'yes',
            'banned' => 'no',
            'anonymous' => 'no',
            'sp_state' => 1,
            'promotion_time_type' => 0,
            'pos_state' => 'normal',
            'picktype' => 'normal',
            'url' => '',
        ], $overrides));
        $this->createdTorrentIds[] = $id;
        DB::table('torrent_extras')->insert([
            'torrent_id' => $id,
            'descr' => 'test description',
            'media_info' => '',
        ]);

        return $id;
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

    public function testRetriverRequiresLogin()
    {
        $this->get('/retriver.php?id=1&type=1&siteid=1')->assertRedirect();
    }

    public function testRetriverRejectsLowClass()
    {
        $user = $this->makeUser('retriver_low_user', User::CLASS_USER);

        $this->asUser($user)->get('/retriver.php?id=1&type=1&siteid=1')
            ->assertForbidden();
    }

    public function testRetriverRejectsMissingId()
    {
        $user = $this->makeUser('retriver_no_id');

        $this->asUser($user)->get('/retriver.php?id=0&type=1&siteid=1')
            ->assertStatus(400);
    }

    public function testRetriverRejectsMissingType()
    {
        $user = $this->makeUser('retriver_no_type');

        $this->asUser($user)->get('/retriver.php?id=1&type=0&siteid=1')
            ->assertStatus(400);
    }

    public function testRetriverRejectsMissingSiteId()
    {
        $user = $this->makeUser('retriver_no_siteid');

        $this->asUser($user)->get('/retriver.php?id=1&type=1&siteid=0')
            ->assertStatus(400);
    }

    public function testRetriverRejectsNonExistentTorrent()
    {
        $user = $this->makeUser('retriver_bad_torrent');

        $this->asUser($user)->get('/retriver.php?id=999999&type=1&siteid=1')
            ->assertStatus(400);
    }

    public function testRetriverRejectsUnknownSiteId()
    {
        $user = $this->makeUser('retriver_bad_siteid');
        $torrentId = $this->makeTorrent($user);

        $this->asUser($user)->get("/retriver.php?id=$torrentId&type=1&siteid=99")
            ->assertStatus(400);
    }

    public function testRetriverRedirectsAfterFetchImdb()
    {
        $user = $this->makeUser('retriver_imdb_ok');
        $torrentId = $this->makeTorrent($user);

        $this->asUser($user)->get("/retriver.php?id=$torrentId&type=1&siteid=1")
            ->assertRedirect("/details.php?id=$torrentId");
    }

    public function testRetriverRedirectsAfterPtGenUpdate()
    {
        $user = $this->makeUser('retriver_ptgen_ok');
        $torrentId = $this->makeTorrent($user);

        $this->asUser($user)->get("/retriver.php?id=$torrentId&type=1&siteid=imdb")
            ->assertRedirect("/details.php?id=$torrentId");
    }
}