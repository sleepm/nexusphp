<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the settings.php migration
 * (SettingsController::web) against a real database.
 */
class SettingsPageTest extends TestCase
{
    protected array $createdUserIds = [];
    protected array $createdSettingNames = [];
    protected array $originalSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['SCRIPT_NAME'] = '/settings.php';

        // Seed the legacy defaults from config/allconfig.php so the settings
        // forms render with populated values in a bare test database.
        require ROOT_PATH . 'config/allconfig.php';
        $sections = [
            'account' => $ACCOUNT,
            'advertisement' => $ADVERTISEMENT,
            'attachment' => $ATTACHMENT,
            'authority' => $AUTHORITY,
            'basic' => $BASIC,
            'bonus' => $BONUS,
            'code' => $CODE,
            'main' => $MAIN,
            'security' => $SECURITY,
            'smtp' => $SMTP,
            'torrent' => $TORRENT,
            'tweak' => $TWEAK,
        ];
        foreach ($sections as $prefix => $values) {
            foreach ($values as $key => $value) {
                $name = "$prefix.$key";
                $row = DB::table('settings')->where('name', $name)->first();
                if (! $row) {
                    $this->originalSettings[$name] = null;
                    DB::table('settings')->insert([
                        'name' => $name,
                        'value' => is_array($value) ? json_encode($value) : $value,
                        'created_at' => now(),
                        'updated_at' => now(),
                        'autoload' => 'yes',
                    ]);
                    $this->createdSettingNames[] = $name;
                }
            }
        }
        // main.defaultlang must be present for getDefaultLang(): string
        $this->originalSettings['main.defaultlang'] = DB::table('settings')->where('name', 'main.defaultlang')->first();
        DB::table('settings')->updateOrInsert(['name' => 'main.defaultlang'], ['value' => 'en', 'updated_at' => now()]);
        $this->createdSettingNames[] = 'main.defaultlang';
        // site_language_enabled must be an array for Language::listEnabled()
        $this->originalSettings['main.site_language_enabled'] = DB::table('settings')->where('name', 'main.site_language_enabled')->first();
        DB::table('settings')->updateOrInsert(['name' => 'main.site_language_enabled'], ['value' => json_encode(['en', 'chs', 'cht']), 'updated_at' => now()]);
        $this->createdSettingNames[] = 'main.site_language_enabled';

