<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Torrent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the Phase 2 P0 migration
 * (comment / bookmark / thanks / attendance) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class InteractionPagesTest extends TestCase
{
    private array $createdUserIds = [];

    private int $testStart;

    protected function setUp(): void
    {
        parent::setUp();
        // the migrated comment.php form posts with @csrf in production; we bypass
        // the middleware here so the controller flow can be exercised directly
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        // the legacy site sets c_secure_pass etc. unencrypted (they are in the
        // EncryptCookies except list), so send cookies without test-harness encryption
        $this->disableCookieEncryption();
        // the local test DB has no activity_log table (only used in production)
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $this->testStart = time();
    }

    protected function tearDown(): void
    {
        if ($this->createdUserIds !== []) {
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('comments')->whereIn('user', $ids)->delete();
            DB::table('thanks')->whereIn('userid', $ids)->delete();
            DB::table('bookmarks')->whereIn('userid', $ids)->delete();
            DB::table('attendance_logs')->whereIn('uid', $ids)->delete();
            DB::table('attendance')->whereIn('uid', $ids)->delete();
            DB::table('torrents')->whereIn('owner', $ids)->delete();
            DB::table('users')->whereIn('id', $ids)->delete();
        }
        DB::table('regimages')->where('dateline', '>=', $this->testStart)->delete();
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
        return Torrent::query()->create([
            'name' => 'E2E Test Torrent',
            'filename' => 'e2e.torrent',
            'save_as' => 'e2e',
            'category' => 1,
            'owner' => $owner->id,
            'added' => now(),
        ]);
    }

    private function cookieFor(User $user): string
    {
        $tokenJson = json_encode(['user_id' => $user->id, 'expires' => time() + 3600]);
        $signature = hash_hmac('sha256', $tokenJson, $user->auth_key);
        return base64_encode($tokenJson . '.' . $signature);
    }

    /**
     * The nexus RequestGuard caches its resolved user per process, but a real
     * HTTP request is a fresh process. Reset the cached guards between the
     * per-test requests so each authenticated request resolves its own user.
     */
    private function requestAs(User $user, string $method, string $uri, array $data = [])
    {
        app('auth')->forgetGuards();
        $request = $this->withCookie('c_secure_pass', $this->cookieFor($user));
        if ($method === 'get') {
            return $request->get($uri);
        }

        return $request->post($uri, $data);
    }

    public function testGuestBookmarkReturnsFailed()
    {
        $this->get('/bookmark.php?torrentid=1')->assertOk()->assertSee('failed');
    }

    public function testCommentPageRequiresLogin()
    {
        $this->get('/comment.php?action=add&type=torrent&pid=1')->assertRedirect();
        $this->post('/comment.php?action=add&type=torrent', ['pid' => 1, 'body' => 'hello'])->assertRedirect();
        $this->get('/comment.php?action=edit&type=torrent&cid=1')->assertRedirect();
        $this->get('/comment.php?action=delete&type=torrent&cid=1')->assertRedirect();
        $this->get('/comment.php?action=vieworiginal&type=torrent&cid=1')->assertRedirect();
    }

    public function testThanksRequiresLogin()
    {
        $this->post('/thanks.php', ['id' => 1])->assertRedirect();
    }

    public function testAttendancePageRequiresLogin()
    {
        $this->get('/attendance.php')->assertRedirect();
        $this->post('/attendance.php')->assertRedirect();
    }

    public function testBookmarkToggle()
    {
        $user = $this->makeUser('bookuser');
        $owner = $this->makeUser('bookowner');
        $torrent = $this->makeTorrent($owner);

        $this->requestAs($user, 'get', '/bookmark.php?torrentid=' . $torrent->id)
            ->assertOk()->assertSee('added');
        $this->assertDatabaseHas('bookmarks', ['userid' => $user->id, 'torrentid' => $torrent->id]);

        $this->requestAs($user, 'get', '/bookmark.php?torrentid=' . $torrent->id)
            ->assertOk()->assertSee('deleted');
        $this->assertDatabaseMissing('bookmarks', ['userid' => $user->id, 'torrentid' => $torrent->id]);
    }

    public function testThanksSuccess()
    {
        $user = $this->makeUser('thankuser');
        $owner = $this->makeUser('thankowner');
        $torrent = $this->makeTorrent($owner);

        $this->requestAs($user, 'post', '/thanks.php', ['id' => $torrent->id])
            ->assertOk()->assertSee('thanks');
        $this->assertDatabaseHas('thanks', ['userid' => $user->id, 'torrentid' => $torrent->id]);
    }

    public function testCommentAddFormAndStore()
    {
        $user = $this->makeUser('comuser');
        $owner = $this->makeUser('comowner');
        $torrent = $this->makeTorrent($owner);

        $this->requestAs($user, 'get', '/comment.php?action=add&type=torrent&pid=' . $torrent->id)
            ->assertOk()->assertSee('compose');

        $response = $this->requestAs($user, 'post', '/comment.php?action=add&type=torrent', ['pid' => $torrent->id, 'body' => 'Hello world']);
        $response->assertRedirect();
        $this->assertDatabaseHas('comments', [
            'user' => $user->id,
            'torrent' => $torrent->id,
            'text' => 'Hello world',
        ]);
    }

    public function testCommentEditDeleteAndViewOriginal()
    {
        $user = $this->makeUser('edituser');
        $admin = $this->makeUser('admin', User::CLASS_STAFF_LEADER);
        $owner = $this->makeUser('editowner');
        $torrent = $this->makeTorrent($owner);
        $comment = Comment::query()->create([
            'user' => $user->id,
            'torrent' => $torrent->id,
            'added' => now(),
            'text' => 'original text',
            'ori_text' => 'original text',
            'anonymous' => 'no',
        ]);

        $this->requestAs($user, 'get', '/comment.php?action=edit&type=torrent&cid=' . $comment->id)
            ->assertOk()->assertSee('original text');

        $this->requestAs($user, 'post', '/comment.php?action=edit&type=torrent&cid=' . $comment->id, ['body' => 'updated text'])
            ->assertRedirect();
        $this->assertDatabaseHas('comments', ['id' => $comment->id, 'text' => 'updated text']);

        $this->requestAs($admin, 'get', '/comment.php?action=vieworiginal&type=torrent&cid=' . $comment->id)
            ->assertOk()->assertSee('original text');

        $this->requestAs($admin, 'get', '/comment.php?action=delete&type=torrent&cid=' . $comment->id)
            ->assertOk();

        $this->requestAs($admin, 'get', '/comment.php?action=delete&type=torrent&cid=' . $comment->id . '&sure=1')
            ->assertRedirect();
        $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
    }

    public function testAttendanceSignIn()
    {
        $user = $this->makeUser('attendance_user');

        // not attended yet → shows the check-in form
        $this->requestAs($user, 'get', '/attendance.php')
            ->assertOk()->assertSee('attendance.php');

        // a valid captcha imagehash/imagestring pair
        $imagehash = md5(uniqid('captcha', true));
        sql_query(sprintf(
            "INSERT INTO regimages (imagehash, dateline, imagestring) VALUES ('%s', %d, '%s')",
            $imagehash,
            time(),
            'test123'
        ));

        $this->requestAs($user, 'post', '/attendance.php', ['imagehash' => $imagehash, 'imagestring' => 'test123'])
            ->assertRedirect('attendance.php');
        $this->assertDatabaseHas('attendance', ['uid' => $user->id]);

        // now attended → calendar branch
        $this->requestAs($user, 'get', '/attendance.php')
            ->assertOk()->assertSee('calendar');
    }
}