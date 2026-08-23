<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserCpPageTest extends TestCase
{
    protected array $createdUserIds = [];

    protected array $originalSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        $this->setSetting('basic.SITENAME', 'TestSite');
        $this->setSetting('main.browsecat', '4');
        $this->setSetting('main.specialcat', '4');
        $this->setSetting('main.site_language_enabled', ['en', 'chs', 'cht']);
        $this->setSetting('main.showshoutbox', 'no');
        $this->setSetting('main.showfunbox', 'no');
        $this->setSetting('main.enablebitbucket', 'no');
        $this->setSetting('main.enablenfo', 'no');
        $this->setSetting('main.showimdbinfo', 'no');
        $this->setSetting('main.showhotmovies', 'no');
        $this->setSetting('main.showclassicmovies', 'no');
        $this->setSetting('main.spsct', 'no');
        $this->setSetting('main.enableschool', 'no');
        $this->setSetting('advertisement.enablead', 'no');
        $this->setSetting('tweak.enabletooltip', 'no');
        $this->setSetting('tweak.enablelocation', 'no');
        $this->setSetting('bonus.prolinkpoint', '0');
        $this->setSetting('security.changeemail', 'yes');
        $this->setSetting('smtp.emailnotify', 'no');
        $this->setSetting('smtp.smtptype', 'none');
        $this->setSetting('seed_box.enabled', 'no');
    }

    protected function tearDown(): void
    {
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        if ($this->originalSettings !== []) {
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
            'last_pm' => now()->subMinutes(5),
            'info' => '',
            'acceptpms' => 'yes',
            'deletepms' => 'no',
            'savepms' => 'no',
            'gender' => 'N/A',
            'country' => 0,
            'download' => 0,
            'upload' => 0,
            'isp' => 0,
            'tracker_url_id' => 0,
            'notifs' => '',
            'stylesheet' => 0,
            'fontsize' => 'medium',
            'lang' => 0,
            'torrentsperpage' => 50,
            'showhot' => 'no',
            'showclassic' => 'no',
            'tooltip' => 'off',
            'noad' => 'no',
            'timetype' => 'timeadded',
            'appendsticky' => 'no',
            'appendnew' => 'no',
            'appendpromotion' => 'off',
            'appendpicked' => 'no',
            'dlicon' => 'no',
            'bmicon' => 'no',
            'showcomnum' => 'no',
            'showlastcom' => 'yes',
            'pmnum' => 20,
            'sbnum' => 70,
            'sbrefresh' => 120,
            'showimdb' => 'no',
            'showdescription' => 'no',
            'shownfo' => 'no',
            'showsmalldescr' => 'no',
            'showcomment' => 'no',
            'topicsperpage' => 20,
            'postsperpage' => 10,
            'avatars' => 'yes',
            'signatures' => 'yes',
            'showlastpost' => 'yes',
            'clicktopic' => 'firstpage',
            'privacy' => 'normal',
            'parked' => 'no',
            'invites' => 1,
            'two_step_secret' => '',
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

    private function callUserCp(User $user, string $method, array $data = [])
    {
        app('auth')->forgetGuards();
        $request = $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en');

        if (strtoupper($method) === 'POST') {
            return $request->post('/usercp.php', $data);
        }
        return $request->get('/usercp.php' . ($data ? '?' . http_build_query($data) : ''));
    }

    // ------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->get('/usercp.php')->assertRedirect();
    }

    // ------------------------------------------------------------ home

    public function testHomeRenders()
    {
        $user = $this->makeUser('usercp_home', User::CLASS_USER);

        $this->callUserCp($user, 'GET')
            ->assertOk()
            ->assertSee('User CP Home', false)
            ->assertSee($user->email, false);
    }

    // ------------------------------------------------------------ personal

    public function testPersonalRenders()
    {
        $user = $this->makeUser('usercp_personal', User::CLASS_USER);

        $this->callUserCp($user, 'GET', ['action' => 'personal'])
            ->assertOk()
            ->assertSee('Personal Settings', false);
    }

    public function testPersonalSave()
    {
        $user = $this->makeUser('usercp_persave', User::CLASS_USER);

        $this->callUserCp($user, 'POST', [
            'action' => 'personal',
            'type' => 'save',
            'parked' => 'no',
            'acceptpms' => 'yes',
            'gender' => 'N/A',
            'info' => 'Hello world',
        ])->assertRedirect();

        $user->refresh();
        $this->assertEquals('Hello world', $user->info);
    }

    // ------------------------------------------------------------ tracker

    public function testTrackerRenders()
    {
        $user = $this->makeUser('usercp_tracker', User::CLASS_USER);

        $this->callUserCp($user, 'GET', ['action' => 'tracker'])
            ->assertOk()
            ->assertSee('Tracker Settings', false);
    }

    public function testTrackerSave()
    {
        $user = $this->makeUser('usercp_trksave', User::CLASS_USER);

        $this->callUserCp($user, 'POST', [
            'action' => 'tracker',
            'type' => 'save',
            'fontsize' => 'large',
            'torrentsperpage' => '75',
            'timetype' => 'timeadded',
        ])->assertRedirect();

        $user->refresh();
        $this->assertEquals('large', $user->fontsize);
        $this->assertEquals(75, $user->torrentsperpage);
    }

    // ------------------------------------------------------------ forum

    public function testForumRenders()
    {
        $user = $this->makeUser('usercp_forum', User::CLASS_USER);

        $this->callUserCp($user, 'GET', ['action' => 'forum'])
            ->assertOk()
            ->assertSee('Forum Settings', false);
    }

    public function testForumSave()
    {
        $user = $this->makeUser('usercp_frmsave', User::CLASS_USER);

        $this->callUserCp($user, 'POST', [
            'action' => 'forum',
            'type' => 'save',
            'topicsperpage' => '30',
            'postsperpage' => '15',
            'clicktopic' => 'lastpage',
        ])->assertRedirect();

        $user->refresh();
        $this->assertEquals(30, $user->topicsperpage);
        $this->assertEquals(15, $user->postsperpage);
        $this->assertEquals('lastpage', $user->clicktopic);
    }

    // ------------------------------------------------------------ security

    public function testSecurityRenders()
    {
        $user = $this->makeUser('usercp_security', User::CLASS_USER);

        $this->callUserCp($user, 'GET', ['action' => 'security'])
            ->assertOk()
            ->assertSee('Security Settings', false);
    }

    // ------------------------------------------------------------ invalid

    public function testInvalidAction()
    {
        $user = $this->makeUser('usercp_badact', User::CLASS_USER);

        $this->callUserCp($user, 'GET', ['action' => 'invalid_action'])
            ->assertStatus(400);
    }
}