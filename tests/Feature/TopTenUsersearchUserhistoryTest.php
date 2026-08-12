<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\Torrent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the Phase 2 P2 migration
 * (topten.php / usersearch.php / userhistory.php) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class TopTenUsersearchUserhistoryTest extends TestCase
{
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
    }

    protected function tearDown(): void
    {
        if ($this->createdUserIds !== []) {
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('posts')->whereIn('userid', $ids)->delete();
            DB::table('topics')->whereIn('userid', $ids)->delete();
            DB::table('forums')->whereIn('id', $this->createdForumIds ?? [])->delete();
            DB::table('comments')->whereIn('user', $ids)->delete();
            DB::table('torrents')->whereIn('owner', $ids)->delete();
            DB::table('users')->whereIn('id', $ids)->delete();
        }
        parent::tearDown();
    }

    private array $createdForumIds = [];

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

    private function cookieFor(User $user): string
    {
        $tokenJson = json_encode(['user_id' => $user->id, 'expires' => time() + 3600]);
        $signature = hash_hmac('sha256', $tokenJson, $user->auth_key);
        return base64_encode($tokenJson . '.' . $signature);
    }

    private function requestAs(User $user, string $method, string $uri)
    {
        app('auth')->forgetGuards();
        $request = $this->withCookie('c_secure_pass', $this->cookieFor($user));
        if ($method === 'get') {
            return $request->get($uri);
        }

        return $request->post($uri);
    }

    // ------------------------------------------------------------------ topten

    public function testToptenRequiresLogin()
    {
        $this->get('/topten.php')->assertRedirect();
    }

    public function testToptenRequiresPermission()
    {
        $user = $this->makeUser('topten_denied');
        $this->requestAs($user, 'get', '/topten.php')->assertForbidden();
    }

    public function testToptenListsTopUploaders()
    {
        $admin = $this->makeUser('topten_admin', User::CLASS_STAFF_LEADER);
        $target = $this->makeUser('topten_uploader');
        DB::table('users')->where('id', $target->id)->update(['uploaded' => 1024 * 1024 * 1024, 'downloaded' => 512 * 1024 * 1024]);

        $this->requestAs($admin, 'get', '/topten.php?type=1')
            ->assertOk()
            ->assertSee('Top 10')
            ->assertSee('topten_uploader');
    }

    public function testToptenTorrentsTypeRenders()
    {
        $admin = $this->makeUser('topten_torrents', User::CLASS_STAFF_LEADER);
        $this->requestAs($admin, 'get', '/topten.php?type=2')
            ->assertOk()
            ->assertSee('Top 10');
    }

    public function testToptenCountriesTypeRenders()
    {
        $admin = $this->makeUser('topten_countries', User::CLASS_STAFF_LEADER);
        $this->requestAs($admin, 'get', '/topten.php?type=3')
            ->assertOk();
    }

    public function testToptenCommunityAndOtherTypesRender()
    {
        $admin = $this->makeUser('topten_other', User::CLASS_STAFF_LEADER);
        $this->requestAs($admin, 'get', '/topten.php?type=5')->assertOk();
        $this->requestAs($admin, 'get', '/topten.php?type=6')->assertOk();
    }

    // ------------------------------------------------------------------ usersearch

    public function testUserSearchRequiresLogin()
    {
        $this->get('/usersearch.php')->assertRedirect();
    }

    public function testUserSearchRequiresModerator()
    {
        $user = $this->makeUser('search_denied');
        $this->requestAs($user, 'get', '/usersearch.php')->assertForbidden();
    }

    public function testUserSearchShowsOnlyFormOnInitialLoad()
    {
        $moderator = $this->makeUser('search_mod', User::CLASS_MODERATOR);
        $this->makeUser('search_target');

        $response = $this->requestAs($moderator, 'get', '/usersearch.php')
            ->assertOk()
            ->assertSee('Administrative User Search');
        $this->assertStringNotContainsString('No user was found', $response->getContent());
    }

    public function testUserSearchByName()
    {
        $moderator = $this->makeUser('search_mod', User::CLASS_MODERATOR);
        $target = $this->makeUser('search_target');

        $this->requestAs($moderator, 'get', '/usersearch.php?n=' . $target->username)
            ->assertOk()
            ->assertSee('search_target')
            ->assertSee($target->email);
    }

    public function testUserSearchNoResult()
    {
        $moderator = $this->makeUser('search_nomatch', User::CLASS_MODERATOR);
        $this->requestAs($moderator, 'get', '/usersearch.php?n=no-such-user-xyz')
            ->assertOk()
            ->assertSee('No user was found');
    }

    public function testUserSearchBadEmail()
    {
        $moderator = $this->makeUser('search_bademail', User::CLASS_MODERATOR);
        $this->requestAs($moderator, 'get', '/usersearch.php?em=not-an-email')
            ->assertStatus(422);
    }

    // ------------------------------------------------------------------ userhistory

    public function testUserHistoryRequiresLogin()
    {
        $this->get('/userhistory.php?id=1&action=viewposts')->assertRedirect();
    }

    public function testUserHistoryInvalidId()
    {
        $user = $this->makeUser('history_badid');
        $this->requestAs($user, 'get', '/userhistory.php?id=0')->assertNotFound();
    }

    public function testUserHistoryOtherUserRequiresPermission()
    {
        $viewer = $this->makeUser('history_viewer');
        $target = $this->makeUser('history_target');
        $this->requestAs($viewer, 'get', '/userhistory.php?id=' . $target->id . '&action=viewposts')
            ->assertForbidden();
    }

    public function testUserHistoryOwnPosts()
    {
        $user = $this->makeUser('history_posts');
        $forum = Forum::query()->create(['sort' => 0, 'name' => 'History Forum', 'description' => '', 'minclassread' => 0, 'minclasswrite' => 0, 'postcount' => 0, 'topiccount' => 0, 'minclasscreate' => 0, 'forid' => 0]);
        $this->createdForumIds[] = $forum->id;
        $topic = Topic::query()->create([
            'userid' => $user->id,
            'subject' => 'History Topic',
            'locked' => 'no',
            'forumid' => $forum->id,
            'firstpost' => 0,
            'lastpost' => 0,
            'sticky' => 'no',
            'views' => 0,
        ]);
        $post = DB::table('posts')->insertGetId([
            'topicid' => $topic->id,
            'userid' => $user->id,
            'added' => now(),
            'body' => 'Hello from the post history',
        ]);

        $this->requestAs($user, 'get', '/userhistory.php?id=' . $user->id . '&action=viewposts')
            ->assertOk()
            ->assertSee('Hello from the post history')
            ->assertSee((string) $post);
    }

    public function testUserHistoryOwnComments()
    {
        $user = $this->makeUser('history_comments');
        $owner = $this->makeUser('history_comments_owner');
        $torrent = Torrent::query()->create([
            'name' => 'History Torrent',
            'filename' => 'history.torrent',
            'save_as' => 'history',
            'category' => 1,
            'owner' => $owner->id,
            'size' => 1024,
            'added' => now(),
        ]);
        $comment = Comment::query()->create([
            'user' => $user->id,
            'torrent' => $torrent->id,
            'added' => now(),
            'text' => 'A comment in the history',
            'ori_text' => 'A comment in the history',
            'anonymous' => 'no',
        ]);

        $this->requestAs($user, 'get', '/userhistory.php?id=' . $user->id . '&action=viewcomments')
            ->assertOk()
            ->assertSee('A comment in the history')
            ->assertSee((string) $comment->id);
    }

    public function testUserHistoryUnknownAction()
    {
        $user = $this->makeUser('history_badaction');
        $this->requestAs($user, 'get', '/userhistory.php?id=' . $user->id . '&action=nope')
            ->assertStatus(400);
    }
}