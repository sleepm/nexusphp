<?php

namespace Tests\Feature;

use App\Models\Forum;
use App\Models\OverForum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the forums.php migration
 * (ForumController::web + portal/viewforum/viewtopic/search) against a real
 * database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown removes everything this test created.
 */
class ForumPageTest extends TestCase
{
    protected array $createdUserIds = [];

    protected array $createdOverforumIds = [];

    protected array $createdForumIds = [];

    protected array $createdTopicIds = [];

    protected array $createdPostIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        // legacy forum forms are verified inside the controller, no CSRF token
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        // legacy site sends c_secure_pass etc. unencrypted (EncryptCookies except list)
        $this->disableCookieEncryption();
        // the local test DB has no activity_log table (only used in production)
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        $this->clearForumCache();
    }

    protected function clearForumCache(): void
    {
        // the cache global is set up by BootNexus middleware during requests;
        // guard setUp() (which runs before any request) against a missing global
        if (!isset($GLOBALS['Cache'])) {
            return;
        }
        foreach ([
            'forums_list',
            'overforums_list',
            'total_posts_count',
            'total_topics_count',
        ] as $key) {
            $GLOBALS['Cache']->delete_value($key);
        }
    }

    protected function makeUser(string $name): User
    {
        $user = User::query()->create([
            'username' => $name,
            'auth_key' => 'authkey_' . $name,
            'email' => $name . '@example.com',
            'password' => bcrypt('password'),
            'passkey' => md5(uniqid('', true)),
            'status' => 'confirmed',
            'enabled' => 'yes',
            'uploaded' => 1024 * 1024 * 1024,
            'downloaded' => 10 * 1024 * 1024 * 1024,
            'seedbonus' => 100000,
            'class' => 1,
            'added' => now(),
            'lang' => 1,
            'forumpost' => 'yes',
            'avatar' => '',
            'signature' => '',
            'last_access' => now(),
        ]);
        $this->createdUserIds[] = $user->id;
        return $user;
    }

    protected function cookieFor(User $user): string
    {
        $tokenJson = json_encode(['user_id' => $user->id, 'expires' => time() + 3600]);
        $signature = hash_hmac('sha256', $tokenJson, $user->auth_key);
        return base64_encode($tokenJson . '.' . $signature);
    }

    protected function requestAs(User $user, string $method, string $uri, array $data = [])
    {
        app('auth')->forgetGuards();
        $cookieName = 'c_secure_pass';
        $cookieValue = $this->cookieFor($user);
        if ($method === 'get') {
            return $this->withCookie($cookieName, $cookieValue)->get($uri);
        }
        return $this->withCookie($cookieName, $cookieValue)->post($uri, $data);
    }

    protected function makeForum(string $name = 'General'): Forum
    {
        $overforum = OverForum::query()->forceCreate([
            'name' => 'Main',
            'description' => 'Top level',
            'minclassview' => 0,
            'sort' => 0,
        ]);
        $this->createdOverforumIds[] = $overforum->id;
        $forum = Forum::query()->create([
            'sort' => 0,
            'name' => $name,
            'description' => 'Discussion',
            'minclassread' => 0,
            'minclasswrite' => 0,
            'minclasscreate' => 0,
            'postcount' => 0,
            'topiccount' => 0,
            'forid' => $overforum->id,
        ]);
        $this->createdForumIds[] = $forum->id;
        $this->clearForumCache();
        return $forum;
    }

    protected function makeTopic(Forum $forum, User $user): Topic
    {
        $topic = Topic::query()->forceCreate([
            'userid' => $user->id,
            'subject' => 'Hello world topic',
            'locked' => 'no',
            'forumid' => $forum->id,
            'sticky' => 'no',
            'hlcolor' => 0,
            'views' => 0,
        ]);
        $this->createdTopicIds[] = $topic->id;
        $post = $this->makePost($topic, $user);
        Topic::query()->whereKey($topic->id)->update(['firstpost' => $post->id, 'lastpost' => $post->id]);
        return $topic;
    }

    protected function makePost(Topic $topic, User $user): Post
    {
        $post = Post::query()->forceCreate([
            'topicid' => $topic->id,
            'userid' => $user->id,
            'added' => now(),
            'body' => 'First post body content',
            'ori_body' => 'First post body content',
        ]);
        $this->createdPostIds[] = $post->id;
        return $post;
    }

    protected function tearDown(): void
    {
        if ($this->createdPostIds !== []) {
            DB::table('posts')->whereIn('id', $this->createdPostIds)->delete();
        }
        if ($this->createdTopicIds !== []) {
            DB::table('topics')->whereIn('id', $this->createdTopicIds)->delete();
        }
        if ($this->createdForumIds !== []) {
            DB::table('forums')->whereIn('id', $this->createdForumIds)->delete();
        }
        if ($this->createdOverforumIds !== []) {
            DB::table('overforums')->whereIn('id', $this->createdOverforumIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        $this->clearForumCache();
        parent::tearDown();
    }

    public function testForumsRequiresLogin()
    {
        $this->get('/forums.php')->assertRedirect();
    }

    public function testForumPortalRendersForUser()
    {
        $user = $this->makeUser('forum_viewer');
        $this->makeForum('General Chat');

        $this->requestAs($user, 'get', '/forums.php')
            ->assertOk()
            ->assertSee('General Chat', false)
            ->assertSee('?action=viewforum', false);
    }

    public function testViewForumListsTopics()
    {
        $user = $this->makeUser('forum_reader');
        $forum = $this->makeForum('Tech');
        $this->makeTopic($forum, $user);

        $this->requestAs($user, 'get', '/forums.php?action=viewforum&forumid=' . $forum->id)
            ->assertOk()
            ->assertSee('Hello world topic', false);
    }

    public function testViewForumUnknownForumRejected()
    {
        $user = $this->makeUser('forum_badforum');

        $this->requestAs($user, 'get', '/forums.php?action=viewforum&forumid=99999')
            ->assertStatus(400);
    }

    public function testViewTopicRendersPosts()
    {
        $user = $this->makeUser('forum_poster');
        $forum = $this->makeForum('Announce');
        $topic = $this->makeTopic($forum, $user);

        $this->requestAs($user, 'get', '/forums.php?action=viewtopic&topicid=' . $topic->id)
            ->assertOk()
            ->assertSee('Hello world topic', false)
            ->assertSee('First post body content', false);
    }

    public function testViewTopicUnknownTopicRejected()
    {
        $user = $this->makeUser('forum_badtopic');

        $this->requestAs($user, 'get', '/forums.php?action=viewtopic&topicid=99999')
            ->assertStatus(400);
    }

    public function testSearchKeywordFindsPosts()
    {
        $user = $this->makeUser('forum_searcher');
        $forum = $this->makeForum('Search');
        $this->makeTopic($forum, $user);

        $this->requestAs($user, 'get', '/forums.php?action=search&keywords=hello')
            ->assertOk()
            ->assertSee('Hello', false)
            ->assertSee('striking', false);
    }

    public function testSearchWithoutKeywordShowsForm()
    {
        $user = $this->makeUser('forum_searchblank');

        $this->requestAs($user, 'get', '/forums.php?action=search')
            ->assertOk()
            ->assertSee('keywords', false);
    }

    public function testViewUnreadRenders()
    {
        $user = $this->makeUser('forum_unread');
        $forum = $this->makeForum('Unread');
        $this->makeTopic($forum, $user);

        $this->requestAs($user, 'get', '/forums.php?action=viewunread')
            ->assertOk();
    }
}