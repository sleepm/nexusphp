<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the takeamountupload.php migration
 * (TorrentController::webTakeAmountUpload) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class TakeAmountUploadPageTest extends TestCase
{
    private array $createdUserIds = [];

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
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('messages')->whereIn('receiver', $ids)->delete();
            DB::table('users')->whereIn('id', $ids)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $username, $class = User::CLASS_POWER_USER, array $overrides = []): User
    {
        $user = User::query()->create(array_merge([
            'username' => $username,
            'passhash' => str_repeat('a', 32),
            'secret' => 'secret',
            'auth_key' => 'authkey_' . $username,
            'email' => $username . '@example.com',
            'status' => 'confirmed',
            'enabled' => 'yes',
            'class' => $class,
            'passkey' => md5($username),
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
        ], $overrides));
        $this->createdUserIds[] = $user->id;

        return $user;
    }

    private function cookieFor(User $user): string
    {
        $tokenJson = json_encode(['user_id' => $user->id, 'expires' => time() + 3600]);
        $signature = hash_hmac('sha256', $tokenJson, $user->auth_key);
        return base64_encode($tokenJson . '.' . $signature);
    }

    private function postTakeAmountUpload(User $user, array $data)
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->post('/takeamountupload.php', $data);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'sender' => 'self',
            'amount' => '2',
            'msg' => 'Thank you for uploading.',
            'subject' => 'Upload credit granted',
            'clases' => [User::CLASS_POWER_USER],
        ], $overrides);
    }

    // ------------------------------------------------------------------ auth

    public function testTakeAmountUploadRequiresLogin()
    {
        $this->post('/takeamountupload.php', $this->validPayload())->assertRedirect();
    }

    // ----------------------------------------------------------- permission

    public function testTakeAmountUploadRejectsNonSysop()
    {
        $admin = $this->makeUser('takeamount_mod', User::CLASS_ADMINISTRATOR);

        $this->postTakeAmountUpload($admin, $this->validPayload())->assertForbidden();
    }

    // ------------------------------------------------------------ validation

    public function testTakeAmountUploadRejectsBlankFields()
    {
        $sysop = $this->makeUser('takeamount_blank_sysop', User::CLASS_SYSOP);

        $response = $this->postTakeAmountUpload($sysop, $this->validPayload([
            'msg' => '',
            'amount' => '',
        ]));
        $response->assertRedirect();
        $this->assertStringContainsString('/error', $response->headers->get('Location'));
    }

    public function testTakeAmountUploadRejectsNonNumericAmount()
    {
        $sysop = $this->makeUser('takeamount_alpha_sysop', User::CLASS_SYSOP);

        $response = $this->postTakeAmountUpload($sysop, $this->validPayload(['amount' => 'abc']));
        $response->assertRedirect();
        $this->assertStringContainsString('/error', $response->headers->get('Location'));
    }

    public function testTakeAmountUploadRejectsInvalidClass()
    {
        $sysop = $this->makeUser('takeamount_badclass_sysop', User::CLASS_SYSOP);

        $response = $this->postTakeAmountUpload($sysop, $this->validPayload(['clases' => ['abc']]));
        $response->assertRedirect();
        $this->assertStringContainsString('/error', $response->headers->get('Location'));
    }

    public function testTakeAmountUploadRejectsNoClass()
    {
        $sysop = $this->makeUser('takeamount_noclass_sysop', User::CLASS_SYSOP);

        $response = $this->postTakeAmountUpload($sysop, $this->validPayload(['clases' => []]));
        $response->assertRedirect();
        $this->assertStringContainsString('/error', $response->headers->get('Location'));
    }

    // -------------------------------------------------------------- success

    public function testTakeAmountUploadAddsUploadToClassUsers()
    {
        $sysop = $this->makeUser('takeamount_ok_sysop', User::CLASS_SYSOP);
        $target = $this->makeUser('takeamount_ok_target', User::CLASS_POWER_USER);
        $otherClass = $this->makeUser('takeamount_ok_other', User::CLASS_USER);

        $response = $this->postTakeAmountUpload($sysop, $this->validPayload([
            'amount' => '2',
            'msg' => 'Thank you for uploading.',
            'subject' => 'Upload credit granted',
        ]));
        $response->assertRedirect();
        $this->assertStringContainsString('/amountupload.php?sent=1', $response->headers->get('Location'));

        $this->assertEquals(2 * 1073741824, DB::table('users')->where('id', $target->id)->value('uploaded'));
        $this->assertEquals(0, DB::table('users')->where('id', $otherClass->id)->value('uploaded'));

        $message = DB::table('messages')
            ->where('receiver', $target->id)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($message);
        $this->assertEquals($sysop->id, $message->sender);
        $this->assertEquals('Upload credit granted', $message->subject);
        $this->assertStringContainsString('Thank you for uploading.', $message->msg);
        $this->assertNull(DB::table('messages')->where('receiver', $otherClass->id)->first());
    }

    public function testTakeAmountUploadSystemSender()
    {
        $sysop = $this->makeUser('takeamount_sys_sysop', User::CLASS_SYSOP);
        $target = $this->makeUser('takeamount_sys_target', User::CLASS_POWER_USER);

        $this->postTakeAmountUpload($sysop, $this->validPayload(['sender' => 'system']))
            ->assertRedirect();

        $this->assertEquals(2 * 1073741824, DB::table('users')->where('id', $target->id)->value('uploaded'));
        $message = DB::table('messages')
            ->where('receiver', $target->id)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($message);
        $this->assertEquals(0, $message->sender);
    }
}
