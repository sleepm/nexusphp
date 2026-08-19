<?php

namespace Tests\Feature;

use App\Models\Forum;
use App\Models\ForumMod;
use App\Models\OverForum;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the forummanage.php migration
 * (ForumManageController::web + list/add/edit/delete) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown removes everything this test created.
 */
class ForumManagePageTest extends TestCase
{
    protected array $createdUserIds = [];

    protected array $createdForumIds = [];

    protected array $createdOverforumIds = [];

    protected array $createdTopicIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        // legacy forummanage.php forms are verified inside the controller, no CSRF token
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
    }

    protected function makeAdmin(string $name): User
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
            'class' => User::CLASS_SYSOP,
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

    protected function makeOverforum(string $name = 'Main', int $sort = 0): OverForum
    {
        $overforum = OverForum::query()->forceCreate([
            'name' => $name,
            'description' => 'Top level category',
            'minclassview' => 0,
            'sort' => $sort,
        ]);
        $this->createdOverforumIds[] = $overforum->id;
        return $overforum;
    }

    protected function makeForum(string $name = 'General', int $sort = 0, int $forid = 0): Forum
    {
        $forum = Forum::query()->create([
            'sort' => $sort,
            'name' => $name,
            'description' => 'Forum description',
            'minclassread' => 0,
            'minclasswrite' => 0,
            'minclasscreate' => 0,
            'forid' => $forid,
        ]);
        $this->createdForumIds[] = $forum->id;
        return $forum;
    }

    protected function tearDown(): void
    {
        if ($this->createdTopicIds !== []) {
            DB::table('posts')->whereIn('topicid', $this->createdTopicIds)->delete();
            DB::table('topics')->whereIn('id', $this->createdTopicIds)->delete();
        }
        if ($this->createdForumIds !== []) {
            DB::table('forummods')->whereIn('forumid', $this->createdForumIds)->delete();
            DB::table('forums')->whereIn('id', $this->createdForumIds)->delete();
        }
        if ($this->createdOverforumIds !== []) {
            DB::table('overforums')->whereIn('id', $this->createdOverforumIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    public function testForumManageRequiresLogin()
    {
        $this->get('/forummanage.php')->assertRedirect();
    }

    public function testListsForumsForPrivilegedUser()
    {
        $user = $this->makeAdmin('forum_mgr');
        $overforum = $this->makeOverforum('Announcements', 0);
        $this->makeForum('General', 0, $overforum->id);

        $this->requestAs($user, 'get', '/forummanage.php')
            ->assertOk()
            ->assertSee('General', false)
            ->assertSee('Announcements', false);
    }

    public function testAddForum()
    {
        $user = $this->makeAdmin('forum_add');
        $overforum = $this->makeOverforum('Category', 0);

        $this->requestAs($user, 'post', '/forummanage.php', [
            'action' => 'addforum',
            'name' => 'New Forum',
            'desc' => 'A brand new forum',
            'overforums' => $overforum->id,
            'readclass' => 1,
            'writeclass' => 1,
            'createclass' => 2,
            'sort' => 0,
        ])->assertRedirect('forummanage.php');

        $row = DB::table('forums')->where('name', 'New Forum')->first();
        $this->assertNotNull($row);
        $this->createdForumIds[] = $row->id;
    }

    public function testAddForumEmptyRedirects()
    {
        $user = $this->makeAdmin('forum_addempty');

        $this->requestAs($user, 'post', '/forummanage.php', [
            'action' => 'addforum',
            'name' => '',
            'desc' => '',
        ])->assertRedirect('forummanage.php');

        $this->assertEquals(0, DB::table('forums')->where('name', '')->count());
    }

    public function testAddForumWithModerator()
    {
        $user = $this->makeAdmin('forum_addmod');

        $this->requestAs($user, 'post', '/forummanage.php', [
            'action' => 'addforum',
            'name' => 'Moderated Forum',
            'desc' => 'With a moderator',
            'overforums' => 0,
            'readclass' => 0,
            'writeclass' => 0,
            'createclass' => 0,
            'sort' => 0,
            'moderator' => $user->username,
        ])->assertRedirect('forummanage.php');

        $row = DB::table('forums')->where('name', 'Moderated Forum')->first();
        $this->assertNotNull($row);
        $this->createdForumIds[] = $row->id;
        $this->assertDatabaseHas('forummods', [
            'forumid' => $row->id,
            'userid' => $user->id,
        ]);
    }

    public function testNewForumFormRenders()
    {
        $user = $this->makeAdmin('forum_new');
        $this->makeOverforum('Category', 0);

        $this->requestAs($user, 'get', '/forummanage.php?action=newforum')
            ->assertOk()
            ->assertSee('Category', false);
    }

    public function testEditFormRenders()
    {
        $user = $this->makeAdmin('forum_edit');
        $forum = $this->makeForum('Legacy', 1);

        $this->requestAs($user, 'get', '/forummanage.php?action=editforum&id=' . $forum->id)
            ->assertOk()
            ->assertSee('Legacy', false);
    }

    public function testEditForum()
    {
        $user = $this->makeAdmin('forum_editpost');
        $forum = $this->makeForum('Old Name', 1);

        $this->requestAs($user, 'post', '/forummanage.php', [
            'action' => 'editforum',
            'id' => $forum->id,
            'name' => 'New Name',
            'desc' => 'Updated description',
            'overforums' => 0,
            'readclass' => 2,
            'writeclass' => 1,
            'createclass' => 2,
            'sort' => 3,
        ])->assertRedirect('forummanage.php');

        $this->assertDatabaseHas('forums', [
            'id' => $forum->id,
            'name' => 'New Name',
            'description' => 'Updated description',
            'minclassread' => 2,
            'minclasswrite' => 1,
            'minclasscreate' => 2,
            'sort' => 3,
        ]);
    }

    public function testEditForumWithModerator()
    {
        $user = $this->makeAdmin('forum_editmod');
        $mod = $this->makeAdmin('forum_moduser');
        $forum = $this->makeForum('Moderated', 0);

        $this->requestAs($user, 'post', '/forummanage.php', [
            'action' => 'editforum',
            'id' => $forum->id,
            'name' => 'Moderated',
            'desc' => 'Keep moderator',
            'overforums' => 0,
            'readclass' => 0,
            'writeclass' => 0,
            'createclass' => 0,
            'sort' => 0,
            'moderator' => $mod->username,
        ])->assertRedirect('forummanage.php');

        $this->assertDatabaseHas('forummods', [
            'forumid' => $forum->id,
            'userid' => $mod->id,
        ]);
    }

    public function testDeleteForumRemovesTopicsPostsAndMods()
    {
        $user = $this->makeAdmin('forum_del');
        $forum = $this->makeForum('Gone', 0);
        $topic = Topic::query()->create([
            'userid' => $user->id,
            'subject' => 'Topic to delete',
            'forumid' => $forum->id,
            'locked' => 'no',
            'sticky' => 'no',
            'hlcolor' => 0,
            'views' => 0,
            'firstpost' => 0,
            'lastpost' => 0,
        ]);
        $this->createdTopicIds[] = $topic->id;
        DB::table('posts')->insert([
            'topicid' => $topic->id,
            'userid' => $user->id,
            'added' => now(),
            'body' => 'Post body',
            'ori_body' => 'Post body',
        ]);
        ForumMod::query()->create(['forumid' => $forum->id, 'userid' => $user->id]);

        $this->requestAs($user, 'get', '/forummanage.php?action=del&id=' . $forum->id)
            ->assertRedirect('forummanage.php');

        $this->assertDatabaseMissing('forums', ['id' => $forum->id]);
        $this->assertDatabaseMissing('forummods', ['forumid' => $forum->id]);
        $this->assertDatabaseMissing('topics', ['id' => $topic->id]);
        $this->assertDatabaseMissing('posts', ['topicid' => $topic->id]);
    }
}
