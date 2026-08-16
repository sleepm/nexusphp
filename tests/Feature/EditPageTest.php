<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the edit.php migration
 * (TorrentController::webEdit) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class EditPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdTorrentIds = [];

    private int $categoryId = 401;

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
            DB::table('torrent_tags')->whereIn('torrent_id', $this->createdTorrentIds)->delete();
            DB::table('torrent_extras')->whereIn('torrent_id', $this->createdTorrentIds)->delete();
            DB::table('torrents')->whereIn('id', $this->createdTorrentIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('torrents')->whereIn('owner', $ids)->delete();
            DB::table('users')->whereIn('id', $ids)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $username, $class = User::CLASS_CRAZY_USER, array $overrides = []): User
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

    private function makeTorrent(User $owner, array $overrides = []): int
    {
        $id = DB::table('torrents')->insertGetId(array_merge([
            'name' => 'E2E Edit Torrent',
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
            'url' => 'https://www.imdb.com/title/tt0111161/',
        ], $overrides));
        $this->createdTorrentIds[] = $id;
        DB::table('torrent_extras')->insert([
            'torrent_id' => $id,
            'descr' => 'original interesting description',
            'media_info' => '',
        ]);

        return $id;
    }

    // ------------------------------------------------------------------ auth

    public function testEditRequiresLogin()
    {
        $this->get('/edit.php?id=1')->assertRedirect();
    }

    // -------------------------------------------------------------- rendering

    public function testEditPageRendersFormForOwner()
    {
        $owner = $this->makeUser('edit_owner');
        $torrentId = $this->makeTorrent($owner);

        $this->requestAs($owner, '/edit.php?id=' . $torrentId)
            ->assertOk()
            ->assertSee('E2E Edit Torrent')
            ->assertSee('action="takeedit.php"', false)
            ->assertSee('name="name"', false)
            ->assertSee('id="oricat"', false)
            ->assertSee('name="descr"', false)
            ->assertSee('original interesting description', false);
    }

    public function testEditPageHiddenFromNonOwner()
    {
        $owner = $this->makeUser('edit_hidden_owner');
        $other = $this->makeUser('edit_hidden_other');
        $torrentId = $this->makeTorrent($owner);

        $lang = get_legacy_lang_file('edit');

        $this->requestAs($other, '/edit.php?id=' . $torrentId)
            ->assertOk()
            ->assertSee($lang['text_cannot_edit_torrent'], false)
            ->assertDontSee('<form method="post"', false);
    }

    public function testEditPageShowsDeleteSectionForAdministrator()
    {
        $admin = $this->makeUser('edit_admin', User::CLASS_ADMINISTRATOR);
        $torrentId = $this->makeTorrent($admin);

        $this->requestAs($admin, '/edit.php?id=' . $torrentId)
            ->assertOk()
            ->assertSee('action="delete.php"', false)
            ->assertSee('name="reasontype"', false);
    }

    public function testEditPageReturnsEmptyWithoutId()
    {
        $user = $this->makeUser('edit_no_id');
        $this->requestAs($user, '/edit.php')->assertOk();
    }
}