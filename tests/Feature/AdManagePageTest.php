<?php

namespace Tests\Feature;

use App\Models\Advertisement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/admanage.php migration to Filament.
 *
 * The legacy procedural advertisement management page is replaced by the
 * Filament System\AdvertisementResource. The legacy entry point now redirects
 * to the admin panel.
 */
class AdManagePageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdAdIds = [];

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
        if ($this->createdAdIds !== []) {
            DB::table('adclicks')->whereIn('adid', $this->createdAdIds)->delete();
            DB::table('advertisements')->whereIn('id', $this->createdAdIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $name, int $class): User
    {
        $user = User::query()->create([
            'username' => $name,
            'passhash' => str_repeat('a', 32),
            'secret' => 'secret',
            'auth_key' => 'authkey_' . $name,
            'email' => $name . '@example.com',
            'status' => 'confirmed',
            'enabled' => 'yes',
            'class' => $class,
            'passkey' => md5($name),
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
        ]);
        $this->createdUserIds[] = $user->id;

        return $user;
    }

    private function cookieFor(User $user): string
    {
        $tokenJson = json_encode(['user_id' => $user->id, 'expires' => time() + 3600]);
        $signature = hash_hmac('sha256', $tokenJson, $user->auth_key);
        return base64_encode($tokenJson . '.' . $signature);
    }

    private function asUser(User $user)
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en');
    }

    private function makeAd(string $name, string $type = 'image'): Advertisement
    {
        $ad = Advertisement::query()->create([
            'enabled' => 1,
            'type' => $type,
            'position' => 'header',
            'displayorder' => 0,
            'name' => $name,
            'parameters' => json_encode(['image' => ['url' => 'pic/ad.jpg', 'link' => 'https://example.com', 'width' => '', 'height' => '', 'title' => '']]),
            'code' => '',
            'starttime' => now()->subDay(),
            'endtime' => now()->addDay(),
        ]);
        $ad->regenerateCode();
        $ad->save();
        $this->createdAdIds[] = $ad->id;

        return $ad;
    }

    // ------------------------------------------------------------------ legacy entry

    public function testLegacyAdmanageRedirectsToFilament(): void
    {
        $this->get('/admanage.php')
            ->assertRedirect(route('filament.admin.resources.system.advertisements.index'));
    }

    public function testLegacyAdmanageWithActionStillRedirects(): void
    {
        $this->get('/admanage.php?action=add&position=header')
            ->assertRedirect(route('filament.admin.resources.system.advertisements.index'));
    }

    // ---------------------------------------------------------- auth on panel

    public function testAdvertisementResourceRequiresLogin(): void
    {
        $this->get('/nexusphp/system/advertisements')
            ->assertStatus(302);
    }

    public function testAdvertisementResourceDeniedBelowModerator(): void
    {
        $user = $this->makeUser('admanage_uploader', User::CLASS_UPLOADER);

        $this->asUser($user)->get('/nexusphp/system/advertisements')->assertForbidden();
    }

    // ------------------------------------------------------------ panel pages

    public function testAdministratorCanAccessAdvertisementResource(): void
    {
        $admin = $this->makeUser('admanage_admin', User::CLASS_ADMINISTRATOR);

        $this->asUser($admin)
            ->get('/nexusphp/system/advertisements')
            ->assertOk();
    }

    public function testListSeesAdvertisement(): void
    {
        $admin = $this->makeUser('admanage_list', User::CLASS_ADMINISTRATOR);
        $ad = $this->makeAd('ad_test_' . substr(md5((string) mt_rand()), 0, 6));

        $this->asUser($admin)
            ->get('/nexusphp/system/advertisements')
            ->assertOk()
            ->assertSee($ad->name, false);
    }

    public function testCreatePageRenders(): void
    {
        $admin = $this->makeUser('admanage_create', User::CLASS_ADMINISTRATOR);

        $this->asUser($admin)
            ->get('/nexusphp/system/advertisements/create')
            ->assertOk();
    }

    public function testBuildCodeForTextAd(): void
    {
        $code = Advertisement::buildCode('text', ['text' => [
            'content' => 'Hello world',
            'link' => 'https://example.com',
            'size' => '',
        ]], 42);

        $this->assertStringContainsString('adredir.php?id=42', $code);
        $this->assertStringContainsString('Hello world', $code);
        $this->assertStringContainsString('https%3A%2F%2Fexample.com', $code);
    }

    public function testRegenerateCodeSetsCodeColumn(): void
    {
        $ad = $this->makeAd('ad_test_code');
        $ad->parameters = ['image' => [
            'url' => 'pic/ad.jpg',
            'link' => 'https://example.com',
            'width' => 300,
            'height' => 60,
            'title' => 'tooltip',
        ]];
        $ad->type = 'image';
        $ad->regenerateCode();

        $this->assertStringContainsString('adredir.php?id=' . $ad->id, $ad->code);
        $this->assertStringContainsString('pic/ad.jpg', $ad->code);
        $this->assertStringContainsString('width="300"', $ad->code);
        $this->assertStringContainsString('title="tooltip"', $ad->code);
    }
}
