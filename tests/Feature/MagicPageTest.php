<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the magic.php migration
 * (RewardController::web → magic reward JSON endpoint).
 */
class MagicPageTest extends TestCase
{
    private array $createdUserIds = [];
    private array $createdTorrentIds = [];
    private array $createdMagicIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        \Nexus\Database\NexusDB::cache_del('nexus_settings_in_laravel');
        \Nexus\Database\NexusDB::cache_del('nexus_settings_in_nexus');
    }

    protected function tearDown(): void
    {
        if ($this->createdMagicIds !== []) {
            DB::table('magic')->whereIn('id', $this->createdMagicIds)->delete();
        }
        if ($this->createdTorrentIds !== []) {
            DB::table('torrents')->whereIn('id', $this->createdTorrentIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $username, float $seedbonus = 1000): User
    {
        $user = User::query()->create([
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
            'seedbonus' => $seedbonus,
            'showfb' => 'yes',
            'hidehb' => 'no',
            'added' => now(),
            'last_access' => now(),
            'commentpm' => 'no',
        ]);
        $this->createdUserIds[] = $user->id;
        return $user;
    }

    private function makeTorrent(User $owner, array $overrides = []): int
    {
        $id = DB::table('torrents')->insertGetId(array_merge([
            'name' => 'E2E Magic Torrent',
            'small_descr' => 'a small description',
            'category' => 1,
            'owner' => $owner->id,
            'added' => now(),
            'visible' => 'yes',
            'banned' => 'no',
            'anonymous' => 'no',
            'sp_state' => 1,
            'promotion_time_type' => 0,
            'pos_state' => 'normal',
            'picktype' => 'normal',
            'url' => 'https://www.imdb.com/title/tt0111161/',
        ], $overrides));
        $this->createdTorrentIds[] = $id;

        return $id;
    }

    private function cookieFor(User $user): string
    {
        $tokenJson = json_encode(['user_id' => $user->id, 'expires' => time() + 3600]);
        $signature = hash_hmac('sha256', $tokenJson, $user->auth_key);
        return base64_encode($tokenJson . '.' . $signature);
    }

    private function postAs(User $user, array $data)
    {
        app('auth')->forgetGuards();
        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->post('/magic.php', $data);
    }

    private function rewardOptionValue(): int
    {
        $options = Setting::getBonusRewardOptions();
        return (int) reset($options);
    }

    // ------------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->post('/magic.php', ['id' => 1, 'value' => 50])
            ->assertRedirect();
    }

    // ------------------------------------------------------------ validation

    public function testInvalidValueRejected()
    {
        $giver = $this->makeUser('magic_giver_invalid');
        $owner = $this->makeUser('magic_owner_invalid');
        $torrentId = $this->makeTorrent($owner);

        $this->postAs($giver, ['id' => $torrentId, 'value' => 3])
            ->assertOk()
            ->assertJson(['ret' => -1])
            ->assertJson(['msg' => 'Invalid value.']);
    }

    public function testInsufficientBonusRejected()
    {
        $giver = $this->makeUser('magic_giver_poor', 10);
        $owner = $this->makeUser('magic_owner_poor');
        $torrentId = $this->makeTorrent($owner);
        $value = $this->rewardOptionValue();

        $this->postAs($giver, ['id' => $torrentId, 'value' => $value])
            ->assertOk()
            ->assertJson(['ret' => -1])
            ->assertJson(['msg' => 'You do not have such bonus!']);
    }

    public function testInvalidTorrentRejected()
    {
        $giver = $this->makeUser('magic_giver_notorrent');

        $this->postAs($giver, ['id' => 999999999, 'value' => $this->rewardOptionValue()])
            ->assertOk()
            ->assertJson(['ret' => -1])
            ->assertJson(['msg' => 'Invalid torrent id!']);
    }

    public function testGivingToSelfRejected()
    {
        $giver = $this->makeUser('magic_giver_self');
        $torrentId = $this->makeTorrent($giver);

        $this->postAs($giver, ['id' => $torrentId, 'value' => $this->rewardOptionValue()])
            ->assertOk()
            ->assertJson(['ret' => -1])
            ->assertJson(['msg' => 'You are giving magic to yourself.']);
    }

    public function testDuplicateRewardRejected()
    {
        $giver = $this->makeUser('magic_giver_dupe');
        $owner = $this->makeUser('magic_owner_dupe');
        $torrentId = $this->makeTorrent($owner);
        $value = $this->rewardOptionValue();

        $this->postAs($giver, ['id' => $torrentId, 'value' => $value])
            ->assertOk()
            ->assertJson(['ret' => 0]);

        $this->postAs($giver, ['id' => $torrentId, 'value' => $value])
            ->assertOk()
            ->assertJson(['ret' => -1])
            ->assertJson(['msg' => 'You already gave the magic value!']);
    }

    // ------------------------------------------------------------ happy path

    public function testSuccessfulRewardTransfersBonus()
    {
        $giver = $this->makeUser('magic_giver_ok', 500);
        $owner = $this->makeUser('magic_owner_ok', 100);
        $torrentId = $this->makeTorrent($owner);
        $value = $this->rewardOptionValue();

        $this->postAs($giver, ['id' => $torrentId, 'value' => $value])
            ->assertOk()
            ->assertJson(['ret' => 0]);

        $this->assertSame(500 - $value, (int) DB::table('users')->where('id', $giver->id)->value('seedbonus'));
        $this->assertSame(100 + $value, (int) DB::table('users')->where('id', $owner->id)->value('seedbonus'));

        $magicRow = DB::table('magic')
            ->where('torrentid', $torrentId)
            ->where('userid', $giver->id)
            ->first();
        $this->assertNotNull($magicRow);
        $this->assertSame($value, (int) $magicRow->value);

        $giverLog = DB::table('bonus_logs')
            ->where('uid', $giver->id)
            ->where('business_type', \App\Models\BonusLogs::BUSINESS_TYPE_REWARD_TORRENT)
            ->first();
        $this->assertNotNull($giverLog);

        $ownerLog = DB::table('bonus_logs')
            ->where('uid', $owner->id)
            ->where('business_type', \App\Models\BonusLogs::BUSINESS_TYPE_TORRENT_BE_REWARD)
            ->first();
        $this->assertNotNull($ownerLog);
    }
}