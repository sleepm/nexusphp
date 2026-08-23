<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the takecontact.php migration
 * (ContactStaffController::webTakeContact) against a real database.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class TakeContactPageTest extends TestCase
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

        // Seed basic.SITENAME / basic.BASEURL from the legacy defaults so the
        // controller's Setting::getSiteName()/getBaseUrl() calls resolve in a
        // bare test database (mirrors SettingsPageTest::setUp).
        require ROOT_PATH . 'config/allconfig.php';
        $this->seedSetting('basic', $BASIC);
        Cache::forget('nexus_settings_in_laravel');
        Cache::forget('nexus_settings_in_nexus');
    }

    private function seedSetting(string $prefix, array $values): void
    {
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

    protected function tearDown(): void
    {
        if ($this->createdUserIds !== []) {
            DB::table('staffmessages')->whereIn('sender', $this->createdUserIds)->delete();
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
            DB::table('settings')->whereIn('name', $this->createdSettingNames)
                ->whereNotIn('name', array_keys($this->originalSettings))
                ->delete();
        }
        Cache::forget('nexus_settings_in_laravel');
        Cache::forget('nexus_settings_in_nexus');
        parent::tearDown();
    }

    private function makeUser(string $name, int $class, array $overrides = []): User
    {
        $user = User::query()->create(array_merge([
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
            'last_staffmsg' => now()->subMinutes(5),
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

    private function postTakeContact(User $user, array $data)
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->post('/takecontact.php', $data);
    }

    private function getTakeContact(User $user)
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/takecontact.php');
    }

    // ------------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->get('/takecontact.php')->assertRedirect();
    }

    public function testPostRequiresLogin()
    {
        $this->post('/takecontact.php', ['subject' => 'S', 'body' => 'B'])->assertRedirect();
    }

    public function testRejectsGetMethod()
    {
        $user = $this->makeUser('takecontact_get_user', User::CLASS_USER);

        $this->getTakeContact($user)->assertStatus(400);
    }

    // ------------------------------------------------------------ validation

    public function testRejectsEmptyBody()
    {
        $user = $this->makeUser('takecontact_empty_body', User::CLASS_USER);

        $this->postTakeContact($user, ['subject' => 'Subject', 'body' => '   '])
            ->assertStatus(400);
    }

    public function testRejectsEmptySubject()
    {
        $user = $this->makeUser('takecontact_empty_subject', User::CLASS_USER);

        $this->postTakeContact($user, ['subject' => '  ', 'body' => 'Body'])
            ->assertStatus(400);
    }

    // ------------------------------------------------------------- flood limit

    public function testFloodProtectionBlocksRecentSubmission()
    {
        $user = $this->makeUser('takecontact_flood_user', User::CLASS_USER);
        // last_staffmsg is not mass-assignable, set it directly.
        $user->last_staffmsg = now();
        $user->save();

        $this->postTakeContact($user, ['subject' => 'Subject', 'body' => 'Body'])
            ->assertStatus(400);

        $this->assertSame(0, DB::table('staffmessages')->where('sender', $user->id)->count());
    }

    public function testModeratorSkipsFloodProtection()
    {
        $mod = $this->makeUser('takecontact_mod', User::CLASS_MODERATOR);
        // last_staffmsg is not mass-assignable, set it directly.
        $mod->last_staffmsg = now();
        $mod->save();

        $this->postTakeContact($mod, ['subject' => 'Subject', 'body' => 'Body'])
            ->assertOk();

        $this->assertSame(1, DB::table('staffmessages')->where('sender', $mod->id)->count());
    }

    // ------------------------------------------------------------ submission

    public function testSuccessfulSubmissionCreatesStaffMessage()
    {
        $user = $this->makeUser('takecontact_ok_user', User::CLASS_USER);

        $this->postTakeContact($user, [
            'subject' => 'A subject',
            'body' => 'A message body',
        ])->assertOk();

        $this->assertSame('A subject', DB::table('staffmessages')->where('sender', $user->id)->value('subject'));
        $this->assertSame('A message body', DB::table('staffmessages')->where('sender', $user->id)->value('msg'));
        $this->assertNotNull(DB::table('users')->where('id', $user->id)->value('last_staffmsg'));
    }

    public function testRedirectsToReturnto()
    {
        $user = $this->makeUser('takecontact_returnto_user', User::CLASS_USER);

        $this->postTakeContact($user, [
            'subject' => 'Subject',
            'body' => 'Body',
            'returnto' => 'forums.php',
        ])->assertRedirect('forums.php');

        $this->assertSame(1, DB::table('staffmessages')->where('sender', $user->id)->count());
    }

    public function testSuccessPageRendered()
    {
        $user = $this->makeUser('takecontact_success_user', User::CLASS_USER);

        $this->postTakeContact($user, ['subject' => 'Subject', 'body' => 'Body'])
            ->assertOk()
            ->assertSee('Succeeded', false)
            ->assertSee('Message was succesfully sent!', false);
    }
}
