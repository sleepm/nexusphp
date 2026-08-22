<?php

namespace Tests\Feature;

use App\Models\AllowedEmail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/allowedemails.php migration to the
 * Filament AllowedEmailsResource.
 *
 * The legacy procedural allowed-email page is replaced by the
 * System\AllowedEmailsResource. The legacy entry point now redirects to the
 * admin panel.
 */
class AllowedEmailsPageTest extends TestCase
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
        DB::table('allowedemails')->delete();
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

    // ------------------------------------------------------------------ legacy entry

    public function testLegacyAllowedEmailsRedirectsToFilament(): void
    {
        $this->get('/allowedemails.php')
            ->assertRedirect(route('filament.admin.resources.system.allowed-emails.index'));
    }

    // ---------------------------------------------------------- auth on panel

    public function testAllowedEmailsResourceRequiresLogin(): void
    {
        $this->get('/nexusphp/system/allowed-emails')
            ->assertStatus(302);
    }

    public function testAllowedEmailsResourceDeniedBelowSysop(): void
    {
        $admin = $this->makeUser('allowedemails_admin', User::CLASS_ADMINISTRATOR);

        $this->asUser($admin)->get('/nexusphp/system/allowed-emails')->assertForbidden();
    }

    // ------------------------------------------------------------ panel pages

    public function testSysopCanAccessAllowedEmailsResource(): void
    {
        $sysop = $this->makeUser('allowedemails_sysop', User::CLASS_SYSOP);

        $this->asUser($sysop)
            ->get('/nexusphp/system/allowed-emails')
            ->assertOk();
    }

    public function testManagePageEnsuresSingleRow(): void
    {
        $sysop = $this->makeUser('allowedemails_single', User::CLASS_SYSOP);
        AllowedEmail::query()->delete();

        $this->asUser($sysop)
            ->get('/nexusphp/system/allowed-emails')
            ->assertOk();

        $this->assertSame(1, AllowedEmail::query()->count());
    }

    public function testManagePageDoesNotDuplicateRow(): void
    {
        $sysop = $this->makeUser('allowedemails_nodup', User::CLASS_SYSOP);

        $this->asUser($sysop)->get('/nexusphp/system/allowed-emails')->assertOk();
        $this->asUser($sysop)->get('/nexusphp/system/allowed-emails')->assertOk();

        $this->assertSame(1, AllowedEmail::query()->count());
    }

    public function testListSeesValue(): void
    {
        $sysop = $this->makeUser('allowedemails_list', User::CLASS_SYSOP);
        AllowedEmail::query()->create(['value' => '@ok.com user@good.com']);

        $this->asUser($sysop)
            ->get('/nexusphp/system/allowed-emails')
            ->assertOk()
            ->assertSee('@ok.com user@good.com', false);
    }

    public function testValueCanBeSaved(): void
    {
        $row = AllowedEmail::query()->create(['value' => '@ok.com']);
        $row->update(['value' => '@ok.com user@good.com']);

        $this->assertSame('@ok.com user@good.com', $row->fresh()->value);
    }
}