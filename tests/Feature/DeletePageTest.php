<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the delete.php migration
 * (TorrentController::webDelete) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class DeletePageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdTorrentIds = [];

    private array $createdTorrentFiles = [];

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
        foreach ($this->createdTorrentFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        if ($this->createdUserIds !== []) {
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('messages')->whereIn('receiver', $ids)->where('sender', 0)->delete();
            DB::table('sitelog')->whereIn('uid', $ids)->where('txt', 'like', 'Torrent % was deleted%')->delete();
        }
        if ($this->createdTorrentIds !== []) {
            DB::table('torrent_tags')->whereIn('torrent_id', $this->createdTorrentIds)->delete();
            DB::table('torrent_extras')->whereIn('torrent_id', $this->createdTorrentIds)->delete();
            DB::table('torrent_operation_logs')->whereIn('torrent_id', $this->createdTorrentIds)->delete();
            DB::table('torrents')->whereIn('id', $this->createdTorrentIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('torrents')->whereIn('owner', $ids)->delete();
            DB::table('users')->whereIn('id', $ids)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $username, $class = User::CLASS_UPLOADER, array $overrides = []): User
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

    private function postDelete(User $user, array $data)
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->post('/delete.php', $data);
    }

    private function makeTorrent(User $owner, array $overrides = []): int
    {
        $id = DB::table('torrents')->insertGetId(array_merge([
            'name' => 'Delete Torrent Name',
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

        // deletetorrent() unlinks the .torrent file; create a dummy one so the
        // legacy helper does not emit a warning in the test environment.
        $file = getFullDirectory(get_setting('main.torrent_dir') . "/$id.torrent");
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, 'dummy');
        $this->createdTorrentFiles[] = $file;

        return $id;
    }

    // ------------------------------------------------------------------ auth

    public function testDeleteRequiresLogin()
    {
        $this->post('/delete.php', ['id' => 1, 'reasontype' => 5, 'reason' => ['', '', '', 'test']])->assertRedirect();
    }

    // ----------------------------------------------------------- permission

    public function testDeleteRejectsUserWithoutPermission()
    {
        $owner = $this->makeUser('delete_perm_owner');
        $uploader = $this->makeUser('delete_perm_uploader', User::CLASS_UPLOADER);
        $torrentId = $this->makeTorrent($owner);

        $response = $this->postDelete($uploader, ['id' => $torrentId, 'reasontype' => 5, 'reason' => ['', '', '', 'test']]);
        $response->assertRedirect();
        $this->assertStringContainsString('/error', $response->headers->get('Location'));

        $this->assertNotNull(DB::table('torrents')->where('id', $torrentId)->first());
    }

    // -------------------------------------------------------------- deletion

    public function testDeleteDeletesTorrent()
    {
        $owner = $this->makeUser('delete_del_owner');
        $admin = $this->makeUser('delete_del_admin', User::CLASS_ADMINISTRATOR);
        $torrentId = $this->makeTorrent($owner, ['name' => 'Torrent To Be Deleted']);

        $response = $this->postDelete($admin, [
            'id' => $torrentId,
            'reasontype' => 5,
            'reason' => ['', '', '', 'The uploader broke the rules.'],
        ]);
        $response->assertOk();
        $response->assertSee('Torrent deleted!');

        $this->assertNull(DB::table('torrents')->where('id', $torrentId)->first());
        $this->assertNull(DB::table('torrent_extras')->where('torrent_id', $torrentId)->first());

        $log = DB::table('sitelog')
            ->where('txt', 'like', 'Torrent ' . $torrentId . '%')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('was deleted by ' . $admin->username, $log->txt);
        $this->assertStringContainsString('The uploader broke the rules.', $log->txt);

        $bonus = (float) get_setting('bonus.uploadtorrent', 0);
        if ($bonus > 0) {
            $this->assertEquals(100 - $bonus, DB::table('users')->where('id', $owner->id)->value('seedbonus'));
        }

        $message = DB::table('messages')
            ->where('receiver', $owner->id)
            ->where('sender', 0)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($message);
        $this->assertStringContainsString('Torrent To Be Deleted', $message->msg);
        $this->assertStringContainsString('The uploader broke the rules.', $message->msg);
    }

    public function testDeleteWithDeadReason()
    {
        $owner = $this->makeUser('delete_dead_owner');
        $admin = $this->makeUser('delete_dead_admin', User::CLASS_ADMINISTRATOR);
        $torrentId = $this->makeTorrent($owner);

        $this->postDelete($admin, ['id' => $torrentId, 'reasontype' => 1, 'reason' => []])
            ->assertOk()
            ->assertSee('Torrent deleted!');

        $log = DB::table('sitelog')
            ->where('txt', 'like', 'Torrent ' . $torrentId . '%')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Dead: 0 seeders, 0 leechers = 0 peers total', $log->txt);
    }

    public function testDeleteOwnTorrentWithoutNotification()
    {
        $admin = $this->makeUser('delete_own_admin', User::CLASS_ADMINISTRATOR);
        $torrentId = $this->makeTorrent($admin, ['name' => 'Own Torrent To Be Deleted']);

        $this->postDelete($admin, ['id' => $torrentId, 'reasontype' => 2, 'reason' => ['already uploaded', '', '', '']])
            ->assertOk()
            ->assertSee('Torrent deleted!');

        $this->assertNull(DB::table('torrents')->where('id', $torrentId)->first());
        $this->assertNull(DB::table('messages')->where('receiver', $admin->id)->where('sender', 0)->first());
    }

    // ------------------------------------------------------------ validation

    public function testDeleteRejectsMissingId()
    {
        $admin = $this->makeUser('delete_missing_id', User::CLASS_ADMINISTRATOR);

        $response = $this->postDelete($admin, ['reasontype' => 5, 'reason' => ['', '', '', 'test']]);
        $response->assertRedirect();
        $this->assertStringContainsString('/error', $response->headers->get('Location'));
    }

    public function testDeleteRejectsInvalidReasonType()
    {
        $owner = $this->makeUser('delete_invalid_rt');
        $admin = $this->makeUser('delete_invalid_rt_admin', User::CLASS_ADMINISTRATOR);
        $torrentId = $this->makeTorrent($owner);

        $response = $this->postDelete($admin, ['id' => $torrentId, 'reasontype' => 0, 'reason' => []]);
        $response->assertRedirect();
        $this->assertStringContainsString('/error', $response->headers->get('Location'));

        $this->assertNotNull(DB::table('torrents')->where('id', $torrentId)->first());
    }

    public function testDeleteRejectsMissingRulesReason()
    {
        $owner = $this->makeUser('delete_missing_rules');
        $admin = $this->makeUser('delete_missing_rules_admin', User::CLASS_ADMINISTRATOR);
        $torrentId = $this->makeTorrent($owner);

        $response = $this->postDelete($admin, ['id' => $torrentId, 'reasontype' => 4, 'reason' => ['', '', '', '']]);
        $response->assertRedirect();
        $this->assertStringContainsString('/error', $response->headers->get('Location'));

        $this->assertNotNull(DB::table('torrents')->where('id', $torrentId)->first());
    }

    public function testDeleteRendersReturntoLink()
    {
        $owner = $this->makeUser('delete_returnto_owner');
        $admin = $this->makeUser('delete_returnto_admin', User::CLASS_ADMINISTRATOR);
        $torrentId = $this->makeTorrent($owner);

        $this->postDelete($admin, [
            'id' => $torrentId,
            'reasontype' => 5,
            'reason' => ['', '', '', 'gone'],
            'returnto' => 'details.php?id=' . $torrentId,
        ])
            ->assertOk()
            ->assertSee('details.php?id=' . $torrentId, false);
    }
}
