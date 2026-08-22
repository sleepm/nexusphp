<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\Rule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/modrules.php migration to the
 * Filament RuleResource.
 *
 * The legacy procedural "Rules management" page (UC_ADMINISTRATOR gate,
 * hardcoded forms, sql_query inserts/updates/deletes) is replaced by the
 * System\RuleResource list/create/edit pages. The legacy entry point now
 * redirects to the admin panel.
 */
class ModRulesPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdRuleIds = [];

    private array $createdLangIds = [];

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
        if ($this->createdRuleIds !== []) {
            DB::table('rules')->whereIn('id', $this->createdRuleIds)->delete();
        }
        if ($this->createdLangIds !== []) {
            DB::table('language')->whereIn('id', $this->createdLangIds)->delete();
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

    private function makeLang(string $folder = 'xx', string $name = 'Testlang'): Language
    {
        $lang = Language::query()->create([
            'lang_name' => $name,
            'site_lang_folder' => $folder,
        ]);
        $this->createdLangIds[] = $lang->id;

        return $lang;
    }

    private function makeRule(int $langId, string $title = 'Test rule', string $text = 'Rule body'): Rule
    {
        $rule = Rule::query()->create([
            'lang_id' => $langId,
            'title' => $title,
            'text' => $text,
        ]);
        $this->createdRuleIds[] = $rule->id;

        return $rule;
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

    public function testLegacyModRulesRedirectsToFilament(): void
    {
        $this->get('/modrules.php')
            ->assertRedirect(route('filament.admin.resources.system.rules.index'));
    }

    // ---------------------------------------------------------- auth on panel

    public function testRuleResourceRequiresLogin(): void
    {
        $this->get('/nexusphp/system/rules')
            ->assertStatus(302);
    }

    public function testRuleResourceDeniedBelowAdministrator(): void
    {
        $user = $this->makeUser('modrules_moderator', User::CLASS_MODERATOR);

        $this->asUser($user)->get('/nexusphp/system/rules')->assertForbidden();
    }

    // ------------------------------------------------------------ panel pages

    public function testAdministratorCanAccessRuleResource(): void
    {
        $admin = $this->makeUser('modrules_admin', User::CLASS_ADMINISTRATOR);

        $this->asUser($admin)
            ->get('/nexusphp/system/rules')
            ->assertOk();
    }

    public function testListSeesRule(): void
    {
        $admin = $this->makeUser('modrules_list', User::CLASS_ADMINISTRATOR);
        $lang = $this->makeLang();
        $rule = $this->makeRule($lang->id, 'My rule title', 'My rule body');

        $this->asUser($admin)
            ->get('/nexusphp/system/rules')
            ->assertOk()
            ->assertSee('My rule title', false)
            ->assertSee('My rule body', false);
    }

    public function testCreatePageRenders(): void
    {
        $admin = $this->makeUser('modrules_create', User::CLASS_ADMINISTRATOR);

        $this->asUser($admin)
            ->get('/nexusphp/system/rules/create')
            ->assertOk();
    }

    public function testEditPageRenders(): void
    {
        $admin = $this->makeUser('modrules_edit', User::CLASS_ADMINISTRATOR);
        $lang = $this->makeLang();
        $rule = $this->makeRule($lang->id);

        $this->asUser($admin)
            ->get('/nexusphp/system/rules/' . $rule->id . '/edit')
            ->assertOk()
            ->assertSee('Test rule', false);
    }

    public function testSaveRuleClearsRulesCache(): void
    {
        $lang = $this->makeLang();

        \Nexus\Database\NexusDB::cache_del('rules');
        $rule = $this->makeRule($lang->id, 'Cached rule');

        $this->assertNull(\Nexus\Database\NexusDB::cache_get('rules'));
        $this->assertEquals('Cached rule', $rule->title);
        $this->assertEquals($lang->id, $rule->lang_id);
    }

    public function testDeleteRuleRemovesRow(): void
    {
        $lang = $this->makeLang();
        $rule = $this->makeRule($lang->id);

        $rule->delete();

        $this->assertDatabaseMissing('rules', ['id' => $rule->id]);
    }
}
