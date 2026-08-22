<?php

namespace Tests\Feature;

use App\Models\Link;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/linksmanage.php migration.
 *
 * The legacy procedural link-management page is replaced by the Filament
 * System\LinksResource (admin entry point) plus a Laravel controller for the
 * link-exchange application flow (?action=apply / POST action=newapply).
 */
class LinksManagePageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdLinkIds = [];

    private array $createdStaffMessageIds = [];

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
        if ($this->createdStaffMessageIds !== []) {
            DB::table('staffmessages')->whereIn('id', $this->createdStaffMessageIds)->delete();
        }
        if ($this->createdLinkIds !== []) {
            DB::table('links')->whereIn('id', $this->createdLinkIds)->delete();
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

    private function makeLink(string $name, string $url, string $title = ''): Link
    {
        $link = Link::query()->create([
            'name' => $name,
            'url' => $url,
            'title' => $title,
        ]);
        $this->createdLinkIds[] = $link->id;

        return $link;
    }

    // ------------------------------------------------------------------ legacy entry

    public function testLegacyLinksmanageAdminRedirectsToFilament(): void
    {
        $admin = $this->makeUser('linksmanage_admin_redirect', User::CLASS_ADMINISTRATOR);

        $this->asUser($admin)
            ->get('/linksmanage.php')
            ->assertRedirect(route('filament.admin.resources.system.links.index'));
    }

    public function testLegacyLinksmanageRequiresLogin(): void
    {
        $this->get('/linksmanage.php')->assertRedirect();
    }

    // ---------------------------------------------------------- auth on panel

    public function testLinksResourceRequiresLogin(): void
    {
        $this->get('/nexusphp/system/links')
            ->assertStatus(302);
    }

    public function testLinksResourceDeniedBelowAdministrator(): void
    {
        $user = $this->makeUser('linksmanage_moderator', User::CLASS_MODERATOR);

        $this->asUser($user)->get('/nexusphp/system/links')->assertForbidden();
    }

    // ------------------------------------------------------------ panel pages

    public function testAdministratorCanAccessLinksResource(): void
    {
        $admin = $this->makeUser('linksmanage_admin', User::CLASS_ADMINISTRATOR);

        $this->asUser($admin)
            ->get('/nexusphp/system/links')
            ->assertOk();
    }

    public function testListSeesLink(): void
    {
        $admin = $this->makeUser('linksmanage_list', User::CLASS_ADMINISTRATOR);
        $link = $this->makeLink('nexusphp_link', 'https://nexusphp.org', 'The Ultimate File Sharing Solution');

        $this->asUser($admin)
            ->get('/nexusphp/system/links')
            ->assertOk()
            ->assertSee($link->name, false);
    }

    public function testCreatePageRenders(): void
    {
        $admin = $this->makeUser('linksmanage_create', User::CLASS_ADMINISTRATOR);

        $this->asUser($admin)
            ->get('/nexusphp/system/links/create')
            ->assertOk();
    }

    public function testDeleteLink(): void
    {
        $admin = $this->makeUser('linksmanage_delete', User::CLASS_ADMINISTRATOR);
        $link = $this->makeLink('linksmanage_delete_target', 'https://example.com');

        $this->asUser($admin)
            ->get('/nexusphp/system/links')
            ->assertOk();

        $link->delete();

        $this->assertDatabaseMissing('links', ['id' => $link->id]);
    }

    // ------------------------------------------------------ link exchange apply

    public function testApplyFormRendersForUserWithPermission(): void
    {
        $user = $this->makeUser('linksmanage_apply_user', User::CLASS_USER);

        $this->asUser($user)
            ->get('/linksmanage.php?action=apply')
            ->assertOk()
            ->assertSee('Rules for Link Exchange:', false)
            ->assertSee('name=linkname', false)
            ->assertSee('name=url', false)
            ->assertSee('name=reason', false)
            ->assertSee('name=email', false);
    }

    public function testApplyFormDeniedWithoutPermission(): void
    {
        $peasant = $this->makeUser('linksmanage_apply_peasant', User::CLASS_PEASANT);

        $this->asUser($peasant)
            ->get('/linksmanage.php?action=apply')
            ->assertForbidden();
    }

    public function testApplySubmitCreatesStaffMessage(): void
    {
        $user = $this->makeUser('linksmanage_apply_submit', User::CLASS_USER);

        $response = $this->asUser($user)->post('/linksmanage.php', [
            'action' => 'newapply',
            'linkname' => 'Example Site',
            'url' => 'https://example.com',
            'title' => 'Example title',
            'admin' => 'John Doe',
            'email' => 'admin@example.com',
            'reason' => 'We want to exchange links with you because we are a friendly community.',
        ]);

        $response->assertOk()->assertSee('Your application has been successfully sent', false);

        $message = DB::table('staffmessages')
            ->where('sender', $user->id)
            ->where('subject', 'Example Site applys for links')
            ->first();

        $this->assertNotNull($message);
        $this->assertStringContainsString('https://example.com', $message->msg);
        $this->assertStringContainsString('admin@example.com', $message->msg);

        $this->createdStaffMessageIds[] = $message->id;
    }

    public function testApplySubmitValidatesRequiredFields(): void
    {
        $user = $this->makeUser('linksmanage_apply_invalid', User::CLASS_USER);

        $response = $this->asUser($user)->post('/linksmanage.php', [
            'action' => 'newapply',
            'linkname' => '',
            'url' => 'https://example.com',
            'admin' => 'John Doe',
            'email' => 'admin@example.com',
            'reason' => 'This reason is definitely long enough to pass the minimum length check.',
        ]);

        $response->assertOk()->assertSee('could not be empty', false);

        $this->assertSame(
            0,
            DB::table('staffmessages')->where('sender', $user->id)->count()
        );
    }

    public function testApplySubmitRejectsInvalidEmail(): void
    {
        $user = $this->makeUser('linksmanage_apply_bademail', User::CLASS_USER);

        $response = $this->asUser($user)->post('/linksmanage.php', [
            'action' => 'newapply',
            'linkname' => 'Example Site',
            'url' => 'https://example.com',
            'admin' => 'John Doe',
            'email' => 'not-an-email',
            'reason' => 'This reason is definitely long enough to pass the minimum length check.',
        ]);

        $response->assertOk()->assertSee('The Email address is invalid', false);

        $this->assertSame(
            0,
            DB::table('staffmessages')->where('sender', $user->id)->count()
        );
    }
}
