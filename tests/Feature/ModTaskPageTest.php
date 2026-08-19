<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the modtask.php migration
 * (ModTaskController::web + confirmuser / edituser) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown removes everything this test created.
 */
class ModTaskPageTest extends TestCase
{
    protected array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        // legacy userdetails.php / unco.php forms post to modtask.php without a CSRF token
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    }

    protected function makeUser(string $name, int $class = User::CLASS_PEASANT, array $extra = []): User
    {
        $user = User::query()->forceCreate(array_merge([
            'username' => $name,
            'auth_key' => 'authkey_' . $name,
            'email' => $name . '@example.com',
            'passkey' => md5($name . uniqid('', true)),
            'status' => 'confirmed',
            'enabled' => 'yes',
            'uploaded' => 1024 * 1024 * 1024,
            'downloaded' => 10 * 1024 * 1024 * 1024,
            'seedbonus' => 100000,
            'class' => $class,
            'added' => now(),
            'lang' => 1,
            'forumpost' => 'yes',
            'avatar' => '',
            'signature' => '',
            'last_access' => now(),
        ], $extra));
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

    protected function tearDown(): void
    {
        if ($this->createdUserIds !== []) {
            DB::table('messages')->whereIn('receiver', $this->createdUserIds)->where('sender', 0)->delete();
            DB::table('funds')->whereIn('user', $this->createdUserIds)->delete();
            DB::table('username_change_logs')->whereIn('uid', $this->createdUserIds)->delete();
            DB::table('user_modify_logs')->whereIn('user_id', $this->createdUserIds)->delete();
            DB::table('sitelog')->whereIn('uid', $this->createdUserIds)->delete();
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    public function testModTaskRequiresLogin()
    {
        $this->post('/modtask.php', ['action' => 'edituser'])->assertRedirect();
    }

    public function testConfirmUser()
    {
        $admin = $this->makeUser('mod_confirm_admin', User::CLASS_SYSOP);
        $pending = $this->makeUser('mod_pending_user', User::CLASS_PEASANT, ['status' => 'pending', 'info' => 'please confirm']);

        $this->requestAs($admin, 'post', '/modtask.php', [
            'action' => 'confirmuser',
            'userid' => $pending->id,
            'confirm' => 'confirmed',
        ])->assertRedirect('unco.php?status=1');

        $this->assertDatabaseHas('users', ['id' => $pending->id, 'status' => 'confirmed', 'info' => null]);
    }

    public function testConfirmUserInvalidConfirmPukes()
    {
        $admin = $this->makeUser('mod_confirm_admin2', User::CLASS_SYSOP);
        $pending = $this->makeUser('mod_pending_user2', User::CLASS_PEASANT, ['status' => 'pending']);

        $this->requestAs($admin, 'post', '/modtask.php', [
            'action' => 'confirmuser',
            'userid' => $pending->id,
            'confirm' => 'hacked',
        ])->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $pending->id, 'status' => 'pending']);
    }

    public function testEditUserBasicFields()
    {
        $admin = $this->makeUser('mod_edit_admin', User::CLASS_SYSOP);
        $target = $this->makeUser('mod_edit_target', User::CLASS_PEASANT);

        $this->requestAs($admin, 'post', '/modtask.php', [
            'action' => 'edituser',
            'userid' => $target->id,
            'returnto' => 'userdetails.php?id=' . $target->id,
            'username' => $target->username,
            'email' => $target->email,
            'title' => 'New Title',
            'avatar' => 'http://example.com/avatar.png',
            'signature' => 'A new signature',
            'privacy' => 'low',
            'support' => 'yes',
            'supportlang' => 'en',
            'supportfor' => 'helping',
            'staffduties' => 'staff duty',
            'moviepicker' => 'yes',
            'pickfor' => 'picks',
            'uploadpos' => 'yes',
            'downloadpos' => 'yes',
            'forumpost' => 'yes',
            'noad' => 'yes',
            'noaduntil' => '',
            'warnlength' => 0,
        ])->assertRedirect('userdetails.php?id=' . $target->id);

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'title' => 'New Title',
            'avatar' => 'http://example.com/avatar.png',
            'signature' => 'A new signature',
            'privacy' => 'low',
            'support' => 'yes',
            'supportlang' => 'en',
            'supportfor' => 'helping',
            'stafffor' => 'staff duty',
            'picker' => 'yes',
            'pickfor' => 'picks',
            'noad' => 'yes',
        ]);
    }

    public function testEditUserRequiresPrfmanage()
    {
        $peasant = $this->makeUser('mod_no_rights', User::CLASS_PEASANT);
        $target = $this->makeUser('mod_no_rights_target', User::CLASS_PEASANT);

        $this->requestAs($peasant, 'post', '/modtask.php', [
            'action' => 'edituser',
            'userid' => $target->id,
            'returnto' => 'userdetails.php?id=' . $target->id,
            'title' => 'hax',
        ])->assertForbidden();
    }

    public function testEditUserCannotEditEqualOrHigherClass()
    {
        $admin = $this->makeUser('mod_sysop', User::CLASS_SYSOP);
        $otherSysop = $this->makeUser('mod_other_sysop', User::CLASS_SYSOP);

        $this->requestAs($admin, 'post', '/modtask.php', [
            'action' => 'edituser',
            'userid' => $otherSysop->id,
            'returnto' => 'userdetails.php?id=' . $otherSysop->id,
            'title' => 'nope',
        ])->assertForbidden();
    }

    public function testEditUserWarnFlow()
    {
        $admin = $this->makeUser('mod_warn_admin', User::CLASS_SYSOP);
        $target = $this->makeUser('mod_warn_target', User::CLASS_PEASANT);

        $this->requestAs($admin, 'post', '/modtask.php', [
            'action' => 'edituser',
            'userid' => $target->id,
            'returnto' => 'userdetails.php?id=' . $target->id,
            'username' => $target->username,
            'email' => $target->email,
            'warnlength' => 1,
            'warnpm' => 'ratio too low',
            'title' => '',
            'forumpost' => 'yes',
            'uploadpos' => 'yes',
            'downloadpos' => 'yes',
        ])->assertRedirect('userdetails.php?id=' . $target->id);

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'warned' => 'yes',
            'timeswarned' => 1,
            'warnedby' => $admin->id,
        ]);
        $this->assertDatabaseHas('messages', [
            'sender' => 0,
            'receiver' => $target->id,
        ]);
        $this->assertDatabaseHas('user_modify_logs', [
            'user_id' => $target->id,
        ]);
    }

    public function testEditUserRemoveWarning()
    {
        $admin = $this->makeUser('mod_unwarn_admin', User::CLASS_SYSOP);
        $target = $this->makeUser('mod_unwarn_target', User::CLASS_PEASANT, [
            'warned' => 'yes',
            'timeswarned' => 2,
            'warnedby' => $admin->id,
        ]);

        $this->requestAs($admin, 'post', '/modtask.php', [
            'action' => 'edituser',
            'userid' => $target->id,
            'returnto' => 'userdetails.php?id=' . $target->id,
            'username' => $target->username,
            'email' => $target->email,
            'warned' => 'no',
            'title' => '',
            'forumpost' => 'yes',
            'uploadpos' => 'yes',
            'downloadpos' => 'yes',
        ])->assertRedirect('userdetails.php?id=' . $target->id);

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'warned' => 'no',
            'warneduntil' => null,
        ]);
        $this->assertDatabaseHas('messages', [
            'sender' => 0,
            'receiver' => $target->id,
        ]);
    }

    public function testEditUserChangeUploadDownloadForumPost()
    {
        $admin = $this->makeUser('mod_rights_admin', User::CLASS_SYSOP);
        $target = $this->makeUser('mod_rights_target', User::CLASS_PEASANT);

        $this->requestAs($admin, 'post', '/modtask.php', [
            'action' => 'edituser',
            'userid' => $target->id,
            'returnto' => 'userdetails.php?id=' . $target->id,
            'username' => $target->username,
            'email' => $target->email,
            'uploadpos' => 'no',
            'downloadpos' => 'no',
            'forumpost' => 'no',
            'title' => '',
        ])->assertRedirect('userdetails.php?id=' . $target->id);

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'uploadpos' => 'no',
            'downloadpos' => 'no',
            'forumpost' => 'no',
        ]);
        // enable, disable and warn messages are all sender=0
        $this->assertSame(3, DB::table('messages')->where('receiver', $target->id)->where('sender', 0)->count());
    }

    public function testEditUserResetPasskey()
    {
        $admin = $this->makeUser('mod_reset_admin', User::CLASS_SYSOP);
        $target = $this->makeUser('mod_reset_target', User::CLASS_PEASANT);
        $oldPasskey = $target->passkey;

        $this->requestAs($admin, 'post', '/modtask.php', [
            'action' => 'edituser',
            'userid' => $target->id,
            'returnto' => 'userdetails.php?id=' . $target->id,
            'username' => $target->username,
            'email' => $target->email,
            'resetkey' => 'yes',
            'title' => '',
            'forumpost' => 'yes',
            'uploadpos' => 'yes',
            'downloadpos' => 'yes',
        ])->assertRedirect('userdetails.php?id=' . $target->id);

        $row = DB::table('users')->where('id', $target->id)->first();
        $this->assertNotSame($oldPasskey, $row->passkey);
    }

    public function testEditUserEmailUsernameChange()
    {
        $admin = $this->makeUser('mod_cru_admin', User::CLASS_SYSOP);
        $target = $this->makeUser('mod_cru_target', User::CLASS_PEASANT);

        $this->requestAs($admin, 'post', '/modtask.php', [
            'action' => 'edituser',
            'userid' => $target->id,
            'returnto' => 'userdetails.php?id=' . $target->id,
            'username' => 'mod_cru_renamed',
            'email' => 'new_email@example.com',
            'title' => '',
            'forumpost' => 'yes',
            'uploadpos' => 'yes',
            'downloadpos' => 'yes',
        ])->assertRedirect('userdetails.php?id=' . $target->id);

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'username' => 'mod_cru_renamed',
            'email' => 'new_email@example.com',
        ]);
        $this->assertDatabaseHas('username_change_logs', [
            'uid' => $target->id,
            'username_old' => 'mod_cru_target',
            'username_new' => 'mod_cru_renamed',
        ]);
        // email-change + username-change messages
        $this->assertSame(2, DB::table('messages')->where('receiver', $target->id)->where('sender', 0)->count());
    }

    public function testStaffLeaderDonorUpdate()
    {
        $leader = $this->makeUser('mod_leader', User::CLASS_STAFF_LEADER);
        $target = $this->makeUser('mod_donor_target', User::CLASS_PEASANT);

        $this->requestAs($leader, 'post', '/modtask.php', [
            'action' => 'edituser',
            'userid' => $target->id,
            'returnto' => 'userdetails.php?id=' . $target->id,
            'donor' => 'yes',
            'donoruntil' => date('Y-m-d H:i:s', strtotime('+1 year')),
            'donated' => 100,
            'donated_cny' => 0,
            'donation_memo' => 'thanks!',
            'username' => $target->username,
            'email' => $target->email,
            'title' => '',
        ])->assertRedirect('userdetails.php?id=' . $target->id);

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'donor' => 'yes',
            'donated' => 100,
        ]);
        $this->assertDatabaseHas('funds', [
            'user' => $target->id,
            'usd' => 100,
            'cny' => 0,
            'memo' => 'thanks!',
        ]);
        $this->assertDatabaseHas('messages', [
            'sender' => 0,
            'receiver' => $target->id,
        ]);
    }

    public function testEditUserUnknownActionPukes()
    {
        $admin = $this->makeUser('mod_bad_action', User::CLASS_SYSOP);

        $this->requestAs($admin, 'post', '/modtask.php', [
            'action' => 'dropAllTables',
        ])->assertForbidden();
    }
}