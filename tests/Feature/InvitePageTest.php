<?php

namespace Tests\Feature;

use App\Models\Invite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the invite.php migration
 * (InviteController::web) against a real database.
 */
class InvitePageTest extends TestCase
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
        // invite-system must be on for the ?type=new form to render; set it
        // before the first request so the Setting::get() static cache sees it.
        $this->setSetting('main.invitesystem', 'yes');
    }

    protected function tearDown(): void
    {
        if ($this->createdUserIds !== []) {
            DB::table('invites')->whereIn('inviter', $this->createdUserIds)->delete();
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        if ($this->originalSettings !== []) {
            foreach ($this->originalSettings as $name => $value) {
                DB::table('settings')->where('name', $name)->update(['value' => $value]);
            }
            app('cache')->forget('nexus_settings_in_laravel');
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

    private function makeUser(string $name, int $class = User::CLASS_USER, array $overrides = []): User
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

    private function getInvite(User $user, string $query = '')
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/invite.php' . $query);
    }

    // ------------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->get('/invite.php')->assertRedirect();
    }

    public function testDeniedWhenViewingOtherUsersInviteesWithoutPermission()
    {
        $owner = $this->makeUser('inv_owner');
        $viewer = $this->makeUser('inv_viewer');

        $this->getInvite($viewer, '?id=' . $owner->id)->assertOk()
            ->assertSee('Permission Denied!', false);
    }

    // ---------------------------------------------------------- invitee list

    public function testListsInvitedUsers()
    {
        $owner = $this->makeUser('inv_list_owner');
        $invitee = $this->makeUser('inv_list_invitee', User::CLASS_USER, ['invited_by' => $owner->id, 'status' => 'pending']);
        $confirmed = $this->makeUser('inv_list_confirmed', User::CLASS_USER, ['invited_by' => $owner->id, 'status' => 'confirmed']);

        $this->getInvite($owner, '?id=' . $owner->id)
            ->assertOk()
            ->assertSee('inv_list_invitee', false)
            ->assertSee('inv_list_invitee@example.com', false)
            ->assertSee('inv_list_confirmed', false)
            ->assertSee('Pending', false)
            ->assertSee('Confirmed', false)
            ->assertSee('action=takeconfirm.php', false)
            ->assertSee('name="conusr[]"', false);
    }

    public function testNoInviteesShowsEmptyMessage()
    {
        $owner = $this->makeUser('inv_empty_owner');

        $this->getInvite($owner, '?id=' . $owner->id)
            ->assertOk()
            ->assertSee('No invites yet.', false);
    }

    public function testFilterByStatus()
    {
        $owner = $this->makeUser('inv_filter_owner');
        $pending = $this->makeUser('inv_filter_pending', User::CLASS_USER, ['invited_by' => $owner->id, 'status' => 'pending']);
        $confirmed = $this->makeUser('inv_filter_confirmed', User::CLASS_USER, ['invited_by' => $owner->id, 'status' => 'confirmed']);

        $this->getInvite($owner, '?id=' . $owner->id . '&menu=invitee&status=confirmed')
            ->assertOk()
            ->assertSee('inv_filter_confirmed', false)
            ->assertDontSee('inv_filter_pending', false);
    }

    public function testFilterByEnabled()
    {
        $owner = $this->makeUser('inv_filteren_owner');
        $yes = $this->makeUser('inv_filteren_yes', User::CLASS_USER, ['invited_by' => $owner->id]);
        $no = $this->makeUser('inv_filteren_no', User::CLASS_USER, ['invited_by' => $owner->id, 'enabled' => 'no']);

        $this->getInvite($owner, '?id=' . $owner->id . '&menu=invitee&enabled=no')
            ->assertOk()
            ->assertSee('inv_filteren_no', false)
            ->assertDontSee('inv_filteren_yes', false);
    }

    // ------------------------------------------------------------- sent / tmp

    public function testSentInvitesList()
    {
        $owner = $this->makeUser('inv_sent_owner');
        $invitee = $this->makeUser('inv_sent_invitee', User::CLASS_USER, ['invited_by' => $owner->id]);
        DB::table('invites')->insert([
            'inviter' => $owner->id,
            'invitee' => 'invited@example.com',
            'hash' => md5('sent-hash'),
            'time_invited' => now(),
            'valid' => Invite::VALID_YES,
            'invitee_register_uid' => $invitee->id,
            'invitee_register_username' => 'inv_sent_invitee',
        ]);

        $this->getInvite($owner, '?id=' . $owner->id . '&menu=sent')
            ->assertOk()
            ->assertSee('invited@example.com', false)
            ->assertSee('Yes', false)
            ->assertSee('signup.php?type=invite&invitenumber=' . md5('sent-hash'), false);
    }

    public function testSentInvitesListShowsRegisteredUserWhenHashInvalid()
    {
        $owner = $this->makeUser('inv_sent2_owner');
        $invitee = $this->makeUser('inv_sent2_invitee', User::CLASS_USER, ['invited_by' => $owner->id]);
        DB::table('invites')->insert([
            'inviter' => $owner->id,
            'invitee' => 'used@example.com',
            'hash' => md5('used-hash'),
            'time_invited' => now(),
            'valid' => Invite::VALID_NO,
            'invitee_register_uid' => $invitee->id,
            'invitee_register_username' => 'inv_sent2_invitee',
        ]);

        $this->getInvite($owner, '?id=' . $owner->id . '&menu=sent')
            ->assertOk()
            ->assertSee('inv_sent2_invitee', false)
            ->assertSee('No', false);
    }

    public function testTmpInvitesList()
    {
        $owner = $this->makeUser('inv_tmp_owner');
        DB::table('invites')->insert([
            'inviter' => $owner->id,
            'invitee' => '',
            'hash' => md5('tmp-hash'),
            'time_invited' => now(),
            'valid' => Invite::VALID_YES,
            'expired_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        $this->getInvite($owner, '?id=' . $owner->id . '&menu=tmp')
            ->assertOk()
            ->assertSee('Temporary invite status', false)
            ->assertSee('Expired at', false)
            ->assertSee('Created at', false);
    }

    // --------------------------------------------------------- type=new (form)

    public function testNewInviteFormShowsForOwner()
    {
        $this->setSetting('main.invitesystem', 'yes');
        $owner = $this->makeUser('inv_new_owner', User::CLASS_POWER_USER);

        $this->getInvite($owner, '?id=' . $owner->id . '&type=new')
            ->assertOk()
            ->assertSee('Email Address', false)
            ->assertSee('action=takeinvite.php', false)
            ->assertSee("name='hash'", false)
            ->assertSee('name=email', false)
            ->assertSee('name=body', false);
    }

    public function testNewInviteFormDeniedForOtherUser()
    {
        $owner = $this->makeUser('inv_new_owner2');
        $viewer = $this->makeUser('inv_new_viewer');

        $this->getInvite($viewer, '?id=' . $owner->id . '&type=new')
            ->assertOk()
            ->assertSee('Permission Denied!', false);
    }

    public function testInvalidIdShowsError()
    {
        $user = $this->makeUser('inv_badid', User::CLASS_MODERATOR);

        $this->getInvite($user, '?id=999999')->assertOk()
            ->assertSee('Invalid id', false);
    }
}
