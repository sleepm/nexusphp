<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the promotionlink.php migration
 * (PromotionLinkController::web) against a real database.
 */
class PromotionLinkPageTest extends TestCase
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
            DB::table('prolinkclicks')->whereIn('userid', $this->createdUserIds)->delete();
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $username, $class = User::CLASS_POWER_USER, array $overrides = []): User
    {
        $user = User::query()->create(array_merge([
            'username' => $username,
            'passhash' => str_repeat('b', 32),
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
        $user->forceFill($overrides)->save();
        $user->refresh();
        $this->createdUserIds[] = $user->id;

        return $user;
    }

    private function cookieFor(User $user): string
    {
        $tokenJson = json_encode(['user_id' => $user->id, 'expires' => time() + 3600]);
        $signature = hash_hmac('sha256', $tokenJson, $user->auth_key);
        return base64_encode($tokenJson . '.' . $signature);
    }

    private function getPromotion(?User $user, string $query = '')
    {
        app('auth')->forgetGuards();

        $request = $this;
        if ($user) {
            $request = $request->withCookie('c_secure_pass', $this->cookieFor($user));
        }
        return $request->withCookie('c_lang_folder', 'en')->get('/promotionlink.php' . $query);
    }

    // ------------------------------------------------------------------ auth

    public function testPromotionPageRequiresLogin()
    {
        $this->getPromotion(null)->assertRedirect();
    }

    public function testKeyClickWorksForGuests()
    {
        $owner = $this->makeUser('promo_link_owner', User::CLASS_POWER_USER, [
            'promotion_link' => 'uniqueclink123',
            'seedbonus' => 50,
        ]);

        $before = (float) DB::table('users')->where('id', $owner->id)->value('seedbonus');

        $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.1'])
            ->get('/promotionlink.php?key=uniqueclink123')
            ->assertStatus(302);

        $after = (float) DB::table('users')->where('id', $owner->id)->value('seedbonus');
        $this->assertGreaterThan($before, $after);

        $this->assertSame(1, DB::table('prolinkclicks')->where('userid', $owner->id)->count());
    }

    public function testKeyClickDoesNotDoubleCountSameIp()
    {
        $owner = $this->makeUser('promo_link_dup', User::CLASS_POWER_USER, [
            'promotion_link' => 'dupclink456',
            'seedbonus' => 50,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.2'])
            ->get('/promotionlink.php?key=dupclink456')
            ->assertStatus(302);

        $afterOne = (float) DB::table('users')->where('id', $owner->id)->value('seedbonus');

        $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.2'])
            ->get('/promotionlink.php?key=dupclink456')
            ->assertStatus(302);

        $afterTwo = (float) DB::table('users')->where('id', $owner->id)->value('seedbonus');
        $this->assertSame($afterOne, $afterTwo);
        $this->assertSame(1, DB::table('prolinkclicks')->where('userid', $owner->id)->count());
    }

    public function testKeyClickIgnoresUnknownKey()
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.3'])
            ->get('/promotionlink.php?key=doesnotexist')
            ->assertStatus(302);
    }

    // ------------------------------------------------------------ rendering

    public function testPromotionPageRenders()
    {
        $user = $this->makeUser('promo_page_user', User::CLASS_POWER_USER, [
            'promotion_link' => 'pageclink789',
        ]);

        $response = $this->getPromotion($user);
        file_put_contents('/tmp/plink-render.html', (string) $response->getContent());
        $response
            ->assertOk()
            ->assertSee('Promotion Link')
            ->assertSee('promotionlink.php?key=pageclink789', false)
            ->assertSee('XHTML 1.0')
            ->assertSee('HTML 4.01')
            ->assertSee('BBCode')
            ->assertSee('BBCode userbar');
    }

    public function testPromotionUserbarHiddenWithoutPermission()
    {
        $user = $this->makeUser('promo_page_plain', User::CLASS_USER, [
            'promotion_link' => 'plainclink111',
        ]);

        $this->getPromotion($user)
            ->assertOk()
            ->assertSee('Promotion Link')
            ->assertDontSee('BBCode userbar');
    }

    // ------------------------------------------------------- key generation

    public function testPromotionRegeneratesKey()
    {
        $user = $this->makeUser('promo_regenerate', User::CLASS_POWER_USER, [
            'promotion_link' => 'oldclink222',
        ]);

        $this->getPromotion($user, '?updatekey=1')->assertStatus(302);

        $newKey = DB::table('users')->where('id', $user->id)->value('promotion_link');
        $this->assertNotSame('oldclink222', $newKey);
        $this->assertNotNull($newKey);
    }

    public function testPromotionGeneratesKeyWhenMissing()
    {
        $user = $this->makeUser('promo_missing_key', User::CLASS_POWER_USER, [
            'promotion_link' => null,
        ]);

        $this->getPromotion($user)->assertStatus(302);

        $key = DB::table('users')->where('id', $user->id)->value('promotion_link');
        $this->assertNotNull($key);
    }
}