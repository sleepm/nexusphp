<?php

namespace Tests\Feature;

use App\Models\BannedEmail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/bannedemails.php migration to the
 * Filament BannedEmailsResource.
 *
 * The legacy procedural banned-email page is replaced by the
 * System\BannedEmailsResource. The legacy entry point now redirects to the
 * admin panel.
 */
class BannedEmailsPageTest extends TestCase
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
        DB::table('bannedemails')->delete();
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

    public function testLegacyBannedEmailsRedirectsToFilament(): void
    {
        $this->get('/bannedemails.php')
            ->assertRedirect(route('filament.admin.resources.system.banned-emails.index'));
    }

    // ---------------------------------------------------------- auth on panel

    public function testBannedEmailsResourceRequiresLogin(): void
    {
        $this->get('/nexusphp/system/banned-emails')
            ->assertStatus(302);
    }

    public function testBannedEmailsResourceDeniedBelowSysop(): void
    {
        $admin = $this->makeUser('bannedemails_admin', User::CLASS_ADMINISTRATOR);

        $this->asUser($admin)->get('/nexusphp/system/banned-emails')->assertForbidden();
    }

    // ------------------------------------------------------------ panel pages

    public function testSysopCanAccessBannedEmailsResource(): void
    {
        $sysop = $this->makeUser('bannedemails_sysop', User::CLASS_SYSOP);

        $this->asUser($sysop)
            ->get('/nexusphp/system/banned-emails')
            ->assertOk();
    }

    public function testManagePageEnsuresSingleRow(): void
    {
        $sysop = $this->makeUser('bannedemails_single', User::CLASS_SYSOP);
        BannedEmail::query()->delete();

        $this->asUser($sysop)
            ->get('/nexusphp/system/banned-emails')
            ->assertOk();

        $this->assertSame(1, BannedEmail::query()->count());
    }

    public function testManagePageDoesNotDuplicateRow(): void
    {
        $sysop = $this->makeUser('bannedemails_nodup', User::CLASS_SYSOP);

        $this->asUser($sysop)->get('/nexusphp/system/banned-emails')->assertOk();
        $this->asUser($sysop)->get('/nexusphp/system/banned-emails')->assertOk();

        $this->assertSame(1, BannedEmail::query()->count());
    }

    public function testListSeesValue(): void
    {
        $sysop = $this->makeUser('bannedemails_list', User::CLASS_SYSOP);
        BannedEmail::query()->create(['value' => '@spam.com user@bad.com']);

        $this->asUser($sysop)
            ->get('/nexusphp/system/banned-emails')
            ->assertOk()
            ->assertSee('@spam.com user@bad.com', false);
    }

    public function testValueCanBeSaved(): void
    {
        $row = BannedEmail::query()->create(['value' => '@spam.com']);
        $row->update(['value' => '@spam.com user@bad.com']);

        $this->assertSame('@spam.com user@bad.com', $row->fresh()->value);
    }
}