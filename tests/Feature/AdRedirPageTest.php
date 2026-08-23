<?php

namespace Tests\Feature;

use App\Models\Advertisement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the adredir.php migration
 * (AdRedirController::web) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes the users and ads this test created.
 */
class AdRedirPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdAdIds = [];

    private array $originalSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';

        // Pre-set the required settings so the first get_setting() call in
        // the controller loads them.  The get_setting() static cache is
        // per-process so we can only mutate the DB before the first fetch.
        $this->setSetting('advertisement.enablead', 'yes');
        $this->setSetting('advertisement.adclickbonus', '5');
        $this->setSetting('tweak.bonus', 'enable');
    }

    protected function tearDown(): void
    {
        foreach ($this->originalSettings as $name => $value) {
            if ($value === null) {
                DB::table('settings')->where('name', $name)->delete();
            } else {
                DB::table('settings')->where('name', $name)->update(['value' => $value]);
            }
        }
        app('cache')->forget('nexus_settings_in_laravel');
        app('cache')->forget('nexus_settings_in_nexus');
        \Nexus\Database\NexusDB::cache_del('nexus_settings_in_laravel');
        \Nexus\Database\NexusDB::cache_del('nexus_settings_in_nexus');
        if ($this->createdAdIds !== []) {
            DB::table('advertisements')->whereIn('id', $this->createdAdIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    private function setSetting(string $name, $value): void
    {
        $row = DB::table('settings')->where('name', $name)->first();
        $this->originalSettings[$name] = $row ? $row->value : null;
        $stored = is_array($value) ? json_encode($value) : $value;
        if ($row) {
            DB::table('settings')->where('name', $name)->update(['value' => $stored]);
        } else {
            DB::table('settings')->insert([
                'name' => $name,
                'value' => $stored,
                'created_at' => now(),
                'updated_at' => now(),
                'autoload' => 'yes',
            ]);
        }
        app('cache')->forget('nexus_settings_in_laravel');
        app('cache')->forget('nexus_settings_in_nexus');
        \Nexus\Database\NexusDB::cache_del('nexus_settings_in_laravel');
        \Nexus\Database\NexusDB::cache_del('nexus_settings_in_nexus');
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
        ], $overrides));
        $this->createdUserIds[] = $user->id;

        return $user;
    }

    private function makeAd(): Advertisement
    {
        $ad = Advertisement::query()->create([
            'enabled' => true,
            'type' => Advertisement::TYPE_XHTML,
            'position' => Advertisement::POSITION_HEADER,
            'name' => 'Test Ad',
            'parameters' => json_encode(['xhtml' => ['code' => 'Test Ad']]),
            'code' => 'Test Ad',
            'starttime' => now()->subDay(),
            'endtime' => now()->addMonth(),
        ]);
        $this->createdAdIds[] = $ad->id;

        return $ad;
    }

    private function cookieFor(User $user): string
    {
        $tokenJson = json_encode(['user_id' => $user->id, 'expires' => time() + 3600]);
        $signature = hash_hmac('sha256', $tokenJson, $user->auth_key);
        return base64_encode($tokenJson . '.' . $signature);
    }

    private function requestAdRedir(User $user, string $uri)
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get($uri);
    }

    // ------------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->get('/adredir.php?id=1&url=http://example.com')->assertRedirect();
    }

    public function testRejectsParkedAccount()
    {
        $user = $this->makeUser('adredir_parked');
        DB::table('users')->where('id', $user->id)->update(['parked' => 'yes']);
        $this->requestAdRedir($user, '/adredir.php?id=1&url=http://example.com')->assertStatus(403);
    }

    // ---------------------------------------------------------------- errors

    public function testRejectsMissingId()
    {
        $user = $this->makeUser('adredir_noid');
        $this->requestAdRedir($user, '/adredir.php?url=http://example.com')->assertStatus(400);
    }

    public function testRejectsMissingUrl()
    {
        $user = $this->makeUser('adredir_nourl');
        $this->requestAdRedir($user, '/adredir.php?id=1')->assertStatus(400);
    }

    public function testRejectsInvalidAdId()
    {
        $user = $this->makeUser('adredir_invalid');
        $this->requestAdRedir($user, '/adredir.php?id=99999&url=http://example.com')->assertStatus(400);
    }

    // ----------------------------------------------------------- successful

    public function testRedirectsToTargetUrl()
    {
        $ad = $this->makeAd();
        $user = $this->makeUser('adredir_ok');

        $this->requestAdRedir($user, '/adredir.php?id=' . $ad->id . '&url=' . rawurlencode('http://example.com/redirect'))
            ->assertRedirect('http://example.com/redirect');
    }

    public function testCreatesAdClick()
    {
        $ad = $this->makeAd();
        $user = $this->makeUser('adredir_click');

        $this->requestAdRedir($user, '/adredir.php?id=' . $ad->id . '&url=' . rawurlencode('http://example.com/click'));

        $click = DB::table('adclicks')->where('adid', $ad->id)->where('userid', $user->id)->first();
        $this->assertNotNull($click);
    }

    public function testGrantsBonusOnFirstClick()
    {
        $ad = $this->makeAd();
        $user = $this->makeUser('adredir_bonus', ['seedbonus' => 100]);

        $this->requestAdRedir($user, '/adredir.php?id=' . $ad->id . '&url=' . rawurlencode('http://example.com/bonus'));

        $this->assertEquals(105, (int) DB::table('users')->where('id', $user->id)->value('seedbonus'));
    }

    public function testDoesNotGrantBonusOnSecondClick()
    {
        $ad = $this->makeAd();
        $user = $this->makeUser('adredir_nobonus2', ['seedbonus' => 100]);

        DB::table('adclicks')->insert([
            'adid' => $ad->id,
            'userid' => $user->id,
            'added' => now(),
        ]);

        $this->requestAdRedir($user, '/adredir.php?id=' . $ad->id . '&url=' . rawurlencode('http://example.com/nobonus2'));

        $this->assertEquals(100, (int) DB::table('users')->where('id', $user->id)->value('seedbonus'));
    }
}