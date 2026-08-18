<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the downloadnotice.php migration
 * (DownloadNoticeController::web) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes the users this test created.
 */
class DownloadNoticePageTest extends TestCase
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
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $username, array $overrides = []): User
    {
        $user = User::query()->create(array_merge([
            'username' => $username,
            'passhash' => str_repeat('a', 32),
            'secret' => 'secret',
            'auth_key' => 'authkey_' . $username,
            'email' => $username . '@example.com',
            'status' => 'confirmed',
            'enabled' => 'yes',
            'class' => User::CLASS_USER,
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
            'showdlnotice' => 1,
            'showclienterror' => 'no',
        ], $overrides));
        $this->createdUserIds[] = $user->id;

        DB::table('users')->where('id', $user->id)->update([
            'showdlnotice' => (int) ($overrides['showdlnotice'] ?? 1),
        ]);

        return $user;
    }

    private function cookieFor(User $user): string
    {
        $tokenJson = json_encode(['user_id' => $user->id, 'expires' => time() + 3600]);
        $signature = hash_hmac('sha256', $tokenJson, $user->auth_key);
        return base64_encode($tokenJson . '.' . $signature);
    }

    private function requestNotice(User $user, string $method, string $uri, array $data = [])
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->{$method}($uri, $data);
    }

    // ------------------------------------------------------------------ auth

    public function testNoticeRequiresLogin()
    {
        $this->get('/downloadnotice.php')->assertRedirect();
    }

    // -------------------------------------------------------------- rendering

    public function testFirstTimeRendersRatioAndClientNotice()
    {
        $user = $this->makeUser('notice_firsttime_user');

        $response = $this->requestNotice($user, 'get', '/downloadnotice.php?torrentid=7&type=firsttime');
        $response->assertOk()
            ->assertSee('First-time download notice')
            ->assertSee('This is a Private Tracker with ratio requirement')
            ->assertSee('Only use allowed BitTorrent clients')
            ->assertSee("Don't show this notice again.", false)
            ->assertSee("Download The Torrent")
            ->assertSee('pic/ratio.png', false)
            ->assertSee('pic/qbittorrent.png', false)
            ->assertSee('pic/transmission.png', false)
            ->assertSee('<input type="hidden" name="id" value="7" />', false)
            ->assertSee('<input type="hidden" name="type" value="firsttime" />', false)
            ->assertSee('checked="checked"', false)
            ->assertSee('id="continuedownload"', false);
    }

    public function testClientNoticeSkipsRatioBlock()
    {
        $user = $this->makeUser('notice_client_user');

        $response = $this->requestNotice($user, 'get', '/downloadnotice.php?torrentid=7&type=client');
        $response->assertOk()
            ->assertSee('Client banned notice')
            ->assertSee('Only use allowed BitTorrent clients')
            ->assertDontSee('This is a Private Tracker with ratio requirement');
    }

    public function testRatioNoticeShowsImproveRatioCheckbox()
    {
        $user = $this->makeUser('notice_ratio_user', [
            'leechwarn' => 'yes',
            'leechwarnuntil' => '2099-11-11 00:00:00',
        ]);

        $response = $this->requestNotice($user, 'get', '/downloadnotice.php?torrentid=7&type=ratio');
        $response->assertOk()
            ->assertSee('Low ratio notice')
            ->assertSee('WARNING', false)
            ->assertSee('This notice will always show until you have improved your ratio.')
            ->assertSee('name="letmedown"', false)
            ->assertSee('id="continuedownload"', false)
            ->assertSee('disabled="disabled"', false);
    }

    // --------------------------------------------------------------- submit

    public function testFirstTimeHideNoticeDisablesNotice()
    {
        $user = $this->makeUser('notice_firsttime_hide');

        $response = $this->requestNotice($user, 'post', '/downloadnotice.php', [
            'id' => 11,
            'type' => 'firsttime',
            'hidenotice' => 1,
        ]);
        $response->assertRedirect('/download.php?id=11&letdown=1');
        $this->assertSame(0, (int) DB::table('users')->where('id', $user->id)->value('showdlnotice'));
    }

    public function testFirstTimeWithoutHideNoticeKeepsNotice()
    {
        $user = $this->makeUser('notice_firsttime_nohide');

        $this->requestNotice($user, 'post', '/downloadnotice.php', [
            'id' => 11,
            'type' => 'firsttime',
        ])->assertRedirect('/download.php?id=11&letdown=1');
        $this->assertSame(1, (int) DB::table('users')->where('id', $user->id)->value('showdlnotice'));
    }

    public function testClientHideNoticeClearsClientError()
    {
        $user = $this->makeUser('notice_client_hide', ['showclienterror' => 'yes']);

        $this->requestNotice($user, 'post', '/downloadnotice.php', [
            'id' => 11,
            'type' => 'client',
            'hidenotice' => 1,
        ])->assertRedirect('/download.php?id=11&letdown=1');
        $this->assertSame('no', DB::table('users')->where('id', $user->id)->value('showclienterror'));
    }

    public function testRatioSubmitRedirectsWithoutUpdatingUser()
    {
        $user = $this->makeUser('notice_ratio_hide', [
            'leechwarn' => 'yes',
            'leechwarnuntil' => '2099-11-11 00:00:00',
            'showdlnotice' => 0,
            'showclienterror' => 'yes',
        ]);

        $this->requestNotice($user, 'post', '/downloadnotice.php', [
            'id' => 11,
            'type' => 'ratio',
            'hidenotice' => 1,
        ])->assertRedirect('/download.php?id=11&letdown=1');

        $row = DB::table('users')->where('id', $user->id)->first();
        $this->assertSame(0, (int) $row->showdlnotice);
        $this->assertSame('yes', $row->showclienterror);
    }

    public function testSubmitRejectsInvalidType()
    {
        $user = $this->makeUser('notice_invalid_type');

        $this->requestNotice($user, 'post', '/downloadnotice.php', [
            'id' => 11,
            'type' => 'hack',
        ])->assertStatus(400);
    }

    public function testSubmitRejectsMissingId()
    {
        $user = $this->makeUser('notice_missing_id');

        $this->requestNotice($user, 'post', '/downloadnotice.php', [
            'id' => 0,
            'type' => 'firsttime',
        ])->assertStatus(400);
    }
}