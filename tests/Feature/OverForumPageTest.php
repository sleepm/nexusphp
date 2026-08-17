<?php

namespace Tests\Feature;

use App\Models\OverForum;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the moforums.php migration
 * (OverForumController::web + list/add/edit/delete) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown removes everything this test created.
 */
class OverForumPageTest extends TestCase
{
    protected array $createdUserIds = [];

    protected array $createdOverforumIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        // legacy moforums.php forms are verified inside the controller, no CSRF token
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

    protected function tearDown(): void
    {
        if ($this->createdOverforumIds !== []) {
            DB::table('overforums')->whereIn('id', $this->createdOverforumIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    public function testMoforumsRequiresLogin()
    {
        $this->get('/moforums.php')->assertRedirect();
    }

    public function testListsOverforumsForPrivilegedUser()
    {
        $user = $this->makeAdmin('forum_mgr');
        $this->makeOverforum('Announcements', 0);

        $this->requestAs($user, 'get', '/moforums.php')
            ->assertOk()
            ->assertSee('Announcements', false);
    }

    public function testAddOverforum()
    {
        $user = $this->makeAdmin('forum_add');

        $this->requestAs($user, 'post', '/moforums.php', [
            'action' => 'addforum',
            'name' => 'New Category',
            'desc' => 'A brand new category',
            'viewclass' => 0,
            'sort' => 0,
        ])->assertRedirect('moforums.php?action=forum');

        $row = DB::table('overforums')->where('name', 'New Category')->first();
        $this->assertNotNull($row);
        $this->createdOverforumIds[] = $row->id;
    }

    public function testAddOverforumEmptyRedirects()
    {
        $user = $this->makeAdmin('forum_addempty');

        $this->requestAs($user, 'post', '/moforums.php', [
            'action' => 'addforum',
            'name' => '',
            'desc' => '',
            'viewclass' => 0,
            'sort' => 0,
        ])->assertRedirect('moforums.php?action=forum');

        $this->assertEquals(0, DB::table('overforums')->where('name', '')->count());
    }

    public function testEditFormRenders()
    {
        $user = $this->makeAdmin('forum_edit');
        $overforum = $this->makeOverforum('Legacy', 1);

        $this->requestAs($user, 'get', '/moforums.php?action=editforum&id=' . $overforum->id)
            ->assertOk()
            ->assertSee('Legacy', false);
    }

    public function testEditOverforum()
    {
        $user = $this->makeAdmin('forum_editpost');
        $overforum = $this->makeOverforum('Old Name', 1);

        $this->requestAs($user, 'post', '/moforums.php', [
            'action' => 'editforum',
            'id' => $overforum->id,
            'name' => 'New Name',
            'desc' => 'Updated description',
            'viewclass' => 1,
            'sort' => 2,
        ])->assertRedirect('moforums.php?action=forum');

        $this->assertDatabaseHas('overforums', [
            'id' => $overforum->id,
            'name' => 'New Name',
            'description' => 'Updated description',
            'minclassview' => 1,
            'sort' => 2,
        ]);
    }

    public function testDeleteOverforum()
    {
        $user = $this->makeAdmin('forum_del');
        $overforum = $this->makeOverforum('Gone', 0);

        $this->requestAs($user, 'get', '/moforums.php?action=del&id=' . $overforum->id)
            ->assertRedirect('moforums.php?action=forum');

        $this->assertDatabaseMissing('overforums', ['id' => $overforum->id]);
    }
}