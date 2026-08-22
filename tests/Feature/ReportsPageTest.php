<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportsPageTest extends TestCase
{
    private array $createdUserIds = [];
    private array $createdReportIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        // Remove any leftover reports from earlier test runs
        DB::table('reports')->truncate();
    }

    protected function tearDown(): void
    {
        if ($this->createdReportIds !== []) {
            DB::table('reports')->whereIn('id', $this->createdReportIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('users')->whereIn('id', $ids)->delete();
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

    private function getReports(User $user, string $query = '')
    {
        app('auth')->forgetGuards();
        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/reports.php' . $query);
    }

    private function postReports(User $user, array $data)
    {
        app('auth')->forgetGuards();
        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->post('/reports.php', $data);
    }

    private function makeReport(User $reporter, int $reportid = 1, string $type = 'user', string $reason = 'cheating'): int
    {
        $id = DB::table('reports')->insertGetId([
            'addedby' => $reporter->id,
            'reportid' => $reportid,
            'type' => $type,
            'reason' => $reason,
            'added' => now(),
            'dealtwith' => 0,
            'dealtby' => 0,
        ]);
        $this->createdReportIds[] = $id;
        return $id;
    }

    // ------------------------------------------------------------------ auth

    public function testReportsRequiresLogin(): void
    {
        $this->get('/reports.php')->assertRedirect();
    }

    public function testReportsRejectsBelowStaff(): void
    {
        $user = $this->makeUser('reports_plain_user', User::CLASS_USER);
        $this->getReports($user)->assertForbidden();
    }

    // -------------------------------------------------------------- rendering

    public function testReportsEmptyList(): void
    {
        $admin = $this->makeUser('reports_empty_admin', User::CLASS_ADMINISTRATOR);
        $this->getReports($admin)->assertOk()->assertSee('No report');
    }

    public function testReportsRendersRows(): void
    {
        $admin = $this->makeUser('reports_list_admin', User::CLASS_ADMINISTRATOR);
        $reporter = $this->makeUser('reports_reporter');
        $this->makeReport($reporter);

        $this->getReports($admin)
            ->assertOk()
            ->assertSee('cheating')
            ->assertSee('reports_reporter')
            ->assertSee('name="delreport[]"', false);
    }

    public function testReportsRendersTorrentReport(): void
    {
        $admin = $this->makeUser('reports_torrent_admin', User::CLASS_ADMINISTRATOR);
        $reporter = $this->makeUser('reports_torrent_reporter');
        $this->makeReport($reporter, 777, 'torrent', 'fake');

        $this->getReports($admin)
            ->assertOk()
            ->assertSee("Torrent doesn't exist or is deleted", false);
    }

    // ------------------------------------------------------------- bulk action

    public function testReportsSetDealt(): void
    {
        $admin = $this->makeUser('reports_setdealt_admin', User::CLASS_ADMINISTRATOR);
        $reporter = $this->makeUser('reports_setdealt_reporter');
        $reportId = $this->makeReport($reporter);

        $this->postReports($admin, ['delreport' => [$reportId], 'setdealt' => 'Set Dealt'])
            ->assertRedirect('/reports.php');
        $this->assertDatabaseHas('reports', [
            'id' => $reportId,
            'dealtwith' => 1,
            'dealtby' => $admin->id,
        ]);
    }

    public function testReportsDelete(): void
    {
        $admin = $this->makeUser('reports_delete_admin', User::CLASS_ADMINISTRATOR);
        $reporter = $this->makeUser('reports_delete_reporter');
        $reportId = $this->makeReport($reporter);

        $this->postReports($admin, ['delreport' => [$reportId], 'delete' => 'Delete'])
            ->assertRedirect('/reports.php');
        $this->assertDatabaseMissing('reports', ['id' => $reportId]);
    }

    public function testReportsEmptySelection(): void
    {
        $admin = $this->makeUser('reports_nosel_admin', User::CLASS_ADMINISTRATOR);
        $this->postReports($admin, ['setdealt' => 'Set Dealt'])
            ->assertOk()
            ->assertSee('Select at least one record');
    }
}