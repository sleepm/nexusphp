<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the friends.php migration (FriendsController::web)
 * against a real database: friend/block list rendering plus add/delete flows.
 */
class FriendsPageTest extends TestCase
{
    protected array $createdUserIds = [];

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
        if ($this->createdUserIds !== []) {
            DB::table('friends')->whereIn('userid', $this->createdUserIds)->delete();
            DB::table('friends')->whereIn('friendid', $this->createdUserIds)->delete();
            DB::table('blocks')->whereIn('userid', $this->createdUserIds)->delete();
            DB::table('blocks')->whereIn('blockid', $this->createdUserIds)->delete();
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $name, int $class = User::CLASS_USER): User
    {
        $user = User::query()->create(array_merge([
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
            'added' => now(),
            'last_access' => now(),
            'commentpm' => 'no',
            'last_pm' => now()->subMinutes(5),
        ]));
        $this->createdUserIds[] = $user->id;

        return $user;
    }

    private function cookieFor(User $user): string
    {
        $tokenJson = json_encode(['user_id' => $user->id, 'expires' => time() + 3600]);
        $signature = hash_hmac('sha256', $tokenJson, $user->auth_key);

        return base64_encode($tokenJson . '.' . $signature);
    }

    private function getFriends(User $user, string $query = '')
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/friends.php' . $query);
    }

    // ---------------------------------------------------------------------- auth

    public function testRequiresLogin()
    {
        $this->get('/friends.php')->assertRedirect();
    }

    // ---------------------------------------------------------------- rendering

    public function testRendersEmptyLists()
    {
        $user = $this->makeUser('friends_empty');

        $this->getFriends($user)
            ->assertOk()
            ->assertSee('PERSONALLIST', false)
            ->assertSee('FRIENDLIST', false)
            ->assertSee('No friends yet', false)
            ->assertSee('Blocked Users', false)
            ->assertSee('Your blocked userlist is empty', false);
    }

    public function testRendersFriend()
    {
        $user = $this->makeUser('friends_owner');
        $friend = $this->makeUser('friends_friend');
        DB::table('friends')->insert(['userid' => $user->id, 'friendid' => $friend->id]);

        $this->getFriends($user)
            ->assertOk()
            ->assertSee('friends_friend', false)
            ->assertSee('Remove from Friends', false)
            ->assertSee('Send PM', false)
            ->assertSee('action=delete', false);
    }

    public function testRendersBlockedUser()
    {
        $user = $this->makeUser('friends_block_owner');
        $blocked = $this->makeUser('friends_blocked');
        DB::table('blocks')->insert(['userid' => $user->id, 'blockid' => $blocked->id]);

        $this->getFriends($user)
            ->assertOk()
            ->assertSee('friends_blocked', false)
            ->assertSee('type=block', false);
    }

    public function testViewUserListLinkRequiresPermission()
    {
        $user = $this->makeUser('friends_noperm');
        $this->getFriends($user)->assertOk()->assertDontSee('Find users/browse user list', false);

        $leader = $this->makeUser('friends_staff', User::CLASS_STAFF_LEADER);
        $this->getFriends($leader)
            ->assertOk()
            ->assertSee('Find users/browse user list', false);
    }

    // --------------------------------------------------------------- action: add

    public function testAddFriend()
    {
        $user = $this->makeUser('friends_add_owner');
        $target = $this->makeUser('friends_add_target');

        $this->getFriends($user, "?action=add&type=friend&targetid={$target->id}")
            ->assertRedirect();
        $this->assertSame(
            1,
            DB::table('friends')->where('userid', $user->id)->where('friendid', $target->id)->count()
        );
    }

    public function testAddBlock()
    {
        $user = $this->makeUser('friends_addblock_owner');
        $target = $this->makeUser('friends_addblock_target');

        $this->getFriends($user, "?action=add&type=block&targetid={$target->id}")
            ->assertRedirect();
        $this->assertSame(
            1,
            DB::table('blocks')->where('userid', $user->id)->where('blockid', $target->id)->count()
        );
    }

    public function testAddDuplicateRejected()
    {
        $user = $this->makeUser('friends_dup_owner');
        $target = $this->makeUser('friends_dup_target');
        DB::table('friends')->insert(['userid' => $user->id, 'friendid' => $target->id]);

        $this->getFriends($user, "?action=add&type=friend&targetid={$target->id}")
            ->assertOk()
            ->assertSee('is already in your', false);
        $this->assertSame(
            1,
            DB::table('friends')->where('userid', $user->id)->where('friendid', $target->id)->count()
        );
    }

    public function testAddUnknownTypeRejected()
    {
        $user = $this->makeUser('friends_addbad_owner');
        $target = $this->makeUser('friends_addbad_target');

        $this->getFriends($user, "?action=add&type=whatever&targetid={$target->id}")
            ->assertOk()
            ->assertSee('Unknown type', false);
    }

    public function testAddInvalidIdRejected()
    {
        $user = $this->makeUser('friends_addbadid');

        $this->getFriends($user, '?action=add&type=friend&targetid=abc')
            ->assertOk()
            ->assertSee('Invalid ID', false);
    }

    // ------------------------------------------------------------- action: delete

    public function testDeleteRequiresConfirmation()
    {
        $user = $this->makeUser('friends_delconf_owner');
        $friend = $this->makeUser('friends_delconf_target');
        DB::table('friends')->insert(['userid' => $user->id, 'friendid' => $friend->id]);

        $this->getFriends($user, "?action=delete&type=friend&targetid={$friend->id}")
            ->assertOk()
            ->assertSee('Do you really want to delete a friend', false)
            ->assertSee('sure=1', false);
        $this->assertSame(
            1,
            DB::table('friends')->where('userid', $user->id)->where('friendid', $friend->id)->count()
        );
    }

    public function testDeleteFriend()
    {
        $user = $this->makeUser('friends_del_owner');
        $friend = $this->makeUser('friends_del_target');
        DB::table('friends')->insert(['userid' => $user->id, 'friendid' => $friend->id]);

        $this->getFriends($user, "?action=delete&type=friend&targetid={$friend->id}&sure=1")
            ->assertRedirect();
        $this->assertSame(
            0,
            DB::table('friends')->where('userid', $user->id)->where('friendid', $friend->id)->count()
        );
    }

    public function testDeleteBlock()
    {
        $user = $this->makeUser('friends_delblock_owner');
        $blocked = $this->makeUser('friends_delblock_target');
        DB::table('blocks')->insert(['userid' => $user->id, 'blockid' => $blocked->id]);

        $this->getFriends($user, "?action=delete&type=block&targetid={$blocked->id}&sure=1")
            ->assertRedirect();
        $this->assertSame(
            0,
            DB::table('blocks')->where('userid', $user->id)->where('blockid', $blocked->id)->count()
        );
    }

    public function testDeleteMissingFriendShowsError()
    {
        $user = $this->makeUser('friends_delmissing_owner');
        $target = $this->makeUser('friends_delmissing_target');

        $this->getFriends($user, "?action=delete&type=friend&targetid={$target->id}&sure=1")
            ->assertOk()
            ->assertSee('No friend found with ID', false);
    }

    public function testDeleteUnknownTypeRejected()
    {
        $user = $this->makeUser('friends_delbad_owner');
        $target = $this->makeUser('friends_delbad_target');

        $this->getFriends($user, "?action=delete&type=whatever&targetid={$target->id}&sure=1")
            ->assertOk()
            ->assertSee('Unknown type', false);
    }
}