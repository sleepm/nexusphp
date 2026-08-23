<?php

namespace Tests\Feature;

use App\Models\Invite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the takeinvite.php migration
 * (InviteController::webTakeInvite) against a real database.
 */
class TakeInvitePageTest extends TestCase
{
    protected array $createdUserIds = [];
    protected array $originalSettings = [];
    protected array $seededEmailTables = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $this->setSetting('basic.SITENAME', 'NexusPHP');
        $this->setSetting('basic.BASEURL', 'localhost');
        $this->setSetting('main.SITEEMAIL', 'nobody@gmail.com');
        $this->setSetting('main.reportemail', 'nobody@gmail.com');
        $this->setSetting('main.invitesystem', 'yes');
        $this->setSetting('main.maxusers', '50000');
        $this->setSetting('main.invite_timeout', '7');
        $this->setSetting('system.is_invite_pre_email_and_username', 'no');
        $this->setSetting('smtp.smtptype', '');
        // legacy EmailBanned()/EmailAllowed() read the first row of these
        // tables with mysql_fetch_array(), which fails on an empty table.
        if (DB::table('bannedemails')->count() === 0) {
            DB::table('bannedemails')->insert(['value' => '']);
            $this->seededEmailTables[] = 'bannedemails';
        }
        if (DB::table('allowedemails')->count() === 0) {
            DB::table('allowedemails')->insert(['value' => '']);
            $this->seededEmailTables[] = 'allowedemails';
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->seededEmailTables as $table) {
            DB::table($table)->where('value', '')->delete();
        }
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
            DB::table('invites')->whereIn('inviter', $this->createdUserIds)->delete();
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

    private function makeUser(string $name, int $class = User::CLASS_POWER_USER, array $overrides = []): User
    {
        $user = User::query()->forceCreate(array_merge([
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
            'invites' => 5,
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

    private function postTakeInvite(User $user, array $data)
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->post('/takeinvite.php', $data);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'invitee_' . uniqid() . '@example.com',
            'hash' => 'permanent',
            'body' => 'Welcome to the site!',
        ], $overrides);
    }

    // ------------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->post('/takeinvite.php', $this->validPayload())->assertRedirect();
    }

    // ------------------------------------------------------------ validation

    public function testMissingEmailFails()
    {
        $user = $this->makeUser('takeinvite_noemail');

        $this->postTakeInvite($user, $this->validPayload(['email' => '  ']))
            ->assertOk()
            ->assertSee('You must enter an email address!', false);
    }

    public function testInvalidEmailFails()
    {
        $user = $this->makeUser('takeinvite_bademail');

        $this->postTakeInvite($user, $this->validPayload(['email' => 'not-an-email']))
            ->assertOk()
            ->assertSee('Invalid email address!', false);
    }

    public function testMissingBodyFails()
    {
        $user = $this->makeUser('takeinvite_nobody');

        $this->postTakeInvite($user, $this->validPayload(['body' => '  ']))
            ->assertOk()
            ->assertSee('Please add a personal message.', false);
    }

    public function testMissingHashFails()
    {
        $user = $this->makeUser('takeinvite_nohash');

        $this->postTakeInvite($user, $this->validPayload(['hash' => '']))
            ->assertOk()
            ->assertSee('Invitation failed!', false);
    }

    public function testEmailAlreadyInUseFails()
    {
        $user = $this->makeUser('takeinvite_emailuse');
        $existing = $this->makeUser('takeinvite_emailuse_existing', User::CLASS_USER);

        $this->postTakeInvite($user, $this->validPayload(['email' => $existing->email]))
            ->assertOk()
            ->assertSee('is already in use.', false);
    }

    public function testInvitationAlreadySentFails()
    {
        $user = $this->makeUser('takeinvite_dupsent');
        DB::table('invites')->insert([
            'inviter' => $user->id,
            'invitee' => 'dup@example.com',
            'hash' => md5('dup'),
            'time_invited' => now(),
            'valid' => Invite::VALID_YES,
        ]);

        $this->postTakeInvite($user, $this->validPayload(['email' => 'dup@example.com']))
            ->assertOk()
            ->assertSee('has already received an invitation', false);
    }

    // ------------------------------------------------------------ submission

    public function testPermanentInviteSucceeds()
    {
        $user = $this->makeUser('takeinvite_ok', User::CLASS_POWER_USER, ['invites' => 3]);
        $email = 'fresh_' . uniqid() . '@example.com';

        $this->postTakeInvite($user, $this->validPayload(['email' => $email]))
            ->assertRedirect('invite.php?id=' . $user->id . '&sent=1');

        $this->assertSame(2, DB::table('users')->where('id', $user->id)->value('invites'));
        $this->assertDatabaseHas('invites', [
            'inviter' => $user->id,
            'invitee' => $email,
        ]);
    }

    public function testTemporaryInviteSucceeds()
    {
        $user = $this->makeUser('takeinvite_tmp', User::CLASS_POWER_USER, ['invites' => 0]);
        $hash = md5('tmp-invite-' . uniqid());
        DB::table('invites')->insert([
            'inviter' => $user->id,
            'invitee' => '',
            'hash' => $hash,
            'time_invited' => now(),
            'valid' => Invite::VALID_YES,
            'expired_at' => now()->addDays(7),
        ]);

        $this->postTakeInvite($user, $this->validPayload(['email' => 'tmp_' . uniqid() . '@example.com', 'hash' => $hash]))
            ->assertRedirect('invite.php?id=' . $user->id . '&sent=1');

        $this->assertSame(0, DB::table('users')->where('id', $user->id)->value('invites'));
        $this->assertSame('1', (string) DB::table('invites')->where('hash', $hash)->value('valid'));
    }

    public function testUsedTemporaryHashFails()
    {
        $user = $this->makeUser('takeinvite_usedhash', User::CLASS_POWER_USER, ['invites' => 0]);
        $hash = md5('used-tmp-' . uniqid());
        $freeHash = md5('free-tmp-' . uniqid());
        DB::table('invites')->insert([
            'inviter' => $user->id,
            'invitee' => 'already@example.com',
            'hash' => $hash,
            'time_invited' => now(),
            'valid' => Invite::VALID_NO,
            'expired_at' => now()->addDays(7),
        ]);
        DB::table('invites')->insert([
            'inviter' => $user->id,
            'invitee' => '',
            'hash' => $freeHash,
            'time_invited' => now(),
            'valid' => Invite::VALID_YES,
            'expired_at' => now()->addDays(7),
        ]);

        $this->postTakeInvite($user, $this->validPayload(['email' => 'other_' . uniqid() . '@example.com', 'hash' => $hash]))
            ->assertOk()
            ->assertSee('Invitation failed!', false);
    }
}
