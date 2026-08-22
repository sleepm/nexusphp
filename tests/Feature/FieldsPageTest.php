<?php

namespace Tests\Feature;

use App\Models\TorrentCustomField;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/fields.php migration to the
 * Filament TorrentCustomFieldResource.
 *
 * The legacy procedural "custom field management" page (UC_ADMINISTRATOR gate,
 * buildFieldTable/buildFieldForm rendering, raw sql_query edits/deletes) is
 * replaced by the TorrentCustomFields\TorrentCustomFieldResource list page.
 * The legacy entry point now redirects to the admin panel.
 */
class FieldsPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdFieldIds = [];

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
        if ($this->createdFieldIds !== []) {
            DB::table('torrents_custom_fields')->whereIn('id', $this->createdFieldIds)->delete();
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

    private function makeField(string $name, string $label = 'Test field', string $type = 'text'): TorrentCustomField
    {
        $field = TorrentCustomField::query()->create([
            'name' => $name,
            'label' => $label,
            'type' => $type,
            'required' => 0,
            'is_single_row' => 0,
            'options' => '',
            'help' => '',
            'display' => '',
            'priority' => 0,
        ]);
        $this->createdFieldIds[] = $field->id;

        return $field;
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

    public function testLegacyFieldsRedirectsToFilament(): void
    {
        $this->get('/fields.php')
            ->assertRedirect(route('filament.admin.resources.torrent-custom-fields.index'));
    }

    public function testLegacyFieldsWithActionStillRedirects(): void
    {
        $this->get('/fields.php?action=view')
            ->assertRedirect(route('filament.admin.resources.torrent-custom-fields.index'));
    }

    // ---------------------------------------------------------- auth on panel

    public function testFieldsResourceRequiresLogin(): void
    {
        $this->get('/nexusphp/torrent-custom-fields')
            ->assertStatus(302);
    }

    public function testFieldsResourceDeniedBelowSysop(): void
    {
        $user = $this->makeUser('fields_admin', User::CLASS_ADMINISTRATOR);

        $this->asUser($user)->get('/nexusphp/torrent-custom-fields')->assertForbidden();
    }

    // ------------------------------------------------------------ panel pages

    public function testSysopCanAccessFieldsResource(): void
    {
        $sysop = $this->makeUser('fields_sysop', User::CLASS_SYSOP);

        $this->asUser($sysop)
            ->get('/nexusphp/torrent-custom-fields')
            ->assertOk();
    }

    public function testListSeesCustomField(): void
    {
        $sysop = $this->makeUser('fields_list', User::CLASS_SYSOP);
        $field = $this->makeField('field_' . substr(md5((string) mt_rand()), 0, 6));

        $this->asUser($sysop)
            ->get('/nexusphp/torrent-custom-fields')
            ->assertOk()
            ->assertSee($field->label, false);
    }

    public function testCreatePageRenders(): void
    {
        $sysop = $this->makeUser('fields_create', User::CLASS_SYSOP);

        $this->asUser($sysop)
            ->get('/nexusphp/torrent-custom-fields/create')
            ->assertOk();
    }

    public function testEditPageRenders(): void
    {
        $sysop = $this->makeUser('fields_edit', User::CLASS_SYSOP);
        $field = $this->makeField('edit_' . substr(md5((string) mt_rand()), 0, 6));

        $this->asUser($sysop)
            ->get('/nexusphp/torrent-custom-fields/' . $field->id . '/edit')
            ->assertOk()
            ->assertSee($field->label, false);
    }
}
