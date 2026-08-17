<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the takemessage.php migration
 * (MessageController::webTakeMessage) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown removes everything this test created.
 */
class TakeMessagePageTest extends TestCase
{
    protected array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        // legacy compose/forward forms post without a CSRF token
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

    protected function tearDown(): void
    {
        Message::query()->truncate();
        DB::table('blocks')->delete();
        DB::table('friends')->delete();
        if ($this->createdUserIds !== []) {
            DB::table('messages')->whereIn('receiver', $this->createdUserIds)->delete();
            DB::table('messages')->whereIn('sender', $this->createdUserIds)->delete();
            DB::table('blocks')->whereIn('userid', $this->createdUserIds)->delete();
            DB::table('friends')->whereIn('userid', $this->createdUserIds)->delete();
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    public function testRequiresLogin()
    {
        $this->get('/takemessage.php')->assertRedirect();
        $this->post('/takemessage.php', ['receiver' => 1, 'body' => 'hi'])->assertRedirect();
    }

    public function testGetMethodRejected()
    {
        $user = $this->makeUser('take_get');
        $this->requestAs($user, 'get', '/takemessage.php')->assertStatus(403);
    }

    public function testSendMessageCreatesAndRedirects()
    {
        $sender = $this->makeUser('take_sender');
        $receiver = $this->makeUser('take_receiver');

        $this->requestAs($sender, 'post', '/takemessage.php', [
            'receiver' => $receiver->id,
            'subject' => 'Hello',
            'body' => 'How are you?',
            'save' => 'yes',
            'returnto' => 'messages.php',
        ])->assertRedirect('http://localhost/messages.php');

        $msg = Message::query()->where('receiver', $receiver->id)->first();
        $this->assertNotNull($msg);
        $this->assertSame($sender->id, (int) $msg->sender);
        $this->assertSame('How are you?', $msg->msg);
        $this->assertSame('Hello', $msg->subject);
        $this->assertSame('yes', $msg->saved);
        $this->assertSame(1, (int) $msg->location);
    }

    public function testSendMessageWithoutBodyRejected()
    {
        $sender = $this->makeUser('take_nobody');
        $receiver = $this->makeUser('take_nobody_rcv');

        $this->requestAs($sender, 'post', '/takemessage.php', [
            'receiver' => $receiver->id,
            'body' => '',
        ])->assertStatus(400);
    }

    public function testSendMessageToNonExistentUserRejected()
    {
        $sender = $this->makeUser('take_nouser');

        $this->requestAs($sender, 'post', '/takemessage.php', [
            'receiver' => 999999,
            'body' => 'Hello?',
        ])->assertStatus(400);
    }

    public function testSendMessageToParkedUserRejected()
    {
        $sender = $this->makeUser('take_parked');
        $receiver = $this->makeUser('take_parked_rcv');
        User::query()->where('id', $receiver->id)->update(['parked' => 'yes']);

        $this->requestAs($sender, 'post', '/takemessage.php', [
            'receiver' => $receiver->id,
            'body' => 'Hello?',
        ])->assertStatus(403);
    }

    public function testSendMessageToBlockingUserRejected()
    {
        $sender = $this->makeUser('take_blocked');
        $receiver = $this->makeUser('take_blocked_rcv');
        DB::table('blocks')->insert(['userid' => $receiver->id, 'blockid' => $sender->id]);

        $this->requestAs($sender, 'post', '/takemessage.php', [
            'receiver' => $receiver->id,
            'body' => 'Hello?',
        ])->assertStatus(403);
    }

    public function testSendMessageToFriendsOnlyUserRejectedWhenNotFriend()
    {
        $sender = $this->makeUser('take_friend_none');
        $receiver = $this->makeUser('take_friend_none_rcv');
        User::query()->where('id', $receiver->id)->update(['acceptpms' => 'friends']);

        $this->requestAs($sender, 'post', '/takemessage.php', [
            'receiver' => $receiver->id,
            'body' => 'Hello?',
        ])->assertStatus(403);
    }

    public function testSendMessageToFriendsOnlyUserAcceptedWhenFriend()
    {
        $sender = $this->makeUser('take_friend_yes');
        $receiver = $this->makeUser('take_friend_yes_rcv');
        User::query()->where('id', $receiver->id)->update(['acceptpms' => 'friends']);
        DB::table('friends')->insert(['userid' => $receiver->id, 'friendid' => $sender->id]);

        $this->requestAs($sender, 'post', '/takemessage.php', [
            'receiver' => $receiver->id,
            'body' => 'Hi friend',
            'returnto' => 'messages.php',
        ])->assertRedirect('http://localhost/messages.php');

        $this->assertNotNull(Message::query()->where('receiver', $receiver->id)->first());
    }

    public function testSendMessageToUserBlockingAllPmsRejected()
    {
        $sender = $this->makeUser('take_noaccept');
        $receiver = $this->makeUser('take_noaccept_rcv');
        User::query()->where('id', $receiver->id)->update(['acceptpms' => 'no']);

        $this->requestAs($sender, 'post', '/takemessage.php', [
            'receiver' => $receiver->id,
            'body' => 'Hello?',
        ])->assertStatus(403);
    }

    public function testFloodControlRejectsRapidSend()
    {
        $sender = $this->makeUser('take_flood');
        $receiver = $this->makeUser('take_flood_rcv');
        User::query()->where('id', $sender->id)->update(['last_pm' => now()->subSeconds(5)]);

        $this->requestAs($sender, 'post', '/takemessage.php', [
            'receiver' => $receiver->id,
            'body' => 'Too fast',
        ])->assertStatus(429);
    }

    public function testSuccessPageRenderedWithoutReturnto()
    {
        $sender = $this->makeUser('take_success');
        $receiver = $this->makeUser('take_success_rcv');

        $this->requestAs($sender, 'post', '/takemessage.php', [
            'receiver' => $receiver->id,
            'body' => 'Plain message',
        ])->assertOk()->assertSee('successfully sent', false);
    }

    public function testForwardMessageBuildsQuotedBody()
    {
        $sender = $this->makeUser('take_fwd');
        $receiver = $this->makeUser('take_fwd_rcv');
        $orig = Message::query()->create([
            'sender' => 0,
            'receiver' => $sender->id,
            'added' => now(),
            'subject' => 'Fwd me',
            'msg' => 'Original body',
            'unread' => 'yes',
            'location' => 1,
            'saved' => 'no',
        ]);

        $this->requestAs($sender, 'post', '/takemessage.php', [
            'forward' => 1,
            'origmsg' => $orig->id,
            'to' => $receiver->username,
            'subject' => 'Fwd: Fwd me',
            'body' => 'Look at this',
            'returnto' => 'messages.php',
        ])->assertRedirect('http://localhost/messages.php');

        $msg = Message::query()->where('receiver', $receiver->id)->first();
        $this->assertNotNull($msg);
        $this->assertSame($sender->id, (int) $msg->sender);
        $this->assertStringContainsString('Original Message from', $msg->msg);
        $this->assertStringContainsString('Original body', $msg->msg);
        $this->assertStringContainsString('Wrote at', $msg->msg);
        $this->assertStringContainsString('Look at this', $msg->msg);
    }

    public function testForwardWithoutRecipientRejected()
    {
        $sender = $this->makeUser('take_fwd_no_to');
        $orig = Message::query()->create([
            'sender' => 0,
            'receiver' => $sender->id,
            'added' => now(),
            'subject' => 'Fwd me',
            'msg' => 'Original body',
            'saved' => 'no',
        ]);

        $this->requestAs($sender, 'post', '/takemessage.php', [
            'forward' => 1,
            'origmsg' => $orig->id,
        ])->assertStatus(400);
    }

    public function testForwardMessageNotOwnedRejected()
    {
        $sender = $this->makeUser('take_fwd_noperm');
        $other = $this->makeUser('take_fwd_owner');
        $orig = Message::query()->create([
            'sender' => 0,
            'receiver' => $other->id,
            'added' => now(),
            'subject' => 'Private',
            'msg' => 'Private body',
            'saved' => 'no',
        ]);

        $this->requestAs($sender, 'post', '/takemessage.php', [
            'forward' => 1,
            'origmsg' => $orig->id,
            'to' => $sender->username,
        ])->assertStatus(403);
    }

    public function testReplyWithDeleteRemovesUnsavedOriginal()
    {
        $sender = $this->makeUser('take_reply');
        $receiver = $this->makeUser('take_reply_rcv');
        $orig = Message::query()->create([
            'sender' => $receiver->id,
            'receiver' => $sender->id,
            'added' => now(),
            'subject' => 'Re: hi',
            'msg' => 'Your message',
            'unread' => 'yes',
            'location' => 1,
            'saved' => 'no',
        ]);

        $this->requestAs($sender, 'post', '/takemessage.php', [
            'receiver' => $receiver->id,
            'subject' => 'Re: hi',
            'body' => 'My reply',
            'origmsg' => $orig->id,
            'delete' => 'yes',
            'returnto' => 'messages.php',
        ])->assertRedirect('http://localhost/messages.php');

        $this->assertNull(Message::query()->find($orig->id));
        $this->assertNotNull(Message::query()->where('receiver', $receiver->id)->first());
    }

    public function testReplyWithDeleteHidesSavedOriginal()
    {
        $sender = $this->makeUser('take_reply_saved');
        $receiver = $this->makeUser('take_reply_saved_rcv');
        $orig = Message::query()->create([
            'sender' => $receiver->id,
            'receiver' => $sender->id,
            'added' => now(),
            'subject' => 'Re: hi',
            'msg' => 'Your message',
            'unread' => 'yes',
            'location' => 2,
            'saved' => 'yes',
        ]);

        $this->requestAs($sender, 'post', '/takemessage.php', [
            'receiver' => $receiver->id,
            'subject' => 'Re: hi',
            'body' => 'My reply',
            'origmsg' => $orig->id,
            'delete' => 'yes',
            'returnto' => 'messages.php',
        ])->assertRedirect();

        $this->assertSame(0, (int) Message::query()->find($orig->id)->location);
    }
}