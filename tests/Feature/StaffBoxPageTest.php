<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the staffbox.php migration
 * (StaffBoxController::web action dispatch) against a real database.
 */
class StaffBoxPageTest extends TestCase
{
    protected array $createdUserIds = [];
    protected array $createdStaffMessageIds = [];

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
        if ($this->createdStaffMessageIds !== []) {
            DB::table('staffmessages')->whereIn('id', $this->createdStaffMessageIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            DB::table('messages')->whereIn('receiver', $this->createdUserIds)->delete();
            DB::table('messages')->whereIn('sender', $this->createdUserIds)->delete();
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $name, int $class, array $overrides = []): User
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
        ], $overrides));
        $this->createdUserIds[] = $user->id;

        return $user;
    }

    private function makeStaffMessage(int $sender, string $subject, array $overrides = []): int
    {
        $id = DB::table('staffmessages')->insertGetId(array_merge([
            'sender' => $sender,
            'added' => now()->toDateTimeString(),
            'subject' => $subject,
            'msg' => 'Staff message body',
            'answeredby' => 0,
            'answered' => 0,
            'answer' => null,
            'permission' => '',
        ], $overrides));
        $this->createdStaffMessageIds[] = $id;

        return $id;
    }

    private function cookieFor(User $user): string
    {
        $tokenJson = json_encode(['user_id' => $user->id, 'expires' => time() + 3600]);
        $signature = hash_hmac('sha256', $tokenJson, $user->auth_key);
        return base64_encode($tokenJson . '.' . $signature);
    }

    private function callStaffBox(User $user, string $method, string $query = '', array $data = [])
    {
        app('auth')->forgetGuards();
        $request = $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en');

        return strtoupper($method) === 'POST'
            ? $request->post('/staffbox.php' . $query, $data)
            : $request->get('/staffbox.php' . $query);
    }

    // ------------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->get('/staffbox.php')->assertRedirect();
    }

    // ------------------------------------------------------------------ list

    public function testEmptyListRendersNotice()
    {
        $admin = $this->makeUser('staffbox_empty_admin', User::CLASS_ADMINISTRATOR);

        $this->callStaffBox($admin, 'GET')
            ->assertOk()
            ->assertSee('No messages yet!', false);
    }

    public function testListRendersMessages()
    {
        $admin = $this->makeUser('staffbox_list_admin', User::CLASS_ADMINISTRATOR);
        $sender = $this->makeUser('staffbox_list_sender', User::CLASS_USER);
        $this->makeStaffMessage($sender->id, 'First staff pm');
        $this->makeStaffMessage($sender->id, 'Second staff pm');

        $this->callStaffBox($admin, 'GET')
            ->assertOk()
            ->assertSee('First staff pm', false)
            ->assertSee('Second staff pm', false)
            ->assertSee('action="?action=takecontactanswered"', false)
            ->assertSee('name="setanswered[]"', false);
    }

    // ------------------------------------------------------------------ view

    public function testViewPmRenders()
    {
        $admin = $this->makeUser('staffbox_view_admin', User::CLASS_ADMINISTRATOR);
        $sender = $this->makeUser('staffbox_view_sender', User::CLASS_USER);
        $id = $this->makeStaffMessage($sender->id, 'View this staff pm');

        $this->callStaffBox($admin, 'GET', '?action=viewpm&pmid=' . $id)
            ->assertOk()
            ->assertSee('View this staff pm', false)
            ->assertSee('Staff message body', false)
            ->assertSee('action=deletestaffmessage&id=' . $id, false);
    }

    public function testViewPmDeniedWithoutPermission()
    {
        $plain = $this->makeUser('staffbox_plain_user', User::CLASS_USER);
        $sender = $this->makeUser('staffbox_gate_sender', User::CLASS_USER);
        $id = $this->makeStaffMessage($sender->id, 'Gated staff pm', ['permission' => 'staffmem']);

        $this->callStaffBox($plain, 'GET', '?action=viewpm&pmid=' . $id)->assertForbidden();
    }

    public function testViewPmAllowedWithPermission()
    {
        $plain = $this->makeUser('staffbox_perm_user', User::CLASS_USER);
        $sender = $this->makeUser('staffbox_perm_sender', User::CLASS_USER);
        $id = $this->makeStaffMessage($sender->id, 'Open staff pm', ['permission' => 'uploadsub']);

        $this->callStaffBox($plain, 'GET', '?action=viewpm&pmid=' . $id)
            ->assertOk()
            ->assertSee('Open staff pm', false);
    }

    // ---------------------------------------------------------------- answer

    public function testAnswerFormRenders()
    {
        $admin = $this->makeUser('staffbox_answer_admin', User::CLASS_ADMINISTRATOR);
        $sender = $this->makeUser('staffbox_answer_sender', User::CLASS_USER);
        $id = $this->makeStaffMessage($sender->id, 'Answer me');

        $this->callStaffBox($admin, 'GET', '?action=answermessage&receiver=' . $sender->id . '&answeringto=' . $id)
            ->assertOk()
            ->assertSee('action="?action=takeanswer"', false)
            ->assertSee('name=receiver value=' . $sender->id, false)
            ->assertSee('name=answeringto value=' . $id, false);
    }

    public function testTakeAnswerSendsMessageAndMarksAnswered()
    {
        $admin = $this->makeUser('staffbox_takeanswer_admin', User::CLASS_ADMINISTRATOR);
        $sender = $this->makeUser('staffbox_takeanswer_sender', User::CLASS_USER);
        $id = $this->makeStaffMessage($sender->id, 'Answer subject');

        $this->callStaffBox($admin, 'POST', '', [
            'action' => 'takeanswer',
            'receiver' => $sender->id,
            'answeringto' => $id,
            'body' => 'My answer body',
        ])
            ->assertRedirect()
            ->assertRedirect('staffbox.php?action=viewpm&pmid=' . $id);

        $this->assertSame('My answer body', DB::table('messages')->where('receiver', $sender->id)->value('msg'));
        $this->assertSame('Answer subject', DB::table('messages')->where('receiver', $sender->id)->value('subject'));

        $row = DB::table('staffmessages')->where('id', $id)->first();
        $this->assertSame(1, (int) $row->answered);
        $this->assertSame($admin->id, (int) $row->answeredby);
        $this->assertSame('My answer body', $row->answer);
    }

    public function testTakeAnswerEmptyBodyRejected()
    {
        $admin = $this->makeUser('staffbox_emptyanswer_admin', User::CLASS_ADMINISTRATOR);
        $sender = $this->makeUser('staffbox_emptyanswer_sender', User::CLASS_USER);
        $id = $this->makeStaffMessage($sender->id, 'No answer');

        $this->callStaffBox($admin, 'POST', '', [
            'action' => 'takeanswer',
            'receiver' => $sender->id,
            'answeringto' => $id,
            'body' => '   ',
        ])->assertStatus(400);
    }

    // ---------------------------------------------------------------- delete

    public function testDeleteStaffMessage()
    {
        $admin = $this->makeUser('staffbox_del_admin', User::CLASS_ADMINISTRATOR);
        $sender = $this->makeUser('staffbox_del_sender', User::CLASS_USER);
        $id = $this->makeStaffMessage($sender->id, 'To be deleted');

        $this->callStaffBox($admin, 'GET', '?action=deletestaffmessage&id=' . $id)
            ->assertRedirect();

        $this->assertNull(DB::table('staffmessages')->where('id', $id)->first());
    }

    // --------------------------------------------------------------- mark

    public function testSetAnswered()
    {
        $admin = $this->makeUser('staffbox_setanswered_admin', User::CLASS_ADMINISTRATOR);
        $sender = $this->makeUser('staffbox_setanswered_sender', User::CLASS_USER);
        $id = $this->makeStaffMessage($sender->id, 'Mark me');

        $this->callStaffBox($admin, 'GET', '?action=setanswered&id=' . $id)
            ->assertRedirect()
            ->assertRedirect('staffbox.php');

        $row = DB::table('staffmessages')->where('id', $id)->first();
        $this->assertSame(1, (int) $row->answered);
        $this->assertSame($admin->id, (int) $row->answeredby);
    }

    public function testBulkMarkAnswered()
    {
        $admin = $this->makeUser('staffbox_bulkmark_admin', User::CLASS_ADMINISTRATOR);
        $sender = $this->makeUser('staffbox_bulkmark_sender', User::CLASS_USER);
        $id1 = $this->makeStaffMessage($sender->id, 'Bulk mark 1');
        $id2 = $this->makeStaffMessage($sender->id, 'Bulk mark 2');

        $this->callStaffBox($admin, 'POST', '', [
            'action' => 'takecontactanswered',
            'setdealt' => 'Set Answered',
            'setanswered' => [$id1, $id2],
        ])->assertRedirect();

        $this->assertSame(1, (int) DB::table('staffmessages')->where('id', $id1)->value('answered'));
        $this->assertSame(1, (int) DB::table('staffmessages')->where('id', $id2)->value('answered'));
    }

    public function testBulkDelete()
    {
        $admin = $this->makeUser('staffbox_bulkdel_admin', User::CLASS_ADMINISTRATOR);
        $sender = $this->makeUser('staffbox_bulkdel_sender', User::CLASS_USER);
        $id1 = $this->makeStaffMessage($sender->id, 'Bulk delete 1');
        $id2 = $this->makeStaffMessage($sender->id, 'Bulk delete 2');

        $this->callStaffBox($admin, 'POST', '', [
            'action' => 'takecontactanswered',
            'delete' => 'Delete',
            'setanswered' => [$id1, $id2],
        ])->assertRedirect();

        $this->assertNull(DB::table('staffmessages')->where('id', $id1)->first());
        $this->assertNull(DB::table('staffmessages')->where('id', $id2)->first());
    }

    public function testBulkActionRequiresSelection()
    {
        $admin = $this->makeUser('staffbox_nosel_admin', User::CLASS_ADMINISTRATOR);

        $this->callStaffBox($admin, 'POST', '', [
            'action' => 'takecontactanswered',
            'setdealt' => 'Set Answered',
        ])->assertStatus(400);
    }
}