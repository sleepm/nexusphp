<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportPageTest extends TestCase
{
    private array $createdUserIds = [];
    private array $createdTorrentIds = [];
    private array $createdReportIds = [];
    private array $createdCommentIds = [];

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
        if ($this->createdReportIds !== []) {
            DB::table('reports')->whereIn('id', $this->createdReportIds)->delete();
        }
        if ($this->createdCommentIds !== []) {
            DB::table('comments')->whereIn('id', $this->createdCommentIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('users')->whereIn('id', $ids)->delete();
        }
        if ($this->createdTorrentIds !== []) {
            DB::table('torrents')->whereIn('id', $this->createdTorrentIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $username, $class = User::CLASS_USER, array $overrides = []): User
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

    private function getReport(User $user, string $query = '')
    {
        app('auth')->forgetGuards();
        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/report.php' . $query);
    }

    private function postReport(User $user, string $field, int $value, string $reason)
    {
        app('auth')->forgetGuards();
        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->post('/report.php', [$field => $value, 'reason' => $reason]);
    }

    public function testReportRequiresLogin(): void
    {
        $this->get('/report.php')->assertRedirect();
    }

    public function testReportInvalidAction(): void
    {
        $user = $this->makeUser('report_invalid_act');
        $this->getReport($user)->assertOk()->assertSee('Invalid action');
    }

    public function testReportTorrentShowsConfirmation(): void
    {
        $user = $this->makeUser('report_torrent');
        $torrentId = DB::table('torrents')->insertGetId([
            'name' => 'ReportMe Torrent',
            'filename' => 'reportme.torrent',
            'save_as' => 'reportme',
            'owner' => $user->id,
            'added' => now(),
            'size' => 1024,
            'numfiles' => 1,
            'visible' => 'yes',
        ]);
        $this->createdTorrentIds[] = $torrentId;

        $this->getReport($user, '?torrent=' . $torrentId)
            ->assertOk()
            ->assertSee('ReportMe Torrent')
            ->assertSee('name="taketorrent"', false);
    }

    public function testReportInvalidTorrent(): void
    {
        $user = $this->makeUser('report_badtorrent');
        $this->getReport($user, '?torrent=99999')->assertOk()->assertSee('Invalid Torrent Id');
    }

    public function testReportSelfUser(): void
    {
        $user = $this->makeUser('report_self');
        $this->getReport($user, '?user=' . $user->id)->assertOk()->assertSee('can not report yourself');
    }

    public function testReportUserShowsConfirmation(): void
    {
        $user = $this->makeUser('report_user_a');
        $target = $this->makeUser('report_user_b');
        $this->getReport($user, '?user=' . $target->id)->assertOk()->assertSee('name="takeuser"', false);
    }

    public function testReportStaffIsBlocked(): void
    {
        $user = $this->makeUser('report_user_c');
        $mod = $this->makeUser('report_mod', User::CLASS_MODERATOR);
        $this->getReport($user, '?user=' . $mod->id)->assertOk()->assertSee('can not report');
    }

    public function testReportPostSubmitCreatesReport(): void
    {
        $user = $this->makeUser('report_submit');
        $this->postReport($user, 'taketorrent', 12345, 'This is a bad torrent')
            ->assertOk()
            ->assertSee('Successfully reported');
        $reportId = DB::table('reports')->where('addedby', $user->id)->where('reportid', 12345)->value('id');
        $this->createdReportIds[] = $reportId;
        $this->assertDatabaseHas('reports', [
            'addedby' => $user->id,
            'reportid' => 12345,
            'type' => 'torrent',
            'reason' => 'This is a bad torrent',
        ]);
    }

    public function testReportMissingReason(): void
    {
        $user = $this->makeUser('report_noreason');
        $this->postReport($user, 'taketorrent', 12345, '')->assertOk()->assertSee('Missing Reason');
    }

    public function testReportDuplicateBlocked(): void
    {
        $user = $this->makeUser('report_duplicate');
        $existingReportId = DB::table('reports')->insertGetId([
            'addedby' => $user->id,
            'reportid' => 54321,
            'type' => 'torrent',
            'reason' => 'first',
            'added' => now(),
        ]);
        $this->createdReportIds[] = $existingReportId;
        $this->postReport($user, 'taketorrent', 54321, 'second')->assertOk()->assertSee('already reported');
    }

    public function testReportCommentShowsConfirmation(): void
    {
        $user = $this->makeUser('report_comment');
        $torrentId = DB::table('torrents')->insertGetId([
            'name' => 'Comment Torrent',
            'filename' => 'comment.torrent',
            'save_as' => 'comment',
            'owner' => $user->id,
            'added' => now(),
            'size' => 1024,
            'numfiles' => 1,
            'visible' => 'yes',
        ]);
        $this->createdTorrentIds[] = $torrentId;
        $commentId = DB::table('comments')->insertGetId([
            'user' => $user->id,
            'torrent' => $torrentId,
            'added' => now(),
            'text' => 'A comment',
        ]);
        $this->createdCommentIds[] = $commentId;
        $this->getReport($user, '?commentid=' . $commentId)
            ->assertOk()
            ->assertSee('name="takecommentid"', false);
    }

    public function testReportPostSubmit(): void
    {
        $user = $this->makeUser('report_submit_2');
        $this->postReport($user, 'takeuser', 999, 'Bad behavior')
            ->assertOk()
            ->assertSee('Successfully reported');
        $reportId = DB::table('reports')->where('addedby', $user->id)->where('reportid', 999)->value('id');
        $this->createdReportIds[] = $reportId;
        $this->assertDatabaseHas('reports', [
            'addedby' => $user->id,
            'reportid' => 999,
            'type' => 'user',
            'reason' => 'Bad behavior',
        ]);
    }
}