<?php

namespace Tests\Feature;

use App\Models\BonusLogs;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the bonus-log.php migration
 * (BonusLogController::web) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class BonusLogPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdLogIds = [];

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
        if ($this->createdLogIds !== []) {
            DB::table('bonus_logs')->whereIn('id', $this->createdLogIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('bonus_logs')->whereIn('uid', $ids)->delete();
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

    private function getBonusLog(User $user, string $query = '')
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/bonus-log.php' . $query);
    }

    private function addBonusLog(int $uid, int $businessType, float $old, float $delta, float $new, string $comment = '', string $createdAt = '2026-08-01 10:00:00'): int
    {
        $id = DB::table('bonus_logs')->insertGetId([
            'uid' => $uid,
            'business_type' => $businessType,
            'old_total_value' => $old,
            'value' => $delta,
            'new_total_value' => $new,
            'comment' => $comment,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        $this->createdLogIds[] = $id;

        return $id;
    }

    // ------------------------------------------------------------------ auth

    public function testBonusLogRequiresLogin()
    {
        $this->get('/bonus-log.php')->assertRedirect();
    }

    // -------------------------------------------------------------- rendering

    public function testBonusLogRendersOwnLog()
    {
        $user = $this->makeUser('bonuslog_viewer');
        $this->addBonusLog($user->id, BonusLogs::BUSINESS_TYPE_EXCHANGE_UPLOAD, 300, 30, 270, 'buy upload', '2026-08-01 10:00:00');

        $this->getBonusLog($user)
            ->assertOk()
            ->assertSee('User bonus details')
            ->assertSee('Exchange uploaded')
            ->assertSee('buy upload')
            ->assertSee('id="bonus-log-table"', false)
            ->assertSee('name="category"', false)
            ->assertSee('name="business_type"', false);
    }

    public function testBonusLogRendersEmptyList()
    {
        $user = $this->makeUser('bonuslog_empty');

        $this->getBonusLog($user)
            ->assertOk()
            ->assertSee('User bonus details')
            ->assertSee('id="bonus-log-table"', false);
    }

    public function testBonusLogShowsPositiveAndNegativeValues()
    {
        $user = $this->makeUser('bonuslog_values');
        $this->addBonusLog($user->id, BonusLogs::BUSINESS_TYPE_EXCHANGE_UPLOAD, 300, 30, 270, 'spend');
        $this->addBonusLog($user->id, BonusLogs::BUSINESS_TYPE_RECEIVE_GIFT, 270, 50, 320, 'gift');

        $response = $this->getBonusLog($user)->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('-30.0', $html);
        $this->assertStringContainsString('+50.0', $html);
    }

    public function testBonusLogFiltersByBusinessType()
    {
        $user = $this->makeUser('bonuslog_filter');
        $this->addBonusLog($user->id, BonusLogs::BUSINESS_TYPE_EXCHANGE_UPLOAD, 300, 30, 270, 'spend upload');
        $this->addBonusLog($user->id, BonusLogs::BUSINESS_TYPE_RECEIVE_GIFT, 270, 50, 320, 'receive gift');

        $this->getBonusLog($user, '?uid=' . $user->id . '&category=common&business_type=' . BonusLogs::BUSINESS_TYPE_EXCHANGE_UPLOAD)
            ->assertOk()
            ->assertSee('spend upload')
            ->assertDontSee('receive gift');
    }

    public function testBonusLogRejectsInvalidUid()
    {
        $user = $this->makeUser('bonuslog_bad_uid');

        $this->getBonusLog($user, '?uid=99999999')->assertStatus(404);
    }

    public function testBonusLogRejectsInvalidCategory()
    {
        $user = $this->makeUser('bonuslog_bad_category');

        $this->getBonusLog($user, '?uid=' . $user->id . '&category=hack')->assertStatus(404);
    }

    public function testBonusLogRejectsInvalidBusinessType()
    {
        $user = $this->makeUser('bonuslog_bad_business');

        $this->getBonusLog($user, '?uid=' . $user->id . '&category=common&business_type=12345')->assertStatus(404);
    }

    // ------------------------------------------------------------- permission

    public function testBonusLogRejectsOtherUserWithoutPermission()
    {
        $viewer = $this->makeUser('bonuslog_other_viewer', User::CLASS_USER);
        $target = $this->makeUser('bonuslog_other_target', User::CLASS_USER);

        $this->getBonusLog($viewer, '?uid=' . $target->id)->assertStatus(403);
    }

    public function testBonusLogAllowsOtherUserWithPermission()
    {
        $viewer = $this->makeUser('bonuslog_veteran_viewer', User::CLASS_VETERAN_USER);
        $target = $this->makeUser('bonuslog_veteran_target', User::CLASS_USER);
        $this->addBonusLog($target->id, BonusLogs::BUSINESS_TYPE_RECEIVE_GIFT, 100, 10, 110, 'a gift');

        $this->getBonusLog($viewer, '?uid=' . $target->id)
            ->assertOk()
            ->assertSee('a gift');
    }
}
