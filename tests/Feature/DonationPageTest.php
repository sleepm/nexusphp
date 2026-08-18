<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the donate.php / donated.php migration
 * (DonationController::web / DonationController::webDonated) against a real
 * database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created and restores the donation
 * settings it touched.
 */
class DonationPageTest extends TestCase
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
            DB::table('users')->whereIn('id', $ids)->delete();
        }
        $this->restoreSettings();
        parent::tearDown();
    }

    private function setSetting(string $name, ?string $value): void
    {
        if ($value === null) {
            DB::table('settings')->where('name', $name)->delete();
        } else {
            DB::table('settings')->updateOrInsert(['name' => $name], ['value' => $value]);
        }
    }

    private function restoreSettings(): void
    {
        DB::table('settings')->updateOrInsert(['name' => 'main.donation'], ['value' => 'yes']);
        DB::table('settings')->updateOrInsert(['name' => 'main.PAYPALACCOUNT'], ['value' => '']);
        DB::table('settings')->updateOrInsert(['name' => 'main.ALIPAYACCOUNT'], ['value' => '']);
        DB::table('settings')->where('name', 'misc.donation_custom')->delete();
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

    private function requestAs(User $user, string $method, string $uri, array $data = [])
    {
        app('auth')->forgetGuards();
        if ($method === 'post') {
            return $this->withCookie('c_secure_pass', $this->cookieFor($user))
                ->withCookie('c_lang_folder', 'en')
                ->post($uri, $data);
        }
        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get($uri);
    }

    // ------------------------------------------------------- donate page

    public function testDonateRendersPaypalAndAlipay()
    {
        $this->setSetting('main.donation', 'yes');
        $this->setSetting('main.PAYPALACCOUNT', 'donations@example.com');
        $this->setSetting('main.ALIPAYACCOUNT', 'alipay@example.com');

        $this->get('/donate.php')
            ->assertOk()
            ->assertSee('Donation')
            ->assertSee('Donate with PayPal')
            ->assertSee('Donate with Alipay')
            ->assertSee('donations@example.com')
            ->assertSee('alipay@example.com')
            ->assertSee('www.paypal.com', false)
            ->assertSee('www.alipay.com', false);
    }

    public function testDonateRendersCustomContentOnly()
    {
        $this->setSetting('main.donation', 'yes');
        $this->setSetting('main.PAYPALACCOUNT', '');
        $this->setSetting('main.ALIPAYACCOUNT', '');
        $this->setSetting('misc.donation_custom', 'Please contact our <b>treasurer</b> directly.');

        $this->get('/donate.php')
            ->assertOk()
            ->assertSee('Please contact our')
            ->assertSee('treasurer')
            ->assertDontSee('Donate with PayPal')
            ->assertDontSee('Donate with Alipay');
    }

    public function testDonateThankYouPage()
    {
        $this->setSetting('main.donation', 'yes');

        $this->get('/donate.php?do=thanks')
            ->assertOk()
            ->assertSee('Thank you for your donation!')
            ->assertSee('sendmessage.php?receiver=1', false);
    }

    public function testDonateDisabledRejected()
    {
        $this->setSetting('main.donation', 'no');

        $this->get('/donate.php')
            ->assertOk()
            ->assertSee("We don't accept donation at the moment.", false);
    }

    public function testDonateNoAccountAvailableRejected()
    {
        $this->setSetting('main.donation', 'yes');
        $this->setSetting('main.PAYPALACCOUNT', '');
        $this->setSetting('main.ALIPAYACCOUNT', '');
        $this->setSetting('misc.donation_custom', null);

        $this->get('/donate.php')
            ->assertOk()
            ->assertSee('No donation accounts are defined.');
    }

    // ------------------------------------------------------ donated page

    public function testDonatedRequiresLogin()
    {
        $this->get('/donated.php')->assertRedirect();
    }

    public function testDonatedRejectsBelowSysop()
    {
        $admin = $this->makeUser('donated_admin', User::CLASS_ADMINISTRATOR);

        $this->requestAs($admin, 'get', '/donated.php')->assertForbidden();
    }

    public function testDonatedRendersForm()
    {
        $sysop = $this->makeUser('donated_sysop', User::CLASS_SYSOP);

        $this->requestAs($sysop, 'get', '/donated.php')
            ->assertOk()
            ->assertSee('Update Users Donated Amounts')
            ->assertSee('name="username"', false)
            ->assertSee('name="donated"', false);
    }

    public function testDonatedUpdatesDonatedAmount()
    {
        $sysop = $this->makeUser('donated_updater', User::CLASS_SYSOP);
        $target = $this->makeUser('donated_target', User::CLASS_USER);
        DB::table('users')->where('id', $target->id)->update(['donated' => 0]);

        $this->requestAs($sysop, 'post', '/donated.php', [
            'username' => $target->username,
            'donated' => '25.50',
        ])->assertRedirect();

        $this->assertSame(25.5, (float) DB::table('users')->where('id', $target->id)->value('donated'));
    }

    public function testDonatedRejectsMissingFormData()
    {
        $sysop = $this->makeUser('donated_missing', User::CLASS_SYSOP);

        $this->requestAs($sysop, 'post', '/donated.php', [
            'username' => '',
            'donated' => '',
        ])->assertStatus(400);
    }

    public function testDonatedRejectsUnknownUser()
    {
        $sysop = $this->makeUser('donated_unknown', User::CLASS_SYSOP);

        $this->requestAs($sysop, 'post', '/donated.php', [
            'username' => 'nobody_here',
            'donated' => '10',
        ])->assertStatus(400);
    }
}
