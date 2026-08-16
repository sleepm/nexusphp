<?php

namespace Tests\Feature;

use App\Models\BonusLogs;
use App\Models\Message;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the mybonus.php migration
 * (BonusController::web / BonusController::webExchange) against a real
 * database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown(Correct) removes everything this test created.
 */
class BonusPageTest extends TestCase
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
            DB::table('bonus_logs')->whereIn('uid', $this->createdUserIds)->delete();
            DB::table('messages')->whereIn('receiver', $this->createdUserIds)->delete();
            DB::table('user_metas')->whereIn('uid', $this->createdUserIds)->delete();
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('users')->whereIn('id', $ids)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $username, $class = User::CLASS_CRAZY_USER, array $overrides = []): User
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
            'seedbonus' => 500000,
            'uploaded' => 0,
            'downloaded' => 0,
            'invites' => 0,
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

    private function requestAs(User $user, string $method, string $uri, array $data = [])
    {
        app('auth')->forgetGuards();
        if ($method === 'post') {
            return $this->withCookie('c_secure_pass', $this->cookieFor($user))->post($uri, $data);
        }
        return $this->withCookie('c_secure_pass', $this->cookieFor($user))->get($uri);
    }

    private function seedBonus(int $userId): int
    {
        return (int) DB::table('users')->where('id', $userId)->value('seedbonus');
    }

    private function setSettingAndFlush(string $name, $value): void
    {
        DB::table('settings')->where('name', $name)->update(['value' => $value]);
        Cache::flush();
        app('cache')->forget('nexus_settings_in_nexus');
    }

    // ------------------------------------------------------------------ auth

    public function testBonusRequiresLogin()
    {
        $this->get('/mybonus.php')->assertRedirect();
    }

    // -------------------------------------------------------------- rendering

    public function testBonusPageRendersForUser()
    {
        $user = $this->makeUser('bonus_viewer');

        $this->requestAs($user, 'get', '/mybonus.php')
            ->assertOk()
            ->assertSee('Karma Bonus Point System', false)
            ->assertSee('1.0 GB Uploaded', false)
            ->assertSee('name="option"', false)
            ->assertSee('What the hell are these Karma Bonus points', false)
            ->assertSeeText('10.0 GB Downloaded')
            ->assertSee('Exchange your Karma Bonus Points', false);
    }

    public function testBonusPageDisabledForSystem()
    {
        $user = $this->makeUser('bonus_disabled');
        $this->setSettingAndFlush('tweak.bonus', 'disable');

        $this->requestAs($user, 'get', '/mybonus.php')
            ->assertStatus(403);

        $this->setSettingAndFlush('tweak.bonus', 'enable');
    }

    // ------------------------------------------------------------- exchanges

    public function testExchangeUploadIncreasesUploadedAmount()
    {
        $user = $this->makeUser('bonus_exchange_upload');

        $this->requestAs($user, 'post', '/mybonus.php', ['option' => 0])
            ->assertRedirect('http://localhost/mybonus.php?do=upload');

        $this->assertSame(1073741824, (int) DB::table('users')->where('id', $user->id)->value('uploaded'));
        $this->assertSame(500000 - 300, $this->seedBonus($user->id));
        $this->assertSame(
            1,
            DB::table('bonus_logs')->where('uid', $user->id)->where('business_type', BonusLogs::BUSINESS_TYPE_EXCHANGE_UPLOAD)->count()
        );
    }

    public function testExchangeDownloadIncreasesDownloadedAmount()
    {
        $user = $this->makeUser('bonus_exchange_download');

        $this->requestAs($user, 'post', '/mybonus.php', ['option' => 4])
            ->assertRedirect('http://localhost/mybonus.php?do=download');

        $this->assertSame(10737418240, (int) DB::table('users')->where('id', $user->id)->value('downloaded'));
        $this->assertSame(500000 - 1000, $this->seedBonus($user->id));
    }

    public function testExchangeCustomTitle()
    {
        $user = $this->makeUser('bonus_exchange_title');

        $this->requestAs($user, 'post', '/mybonus.php', ['option' => 8, 'title' => 'SuperHero'])
            ->assertRedirect('http://localhost/mybonus.php?do=title');

        $this->assertSame('SuperHero', DB::table('users')->where('id', $user->id)->value('title'));
        $this->assertSame(500000 - 5000, $this->seedBonus($user->id));
    }

    public function testExchangeCustomTitleFiltersBadWords()
    {
        $user = $this->makeUser('bonus_exchange_bad_title');

        $this->requestAs($user, 'post', '/mybonus.php', ['option' => 8, 'title' => 'shit monster'])
            ->assertRedirect('http://localhost/mybonus.php?do=title');

        $this->assertSame('I just wasted my karma monster', DB::table('users')->where('id', $user->id)->value('title'));
    }

    public function testExchangeVipStatus()
    {
        $user = $this->makeUser('bonus_exchange_vip', User::CLASS_POWER_USER);

        $this->requestAs($user, 'post', '/mybonus.php', ['option' => 9])
            ->assertRedirect('http://localhost/mybonus.php?do=vip');

        $row = DB::table('users')->where('id', $user->id)->first(['class', 'vip_until']);
        $this->assertSame((int) User::CLASS_VIP, (int) $row->class);
        $this->assertNotNull($row->vip_until);
        $this->assertSame(500000 - 8000, $this->seedBonus($user->id));
    }

    public function testExchangeKarmaGift()
    {
        $giver = $this->makeUser('bonus_gift_giver');
        $receiver = $this->makeUser('bonus_gift_receiver', User::CLASS_USER, ['seedbonus' => 50]);

        // option 10 = gift_1 (min 100); taxpercentage=10, basictax=4 -> 500 -> 446
        $this->requestAs($giver, 'post', '/mybonus.php', [
            'option' => 10,
            'username' => $receiver->username,
            'bonusgift' => 500,
            'message' => 'thanks for everything',
        ])->assertRedirect('http://localhost/mybonus.php?do=transfer');

        $this->assertSame(500000 - 500, $this->seedBonus($giver->id));
        $this->assertSame(50 + 446, (int) $this->seedBonus($receiver->id));
        $this->assertSame(1, DB::table('bonus_logs')->where('uid', $giver->id)->where('business_type', BonusLogs::BUSINESS_TYPE_GIFT_TO_SOMEONE)->count());
        $this->assertSame(1, DB::table('bonus_logs')->where('uid', $receiver->id)->where('business_type', BonusLogs::BUSINESS_TYPE_RECEIVE_GIFT)->count());
        $this->assertSame(
            1,
            Message::query()->where('receiver', $receiver->id)->where('msg', 'like', '%thanks for everything%')->count()
        );
    }

    public function testExchangeKarmaGiftRejectsMissingReceiver()
    {
        $giver = $this->makeUser('bonus_gift_nobody');

        $this->requestAs($giver, 'post', '/mybonus.php', [
            'option' => 10,
            'username' => 'no_such_username',
            'bonusgift' => 500,
        ])->assertStatus(400);
    }

    public function testExchangeCharity()
    {
        $giver = $this->makeUser('bonus_charity_giver');
        $needy = $this->makeUser('bonus_charity_needy', User::CLASS_USER, [
            'downloaded' => 20 * 1073741824,
            'uploaded' => 0,
            'seedbonus' => 10,
        ]);

        // option 15 = gift_2 charity
        $this->requestAs($giver, 'post', '/mybonus.php', [
            'option' => 15,
            'bonuscharity' => 3000,
            'ratiocharity' => 0.3,
        ])->assertRedirect('http://localhost/mybonus.php?do=charity');

        $this->assertSame(500000 - 3000, $this->seedBonus($giver->id));
        $this->assertSame(10 + 3000, (int) $this->seedBonus($needy->id));
        $this->assertGreaterThan(
            0,
            DB::table('users')->where('id', $giver->id)->value('charity')
        );
    }

    public function testExchangeNotEnoughBonusRejected()
    {
        $user = $this->makeUser('bonus_poor', User::CLASS_USER, ['seedbonus' => 50]);

        // option 8 = custom title (5000 points)
        $this->requestAs($user, 'post', '/mybonus.php', ['option' => 8, 'title' => 'Rich'])
            ->assertStatus(400);
    }

    public function testExchangeCheatFieldsRejected()
    {
        $user = $this->makeUser('bonus_cheater');

        $this->requestAs($user, 'post', '/mybonus.php', ['option' => 0, 'userid' => $user->id])
            ->assertStatus(400);
    }

    public function testExchangeMissingOptionRejected()
    {
        $user = $this->makeUser('bonus_no_option');

        $this->requestAs($user, 'post', '/mybonus.php', [])
            ->assertStatus(400);
    }

    public function testExchangeSuccessShownOnNextVisit()
    {
        $user = $this->makeUser('bonus_success_message');

        $this->requestAs($user, 'post', '/mybonus.php', ['option' => 0]);

        $this->requestAs($user, 'get', '/mybonus.php?do=upload')
            ->assertOk()
            ->assertSee('You have just increased your', false);
    }
}