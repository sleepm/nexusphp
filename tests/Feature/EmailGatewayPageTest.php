<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the email-gateway.php migration
 * (EmailGatewayController::web) against a real database.
 */
class EmailGatewayPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $originalSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';

        // Pre-set the required settings so the first get_setting()/Setting::get()
        // call in the controller loads them (the static caches are per-process).
        $this->setSetting('basic.SITENAME', 'NexusPHP');
        $this->setSetting('basic.BASEURL', 'localhost');
        $this->setSetting('main.SITEEMAIL', 'nobody@gmail.com');
    }

    protected function tearDown(): void
    {
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
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
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
        $user = User::query()->forceCreate([
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

    private function callGateway(string $method, int $id, array $data = [])
    {
        return strtoupper($method) === 'POST'
            ? $this->post('/email-gateway.php?id=' . $id, $data)
            : $this->get('/email-gateway.php?id=' . $id);
    }

    // ------------------------------------------------------------- validation

    public function testInvalidIdRejected()
    {
        $this->callGateway('GET', 0)->assertStatus(400);
        $this->callGateway('GET', -5)->assertStatus(400);
    }

    public function testNoSuchUser()
    {
        $this->callGateway('GET', 999999)
            ->assertOk()
            ->assertSee('No such user.', false);
    }

    public function testNonStaffRejected()
    {
        $user = $this->makeUser('gateway_peasant', User::CLASS_USER);

        $this->callGateway('GET', $user->id)
            ->assertOk()
            ->assertSee('The gateway can only be used to e-mail staff members.', false);
    }

    // ------------------------------------------------------------------ form

    public function testFormRendersForModerator()
    {
        $mod = $this->makeUser('gateway_mod', User::CLASS_MODERATOR);

        $this->callGateway('GET', $mod->id)
            ->assertOk()
            ->assertSee('Send e-mail to gateway_mod', false)
            ->assertSee('action=email-gateway.php?id=' . $mod->id, false)
            ->assertSee('name=from', false)
            ->assertSee('name=from_email', false)
            ->assertSee('name=subject', false)
            ->assertSee('name=message', false);
    }

    public function testFormRendersForSysOp()
    {
        $sysop = $this->makeUser('gateway_sysop', User::CLASS_SYSOP);

        $this->callGateway('GET', $sysop->id)
            ->assertOk()
            ->assertSee('Send e-mail to gateway_sysop', false);
    }

    // -------------------------------------------------------------- submit

    public function testEmptyMessageRejected()
    {
        $mod = $this->makeUser('gateway_empty', User::CLASS_MODERATOR);

        $this->callGateway('POST', $mod->id, [
            'from' => 'Joe',
            'from_email' => 'joe@example.com',
            'subject' => 'Hi',
            'message' => '   ',
        ])
            ->assertOk()
            ->assertSee('No message text!', false);
    }

    public function testInvalidFromEmailRejected()
    {
        $mod = $this->makeUser('gateway_bademail', User::CLASS_MODERATOR);

        $this->callGateway('POST', $mod->id, [
            'from' => 'Joe',
            'from_email' => 'not-an-email',
            'subject' => 'Hi',
            'message' => 'A body',
        ])
            ->assertOk()
            ->assertSee('Invalid email address!', false);
    }

    public function testSendMailSuccess()
    {
        $mod = $this->makeUser('gateway_ok', User::CLASS_MODERATOR);

        $this->callGateway('POST', $mod->id, [
            'from' => 'Joe',
            'from_email' => 'joe@example.com',
            'subject' => 'Hello',
            'message' => 'A message for staff',
        ])
            ->assertOk()
            ->assertSee('E-mail successfully queued for delivery.', false);
    }

    public function testSendMailDefaults()
    {
        $mod = $this->makeUser('gateway_defaults', User::CLASS_MODERATOR);

        $this->callGateway('POST', $mod->id, [
            'from' => '',
            'from_email' => '',
            'subject' => '',
            'message' => 'Body only',
        ])
            ->assertOk()
            ->assertSee('E-mail successfully queued for delivery.', false);
    }
}
