<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the takeedit.php migration
 * (TorrentController::webTakeEdit) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class TakeEditPageTest extends TestCase
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

    private function postEdit(User $user, array $data)
    {
        app('auth')->forgetGuards();
        return $this->withCookie('c_secure_pass', $this->cookieFor($user))->post('/takeedit.php', $data);
    }

    private function makeTorrent(User $owner, array $overrides = []): int
    {
        $id = DB::table('torrents')->insertGetId(array_merge([
            'name' => 'Original Torrent Name',
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

    private function editData(int $torrentId, array $extra = []): array
    {
        return array_merge([
            'id' => $torrentId,
            'name' => 'Updated Torrent Name',
            'small_descr' => 'updated small description',
            'descr' => 'an updated description body',
            'type' => $this->categoryId,
            'url' => 'https://www.imdb.com/title/tt0111161/',
            'nfoaction' => 'keep',
            'price' => 0,
        ], $extra);
    }

    // ------------------------------------------------------------------ auth

    public function testTakeEditRequiresLogin()
    {
        $this->post('/takeedit.php', $this->editData(1))->assertRedirect();
    }

    // ------------------------------------------------------------- valid edit

    public function testTakeEditUpdatesTorrent()
    {
        $owner = $this->makeUser('takeedit_ok');
        $torrentId = $this->makeTorrent($owner);

        $response = $this->postEdit($owner, $this->editData($torrentId));
        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('details.php?id=' . $torrentId, $location);
        $this->assertStringContainsString('edited=1', $location);

        $torrent = DB::table('torrents')->where('id', $torrentId)->first();
        $this->assertEquals('Updated Torrent Name', $torrent->name);
        $this->assertEquals('updated small description', $torrent->small_descr);
        $this->assertEquals($this->categoryId, $torrent->category);
        $this->assertEquals('no', $torrent->anonymous);

        $extra = DB::table('torrent_extras')->where('torrent_id', $torrentId)->first();
        $this->assertNotNull($extra);
        $this->assertEquals('an updated description body', $extra->descr);
    }

    // ---------------------------------------------------------------- others

    public function testTakeEditRejectsNonOwner()
    {
        $owner = $this->makeUser('takeedit_owner');
        $other = $this->makeUser('takeedit_other');
        $torrentId = $this->makeTorrent($owner);

        $this->postEdit($other, $this->editData($torrentId))->assertRedirect();

        $torrent = DB::table('torrents')->where('id', $torrentId)->first();
        $this->assertEquals('Original Torrent Name', $torrent->name);
    }

    public function testTakeEditFailsOnMissingFormData()
    {
        $owner = $this->makeUser('takeedit_nodescr');
        $torrentId = $this->makeTorrent($owner);

        $data = $this->editData($torrentId);
        unset($data['name']);
        $this->postEdit($owner, $data)->assertRedirect();
    }

    public function testTakeEditModeratorEditLogsOperation()
    {
        $owner = $this->makeUser('takeedit_mod_owner');
        $moderator = $this->makeUser('takeedit_mod', User::CLASS_MODERATOR);
        $torrentId = $this->makeTorrent($owner);

        $this->postEdit($moderator, $this->editData($torrentId))->assertRedirect();

        $torrent = DB::table('torrents')->where('id', $torrentId)->first();
        $this->assertEquals('Updated Torrent Name', $torrent->name);

        $log = DB::table('torrent_operation_logs')
            ->where('torrent_id', $torrentId)
            ->where('action_type', 'edit')
            ->first();
        $this->assertNotNull($log);
        $this->assertEquals($moderator->id, $log->uid);
    }
}