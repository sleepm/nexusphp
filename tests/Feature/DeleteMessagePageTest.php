<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the deletemessage.php migration
 * (DeleteMessageController::web) against a real database.
 */
class DeleteMessagePageTest extends TestCase
{
    protected array $createdUserIds = [];
    protected array $createdMessageIds = [];

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
        if ($this->createdMessageIds !== []) {
            DB::table('messages')->whereIn('id', $this->createdMessageIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $name, int $class = User::CLASS_USER): User
    {
        $user = User::query()->create([
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
        ]);
        $this->createdUserIds[] = $user->id;

        return $user;
    }

    private function makeMessage(array $attributes = []): Message
    {
        $message = Message::query()->create(array_merge([
            'sender' => 0,
            'receiver' => 1,
            'added' => now(),
            'subject' => 'Test subject',
            'msg' => 'Test body',
            'unread' => 'yes',
            'location' => 1,
            'saved' => 'no',
        ], $attributes));
        $this->createdMessageIds[] = $message->id;

        return $message;
    }

    private function cookieFor(User $user): string
    {
        $tokenJson = json_encode(['user_id' => $user->id, 'expires' => time() + 3600]);
        $signature = hash_hmac('sha256', $tokenJson, $user->auth_key);
        return base64_encode($tokenJson . '.' . $signature);
    }

    private function deleteMessage(User $user, string $query)
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/deletemessage.php' . $query);
    }

    // ------------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->get('/deletemessage.php?id=1&type=in')->assertRedirect();
    }

    // ------------------------------------------------------------ validation

    public function testInvalidIdsRejected()
    {
        $user = $this->makeUser('deletemsg_invalid');

        $this->deleteMessage($user, '?id=abc&type=in')->assertStatus(400);
        $this->deleteMessage($user, '?id=0&type=in')->assertStatus(400);
        $this->deleteMessage($user, '?id=-5&type=in')->assertStatus(400);
        $this->deleteMessage($user, '?id=1.5&type=in')->assertStatus(400);
    }

    public function testUnknownTypeRejected()
    {
        $user = $this->makeUser('deletemsg_notype');
        $message = $this->makeMessage(['receiver' => $user->id]);

        $this->deleteMessage($user, '?id=' . $message->id . '&type=x')->assertStatus(400);
    }

    public function testMissingMessageRejected()
    {
        $user = $this->makeUser('deletemsg_missing');

        $this->deleteMessage($user, '?id=999999999&type=in')->assertStatus(400);
    }

    // ------------------------------------------------------------- inbox flow

    public function testDeleteFromInbox()
    {
        $user = $this->makeUser('deletemsg_inbox');
        $message = $this->makeMessage(['receiver' => $user->id, 'location' => 1]);

        $this->deleteMessage($user, '?id=' . $message->id . '&type=in')
            ->assertRedirect();

        $this->assertNull(DB::table('messages')->where('id', $message->id)->first());
    }

    public function testDeleteInboxMessagesNotOwnedRejected()
    {
        $owner = $this->makeUser('deletemsg_owner');
        $thief = $this->makeUser('deletemsg_thief');
        $message = $this->makeMessage(['receiver' => $owner->id, 'location' => 1]);

        $this->deleteMessage($thief, '?id=' . $message->id . '&type=in')->assertStatus(403);
    }

    public function testUnPromoteBothInbox()
    {
        $user = $this->makeUser('deletemsg_both_in');
        $message = $this->makeMessage(['receiver' => $user->id, 'location' => 0]);

        $this->deleteMessage($user, '?id=' . $message->id . '&type=in')->assertRedirect();

        $this->assertSame(-1, DB::table('messages')->where('id', $message->id)->value('location'));
    }

    public function testInboxMessageNotInInboxRejected()
    {
        $user = $this->makeUser('deletemsg_out_in');
        $message = $this->makeMessage(['receiver' => $user->id, 'location' => 2]);

        $this->deleteMessage($user, '?id=' . $message->id . '&type=in')->assertStatus(400);
    }

    // ------------------------------------------------------------- sent flow

    public function testDeleteFromSentbox()
    {
        $user = $this->makeUser('deletemsg_sent');
        $message = $this->makeMessage(['sender' => $user->id, 'location' => -1]);

        $this->deleteMessage($user, '?id=' . $message->id . '&type=out')
            ->assertRedirect();

        $this->assertNull(DB::table('messages')->where('id', $message->id)->first());
    }

    public function testDeleteSentMessagesNotOwnedRejected()
    {
        $owner = $this->makeUser('deletemsg_sent_owner');
        $thief = $this->makeUser('deletemsg_sent_thief');
        $message = $this->makeMessage(['sender' => $owner->id, 'location' => -1]);

        $this->deleteMessage($thief, '?id=' . $message->id . '&type=out')->assertStatus(403);
    }

    public function testUnPromoteBothSentbox()
    {
        $user = $this->makeUser('deletemsg_both_out');
        $message = $this->makeMessage(['sender' => $user->id, 'location' => 0]);

        $this->deleteMessage($user, '?id=' . $message->id . '&type=out')->assertRedirect();

        $this->assertSame(1, DB::table('messages')->where('id', $message->id)->value('location'));
    }
}