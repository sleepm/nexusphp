<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Pmbox;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the messages.php migration
 * (MessageController::web + actions) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown removes everything this test created.
 */
class MessagePageTest extends TestCase
{
    protected array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        // migrate action posts come from legacy forms without a CSRF token
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        // legacy site sends c_secure_pass etc. unencrypted (EncryptCookies except list)
        $this->disableCookieEncryption();
        // the local test DB has no activity_log table (only used in production)
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
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
            'pmnum' => 20,
            'savepms' => 'no',
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

    protected function makeMessage(array $attributes = []): Message
    {
        return Message::query()->create(array_merge([
            'sender' => 0,
            'receiver' => 1,
            'added' => now(),
            'subject' => 'Test subject',
            'msg' => 'Test body',
            'unread' => 'yes',
            'location' => 1,
            'saved' => 'no',
        ], $attributes));
    }

    protected function tearDown(): void
    {
        Pmbox::query()->truncate();
        Message::query()->truncate();
        if ($this->createdUserIds !== []) {
            DB::table('messages')->whereIn('receiver', $this->createdUserIds)->delete();
            DB::table('messages')->whereIn('sender', $this->createdUserIds)->delete();
            DB::table('pmboxes')->whereIn('userid', $this->createdUserIds)->delete();
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    public function testMessagesRequiresLogin()
    {
        $this->get('/messages.php')->assertRedirect();
    }

    public function testViewMailboxRendersForUser()
    {
        $user = $this->makeUser('messages_viewer');

        $this->requestAs($user, 'get', '/messages.php')
            ->assertOk()
            ->assertSee('messages.php?action=viewmailbox&box=-1', false)
            ->assertSee('Search Message', false)
            ->assertSee('No Messages.', false);
    }

    public function testViewMailboxListsInboxMessages()
    {
        $user = $this->makeUser('messages_inbox');
        $this->makeMessage([
            'receiver' => $user->id,
            'subject' => 'Hello inbox',
            'msg' => 'Welcome message body',
            'unread' => 'yes',
            'location' => 1,
        ]);

        $this->requestAs($user, 'get', '/messages.php')
            ->assertOk()
            ->assertSee('Hello inbox', false)
            ->assertSee('messages.php?action=viewmessage&id=', false);
    }

    public function testViewSentboxListsSentMessages()
    {
        $user = $this->makeUser('messages_sentbox');
        $this->makeMessage([
            'sender' => $user->id,
            'receiver' => 1,
            'subject' => 'My sent mail',
            'saved' => 'yes',
        ]);

        $this->requestAs($user, 'get', '/messages.php?action=viewmailbox&box=-1')
            ->assertOk()
            ->assertSee('My sent mail', false)
            ->assertSee('Sentbox', false);
    }

    public function testViewMailboxCustomBoxShowsName()
    {
        $user = $this->makeUser('messages_custombox');
        Pmbox::query()->create(['userid' => $user->id, 'boxnumber' => 2, 'name' => 'Archive']);

        $this->requestAs($user, 'get', '/messages.php?action=viewmailbox&box=2')
            ->assertOk()
            ->assertSee('Archive', false);
    }

    public function testViewMailboxInvalidBoxRejected()
    {
        $user = $this->makeUser('messages_badbox');

        $this->requestAs($user, 'get', '/messages.php?action=viewmailbox&box=42')
            ->assertStatus(403);
    }

    public function testViewMessageMarksRead()
    {
        $user = $this->makeUser('messages_viewmsg');
        $message = $this->makeMessage([
            'receiver' => $user->id,
            'subject' => 'Read me now',
            'msg' => 'Body content here',
            'unread' => 'yes',
        ]);

        $this->requestAs($user, 'get', '/messages.php?action=viewmessage&id=' . $message->id)
            ->assertOk()
            ->assertSee('Read me now', false)
            ->assertSee('Body content here', false);

        $this->assertSame('no', Message::query()->find($message->id)->unread);
    }

    public function testViewMessageWithoutPermissionRejected()
    {
        $user = $this->makeUser('messages_noperm');
        $other = $this->makeUser('messages_owner');
        $message = $this->makeMessage([
            'receiver' => $other->id,
            'subject' => 'Private',
        ]);

        $this->requestAs($user, 'get', '/messages.php?action=viewmessage&id=' . $message->id)
            ->assertStatus(403);
    }

    public function testSearchByKeywordFiltersMessages()
    {
        $user = $this->makeUser('messages_search');
        $this->makeMessage(['receiver' => $user->id, 'subject' => 'Findable unique subject']);
        $this->makeMessage(['receiver' => $user->id, 'subject' => 'Another subject']);

        $this->requestAs($user, 'get', '/messages.php?action=viewmailbox&keyword=Findable')
            ->assertOk()
            ->assertSee('Findable unique subject', false)
            ->assertDontSee('Another subject', false);
    }

    public function testUnreadFilter()
    {
        $user = $this->makeUser('messages_unread');
        $this->makeMessage(['receiver' => $user->id, 'subject' => 'Unread one', 'unread' => 'yes']);
        $this->makeMessage(['receiver' => $user->id, 'subject' => 'Read one', 'unread' => 'no']);

        $this->requestAs($user, 'get', '/messages.php?action=viewmailbox&unread=yes')
            ->assertOk()
            ->assertSee('Unread one', false)
            ->assertDontSee('Read one', false);
    }

    public function testMoveMessageToCustomBox()
    {
        $user = $this->makeUser('messages_move');
        Pmbox::query()->create(['userid' => $user->id, 'boxnumber' => 2, 'name' => 'Archive']);
        $message = $this->makeMessage(['receiver' => $user->id, 'subject' => 'Move me']);

        $this->requestAs($user, 'post', '/messages.php', [
            'action' => 'moveordel',
            'box' => 2,
            'move' => 'Move to',
            'messages' => [$message->id],
        ])->assertRedirect('http://localhost/messages.php?action=viewmailbox&box=2');

        $this->assertSame(2, (int) Message::query()->find($message->id)->location);
    }

    public function testMarkReadSingleMessage()
    {
        $user = $this->makeUser('messages_markread');
        $message = $this->makeMessage(['receiver' => $user->id, 'subject' => 'Mark me', 'unread' => 'yes']);

        $this->requestAs($user, 'post', '/messages.php', [
            'action' => 'moveordel',
            'box' => 1,
            'markread' => 'Mark as read',
            'messages' => [$message->id],
        ])->assertRedirect('http://localhost/messages.php?action=viewmailbox&box=1');

        $this->assertSame('no', Message::query()->find($message->id)->unread);
    }

    public function testDeleteMessageSingle()
    {
        $user = $this->makeUser('messages_delsingle');
        $message = $this->makeMessage(['receiver' => $user->id, 'subject' => 'Delete me']);

        $this->requestAs($user, 'post', '/messages.php', [
            'action' => 'moveordel',
            'delete' => 'Delete',
            'messages' => [$message->id],
        ])->assertRedirect('http://localhost/messages.php?action=viewmailbox');

        $this->assertNull(Message::query()->find($message->id));
    }

    public function testMoveOrdDelWithoutSelectionOnDeleteFails()
    {
        $user = $this->makeUser('messages_noempty');

        $this->requestAs($user, 'post', '/messages.php', [
            'action' => 'moveordel',
            'delete' => 'Delete',
        ])->assertStatus(400);
    }

    public function testForwardFormRenders()
    {
        $user = $this->makeUser('messages_forward');
        $message = $this->makeMessage(['receiver' => $user->id, 'subject' => 'Forward me']);

        $this->requestAs($user, 'get', '/messages.php?action=forward&id=' . $message->id)
            ->assertOk()
            ->assertSee('Fwd: Forward me', false)
            ->assertSee('name="forward" value="1"', false)
            ->assertSee('action="takemessage.php"', false);
    }

    public function testEditMailboxesRenders()
    {
        $user = $this->makeUser('messages_editboxes');

        $this->requestAs($user, 'get', '/messages.php?action=editmailboxes')
            ->assertOk()
            ->assertSee('action2" value="add"', false)
            ->assertSee('action2" value="edit"', false);
    }

    public function testAddMailbox()
    {
        $user = $this->makeUser('messages_addbox');

        $this->requestAs($user, 'get', '/messages.php?action=editmailboxes2&action2=add&new1=Work')
            ->assertRedirect('http://localhost/messages.php?action=editmailboxes');

        $box = Pmbox::query()->where('userid', $user->id)->first();
        $this->assertNotNull($box);
        $this->assertSame('Work', $box->name);
    }

    public function testEditMailboxName()
    {
        $user = $this->makeUser('messages_editbox');
        $box = Pmbox::query()->create(['userid' => $user->id, 'boxnumber' => 2, 'name' => 'Old name']);

        $this->requestAs($user, 'get', '/messages.php?action=editmailboxes2&action2=edit&edit' . $box->id . '=New name')
            ->assertRedirect('http://localhost/messages.php?action=editmailboxes');

        $this->assertSame('New name', Pmbox::query()->find($box->id)->name);
    }

    public function testDeleteMailboxByNameCleared()
    {
        $user = $this->makeUser('messages_delbox');
        $box = Pmbox::query()->create(['userid' => $user->id, 'boxnumber' => 2, 'name' => 'Old name']);

        $this->requestAs($user, 'get', '/messages.php?action=editmailboxes2&action2=edit&edit' . $box->id . '=')
            ->assertRedirect('http://localhost/messages.php?action=editmailboxes');

        $this->assertNull(Pmbox::query()->find($box->id));
    }

    public function testDeleteSingleMessageAction()
    {
        $user = $this->makeUser('messages_delaction');
        $message = $this->makeMessage(['receiver' => $user->id, 'subject' => 'Gone soon']);

        $this->requestAs($user, 'get', '/messages.php?action=deletemessage&id=' . $message->id)
            ->assertRedirect();

        $this->assertNull(Message::query()->find($message->id));
    }
}