        Cache::forget('nexus_settings_in_laravel');
        Cache::forget('nexus_settings_in_nexus');
    }

    protected function tearDown(): void
    {
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        foreach ($this->originalSettings as $name => $row) {
            if ($row) {
                DB::table('settings')->where('name', $name)->update(['value' => $row->value]);
            } else {
                DB::table('settings')->where('name', $name)->delete();
            }
        }
        if ($this->createdSettingNames !== []) {
            DB::table('settings')->whereIn('name', $this->createdSettingNames)->whereNotIn('name', array_keys($this->originalSettings))->delete();
        }
        Cache::forget('nexus_settings_in_laravel');
        Cache::forget('nexus_settings_in_nexus');
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
            'added' => now()->format('Y-m-d H:i:s'),
            'last_access' => now()->format('Y-m-d H:i:s'),
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

    // ------------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->get('/settings.php')->assertRedirect();
    }

    public function testDeniedBelowSysop()
    {
        $user = $this->makeUser('settings_user', User::CLASS_USER);

        $this->asUser($user)->get('/settings.php')->assertForbidden();
    }

    // ------------------------------------------------------------------ menu

    public function testMenuRenders()
    {
        $sysop = $this->makeUser('settings_sysop', User::CLASS_SYSOP);

        $this->asUser($sysop)->get('/settings.php')
            ->assertOk()
            ->assertSee('Website Settings', false)
            ->assertSee("value='basicsettings'", false)
            ->assertSee("value='mainsettings'", false)
            ->assertSee("value='smtpsettings'", false);
    }

    // ------------------------------------------------------------------ form views

    public function testBasicSettingsFormRenders()
    {
        $sysop = $this->makeUser('settings_basic_sysop', User::CLASS_SYSOP);

        $this->asUser($sysop)->post('/settings.php', ['action' => 'basicsettings'])
            ->assertOk()
            ->assertSee('SITENAME', false)
            ->assertSee('BASEURL', false)
            ->assertSee('savesettings_basic', false);
    }

    public function testMainSettingsFormRenders()
    {
        $sysop = $this->makeUser('settings_main_sysop', User::CLASS_SYSOP);

        $this->asUser($sysop)->post('/settings.php', ['action' => 'mainsettings'])
            ->assertOk()
            ->assertSee('site_online', false)
            ->assertSee('invitesystem', false)
            ->assertSee('savesettings_main', false);
    }

    public function testSmtpSettingsFormRenders()
    {
        $sysop = $this->makeUser('settings_smtp_sysop', User::CLASS_SYSOP);

        $this->asUser($sysop)->post('/settings.php', ['action' => 'smtpsettings'])
            ->assertOk()
            ->assertSee('emailnotify', false)
            ->assertSee('smtptype', false)
            ->assertSee('savesettings_smtp', false);
    }

    public function testSecuritySettingsFormRenders()
    {
        $sysop = $this->makeUser('settings_security_sysop', User::CLASS_SYSOP);

        $this->asUser($sysop)->post('/settings.php', ['action' => 'securitysettings'])
            ->assertOk()
            ->assertSee('securelogin', false)
            ->assertSee('iv', false)
            ->assertSee('savesettings_security', false);
    }

    public function testAuthoritySettingsFormRenders()
    {
        $sysop = $this->makeUser('settings_authority_sysop', User::CLASS_SYSOP);

        $this->asUser($sysop)->post('/settings.php', ['action' => 'authoritysettings'])
            ->assertOk()
            ->assertSee('defaultclass', false)
            ->assertSee('staffmem', false)
            ->assertSee('savesettings_authority', false);
    }

    public function testTweakSettingsFormRenders()
    {
        $sysop = $this->makeUser('settings_tweak_sysop', User::CLASS_SYSOP);

        $this->asUser($sysop)->post('/settings.php', ['action' => 'tweaksettings'])
            ->assertOk()
            ->assertSee('where', false)
            ->assertSee('enabletooltip', false)
            ->assertSee('savesettings_tweak', false);
    }

    public function testBonusSettingsFormRenders()
    {
        $sysop = $this->makeUser('settings_bonus_sysop', User::CLASS_SYSOP);

        $this->asUser($sysop)->post('/settings.php', ['action' => 'bonussettings'])
            ->assertOk()
            ->assertSee('donortimes', false)
            ->assertSee('perseeding', false)
            ->assertSee('savesettings_bonus', false);
    }

    public function testAccountSettingsFormRenders()
    {
        $sysop = $this->makeUser('settings_account_sysop', User::CLASS_SYSOP);

        $this->asUser($sysop)->post('/settings.php', ['action' => 'accountsettings'])
            ->assertOk()
            ->assertSee('neverdelete', false)
            ->assertSee('deletepeasant', false)
            ->assertSee('savesettings_account', false);
    }

    public function testTorrentSettingsFormRenders()
    {
        $sysop = $this->makeUser('settings_torrent_sysop', User::CLASS_SYSOP);

        $this->asUser($sysop)->post('/settings.php', ['action' => 'torrentsettings'])
            ->assertOk()
            ->assertSee('sticky_first_level_background_color', false)
            ->assertSee('download_support_passkey', false)
            ->assertSee('savesettings_torrent', false);
    }

    public function testAttachmentSettingsFormRenders()
    {
        $sysop = $this->makeUser('settings_attach_sysop', User::CLASS_SYSOP);

        $this->asUser($sysop)->post('/settings.php', ['action' => 'attachmentsettings'])
            ->assertOk()
            ->assertSee('enableattach', false)
            ->assertSee('savesettings_attachment', false);
    }

    public function testAdvertisementSettingsFormRenders()
    {
        $sysop = $this->makeUser('settings_ad_sysop', User::CLASS_SYSOP);

        $this->asUser($sysop)->post('/settings.php', ['action' => 'advertisementsettings'])
            ->assertOk()
            ->assertSee('enablead', false)
            ->assertSee('savesettings_advertisement', false);
    }

    public function testCodeSettingsFormRenders()
    {
        $sysop = $this->makeUser('settings_code_sysop', User::CLASS_SYSOP);

        $this->asUser($sysop)->post('/settings.php', ['action' => 'codesettings'])
            ->assertOk()
            ->assertSee('mainversion', false)
            ->assertSee('subversion', false)
            ->assertSee('savesettings_code', false);
    }

    public function testMiscSettingsFormRenders()
    {
        $sysop = $this->makeUser('settings_misc_sysop', User::CLASS_SYSOP);

        $this->asUser($sysop)->post('/settings.php', ['action' => 'miscsettings'])
            ->assertOk()
            ->assertSee('donation_custom', false)
            ->assertSee('protected_forum', false)
            ->assertSee('savesettings_misc', false);
    }

    // ------------------------------------------------------------------ save actions

    public function testSaveBasicSettings()
    {
        $sysop = $this->makeUser('settings_save_basic', User::CLASS_SYSOP);

        $this->asUser($sysop)->post('/settings.php', [
            'action' => 'savesettings_basic',
            'SITENAME' => 'TestSite_' . uniqid(),
            'BASEURL' => 'testsite.example.com',
        ])
            ->assertOk()
            ->assertSee('Message', false)
            ->assertSee('here', false);

        $this->createdSettingNames[] = 'basic.SITENAME';
        $this->createdSettingNames[] = 'basic.BASEURL';
        $this->createdSettingNames[] = 'basic.announce_url';
    }

    public function testSaveMainSettings()
    {
        $sysop = $this->makeUser('settings_save_main', User::CLASS_SYSOP);

        $this->asUser($sysop)->post('/settings.php', [
            'action' => 'savesettings_main',
            'site_online' => 'yes',
            'max_torrent_size' => '2097152',
            'invitesystem' => 'yes',
            'registration' => 'yes',
        ])
            ->assertOk()
            ->assertSee('Message', false);

        $this->createdSettingNames[] = 'main.site_online';
        $this->createdSettingNames[] = 'main.max_torrent_size';
        $this->createdSettingNames[] = 'main.invitesystem';
        $this->createdSettingNames[] = 'main.registration';
    }

    public function testSaveSmtpSettings()
    {
        $sysop = $this->makeUser('settings_save_smtp', User::CLASS_SYSOP);

        $this->asUser($sysop)->post('/settings.php', [
            'action' => 'savesettings_smtp',
            'smtptype' => 'default',
            'emailnotify' => 'no',
        ])
            ->assertOk()
            ->assertSee('Message', false);

        $this->createdSettingNames[] = 'smtp.smtptype';
        $this->createdSettingNames[] = 'smtp.emailnotify';
    }

    public function testSaveSecuritySettings()
    {
        $sysop = $this->makeUser('settings_save_sec', User::CLASS_SYSOP);

        $this->asUser($sysop)->post('/settings.php', [
            'action' => 'savesettings_security',
            'securelogin' => 'no',
            'iv' => 'yes',
            'maxip' => '3',
        ])
            ->assertOk()
            ->assertSee('Message', false);

        $this->createdSettingNames[] = 'security.securelogin';
        $this->createdSettingNames[] = 'security.iv';
        $this->createdSettingNames[] = 'security.maxip';
    }

    public function testSaveTweakSettings()
    {
        $sysop = $this->makeUser('settings_save_tweak', User::CLASS_SYSOP);

        $this->asUser($sysop)->post('/settings.php', [
            'action' => 'savesettings_tweak',
            'where' => 'yes',
            'bonus' => 'enable',
            'enablelocation' => 'yes',
        ])
            ->assertOk()
            ->assertSee('Message', false);

        $this->createdSettingNames[] = 'tweak.where';
        $this->createdSettingNames[] = 'tweak.bonus';
        $this->createdSettingNames[] = 'tweak.enablelocation';
    }

    public function testSaveTorrentSettings()
    {
        $sysop = $this->makeUser('settings_save_torrent', User::CLASS_SYSOP);

        $this->asUser($sysop)->post('/settings.php', [
            'action' => 'savesettings_torrent',
            'download_support_passkey' => 'yes',
            'paid_torrent_enabled' => 'no',
            'randomhalfleech' => '5',
        ])
            ->assertOk()
            ->assertSee('Message', false);

        $this->createdSettingNames[] = 'torrent.download_support_passkey';
        $this->createdSettingNames[] = 'torrent.paid_torrent_enabled';
        $this->createdSettingNames[] = 'torrent.randomhalfleech';
    }

    public function testSaveMiscSettings()
    {
        $sysop = $this->makeUser('settings_save_misc', User::CLASS_SYSOP);

        $this->asUser($sysop)->post('/settings.php', [
            'action' => 'savesettings_misc',
            'protected_forum' => '1,2,3',
        ])
            ->assertOk()
            ->assertSee('Message', false);

        $this->createdSettingNames[] = 'misc.protected_forum';
        $this->createdSettingNames[] = 'misc.donation_custom';
    }

    public function testSaveMiscInvalidForumFormat()
    {
        $sysop = $this->makeUser('settings_misc_bad', User::CLASS_SYSOP);

        $this->asUser($sysop)->post('/settings.php', [
            'action' => 'savesettings_misc',
            'protected_forum' => 'abc',
        ])
            ->assertOk()
            ->assertSee('The format of forums is wrong, please check it again!', false);
    }
}