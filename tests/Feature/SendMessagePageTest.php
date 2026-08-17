<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the sendmessage.php migration
 * (MessageController::webSendMessage) against a real database.
 */
class SendMessagePageTest extends TestCase
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
            'deletepms' => 'no',
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

    protected function requestAs(User $user, string $uri)
    {
        app('auth')->forgetGuards();
        return $this->withCookie('c_secure_pass', $this->cookieFor($user))->get($uri);
    }

    protected function tearDown(): void
    {
        Message::query()->truncate();
        if ($this->createdUserIds !== []) {
            DB::table('messages')->whereIn('receiver', $this->createdUserIds)->delete();
            DB::table('messages')->whereIn('sender', $this->createdUserIds)->delete();
            DB::table('pmboxes')->whereIn('userid', $this->createdUserIds)->delete();
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    public function testSendMessageRequiresLogin()
    {
        $this->get('/sendmessage.php?receiver=1')->assertRedirect();
    }

    public function testComposeFormRendersForReceiver()
    {
        $user = $this->makeUser('sendmsg_sender');
        $target = $this->makeUser('sendmsg_receiver');

        $this->requestAs($user, '/sendmessage.php?receiver=' . $target->id)
            ->assertOk()
            ->assertSee('action=takemessage.php', false)
            ->assertSee('name=receiver value="' . $target->id . '"', false)
            ->assertSee('name="subject"', false)
            ->assertSee("name='save' value='yes'", false);
    }

    public function testInvalidReceiverRejected()
    {
        $user = $this->makeUser('sendmsg_badreceiver');

        $this->requestAs($user, '/sendmessage.php?receiver=abc')->assertStatus(403);
        $this->requestAs($user, '/sendmessage.php?receiver=0')->assertStatus(403);
    }

    public function testMissingUserNotFound()
    {
        $user = $this->makeUser('sendmsg_missing');

        $this->requestAs($user, '/sendmessage.php?receiver=999999999')->assertStatus(404);
    }

    public function testReplyFormPrefillsSubjectAndBody()
    {
        $user = $this->makeUser('sendmsg_replier');
        $sender = $this->makeUser('sendmsg_orig');
        $message = Message::query()->create([
            'sender' => $sender->id,
            'receiver' => $user->id,
            'added' => now(),
            'subject' => 'Original subject',
            'msg' => 'Original body',
            'unread' => 'yes',
            'location' => 1,
            'saved' => 'no',
        ]);

        $this->requestAs($user, '/sendmessage.php?receiver=' . $sender->id . '&replyto=' . $message->id)
            ->assertOk()
            ->assertSee('Re: Original subject', false)
            ->assertSee('Original body', false)
            ->assertSee('name=origmsg value="' . $message->id . '"', false);
    }

    public function testReReplySubjectCountsReplies()
    {
        $user = $this->makeUser('sendmsg_rereply');
        $sender = $this->makeUser('sendmsg_orig2');
        $message = Message::query()->create([
            'sender' => $sender->id,
            'receiver' => $user->id,
            'added' => now(),
            'subject' => 'Re(2): Thread subject',
            'msg' => 'Second reply body',
            'unread' => 'yes',
            'location' => 1,
            'saved' => 'no',
        ]);

        $this->requestAs($user, '/sendmessage.php?receiver=' . $sender->id . '&replyto=' . $message->id)
            ->assertOk()
            ->assertSee('Re(3): Thread subject', false);
    }

    public function testReplyToSomeoneElsesMessageRejected()
    {
        $user = $this->makeUser('sendmsg_intruder');
        $owner = $this->makeUser('sendmsg_owner');
        $sender = $this->makeUser('sendmsg_sender2');
        $message = Message::query()->create([
            'sender' => $sender->id,
            'receiver' => $owner->id,
            'added' => now(),
            'subject' => 'Private',
            'msg' => 'Not yours',
            'unread' => 'yes',
            'location' => 1,
            'saved' => 'no',
        ]);

        $this->requestAs($user, '/sendmessage.php?receiver=' . $sender->id . '&replyto=' . $message->id)
            ->assertStatus(403);
    }
}
