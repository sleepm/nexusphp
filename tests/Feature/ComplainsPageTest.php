<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ComplainsPageTest extends TestCase
{
    private array $createdUserIds = [];
    private array $createdComplainIds = [];
    private array $createdReplyIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        \Nexus\Database\NexusDB::cache_del('nexus_settings_in_laravel');
        \Nexus\Database\NexusDB::cache_del('nexus_settings_in_nexus');
        $redis = \Nexus\Database\NexusDB::redis();
        $lockKeys = $redis->keys('complains:lock:*');
        if ($lockKeys) {
            $redis->del($lockKeys);
        }
    }

    protected function tearDown(): void
    {
        if ($this->createdReplyIds !== []) {
            DB::table('complain_replies')->whereIn('id', $this->createdReplyIds)->delete();
        }
        if ($this->createdComplainIds !== []) {
            DB::table('complains')->whereIn('id', $this->createdComplainIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('users')->whereIn('id', $ids)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $username, $class = User::CLASS_USER, array $overrides = []): User
    {
        $user = User::query()->create(array_merge([
            'username' => $username,
            'passhash' => str_repeat('a', 32),
            'secret' => 'secret',
            'auth_key' => 'authkey_' . $username,
            'email' => $username . '@example.com',
            'status' => 'confirmed',
            'enabled' => 'yes',
            'class' => $class,
            'passkey' => md5($username),
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

    private function makeComplain(string $email, string $body = 'Test complaint body', int $answered = 0, string $uuid = ''): object
    {
        if ($uuid === '') {
            $uuid = \Illuminate\Support\Str::uuid()->toString();
        }
        $id = DB::table('complains')->insertGetId([
            'uuid' => $uuid,
            'email' => $email,
            'body' => $body,
            'added' => now(),
            'answered' => $answered,
            'ip' => '127.0.0.1',
        ]);
        $this->createdComplainIds[] = $id;
        return (object) ['id' => $id, 'uuid' => $uuid];
    }

    private function guestGet(string $uri)
    {
        app('auth')->forgetGuards();
        return $this->withCookie('c_lang_folder', 'en')->get($uri);
    }

    private function guestPost(string $uri, array $data)
    {
        app('auth')->forgetGuards();
        return $this->withCookie('c_lang_folder', 'en')->post($uri, $data);
    }

    private function adminGet(User $user, string $uri)
    {
        app('auth')->forgetGuards();
        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get($uri);
    }

    private function adminPost(User $user, string $uri, array $data)
    {
        app('auth')->forgetGuards();
        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->post($uri, $data);
    }

    private function userGet(User $user, string $uri)
    {
        app('auth')->forgetGuards();
        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get($uri);
    }

    // ------------------------------------------------------------------ guest compose

    public function testComplainsGuestCanViewCompose(): void
    {
        $this->guestGet('/complains.php')
            ->assertOk()
            ->assertSee('New complain');
    }

    // ------------------------------------------------------------------ auth

    public function testComplainsNonAdminLoggedInDenied(): void
    {
        $user = $this->makeUser('complain_plain_user');
        $this->userGet($user, '/complains.php')->assertForbidden();
    }

    // ------------------------------------------------------------------ admin list

    public function testComplainsAdminList(): void
    {
        $admin = $this->makeUser('complain_list_admin', User::CLASS_ADMINISTRATOR);
        $this->adminGet($admin, '/complains.php?action=list')
            ->assertOk()
            ->assertSee('Pending complaints');
    }

    public function testComplainsAdminListShowsPending(): void
    {
        $admin = $this->makeUser('complain_list_pending', User::CLASS_ADMINISTRATOR);
        $comp = $this->makeComplain('pending@example.com', 'pending issue', 0);

        $this->adminGet($admin, '/complains.php?action=list')
            ->assertOk()
            ->assertSee('pending@example.com')
            ->assertSee($comp->uuid);
    }

    // ------------------------------------------------------------------ create new

    public function testComplainsCreateNew(): void
    {
        $disabledUser = $this->makeUser('complain_disabled_user', User::CLASS_USER, [
            'enabled' => 'no',
            'email' => 'disabled@complain.test',
        ]);

        $response = $this->guestPost('/complains.php', [
            'action' => 'new',
            'email' => 'disabled@complain.test',
            'body' => 'I need help with my account',
        ])->assertRedirect();

        $this->assertDatabaseHas('complains', [
            'email' => 'disabled@complain.test',
            'body' => 'I need help with my account',
        ]);
    }

    public function testComplainsCreateNewFailsUnknownEmail(): void
    {
        $this->guestPost('/complains.php', [
            'action' => 'new',
            'email' => 'unknown@example.com',
            'body' => 'Help me',
        ])->assertOk()->assertSee('Bad email or empty complain');
    }

    public function testComplainsCreateNewFailsBlankBody(): void
    {
        $this->guestPost('/complains.php', [
            'action' => 'new',
            'email' => 'any@example.com',
            'body' => '',
        ])->assertOk()->assertSee('Bad email or empty complain');
    }

    // ------------------------------------------------------------------ view

    public function testComplainsViewByGuest(): void
    {
        $uuid = \Illuminate\Support\Str::uuid()->toString();
        $comp = $this->makeComplain('viewer@test.com', 'Visible content', 0, $uuid);

        $this->guestGet('/complains.php?action=view&id=' . $uuid)
            ->assertOk()
            ->assertSee('Visible content')
            ->assertSee('viewer@test.com');
    }

    public function testComplainsViewByAdmin(): void
    {
        $admin = $this->makeUser('complain_view_admin', User::CLASS_ADMINISTRATOR);
        $uuid = \Illuminate\Support\Str::uuid()->toString();
        $comp = $this->makeComplain('adminview@test.com', 'Admin sees this', 0, $uuid);

        $this->adminGet($admin, '/complains.php?action=view&id=' . $uuid)
            ->assertOk()
            ->assertSee('Admin sees this');
    }

    public function testComplainsInvalidUuidReturns403(): void
    {
        $this->guestGet('/complains.php?action=view&id=short-uuid')->assertForbidden();
        $this->guestGet('/complains.php?action=view&id=' . \Illuminate\Support\Str::uuid()->toString())->assertForbidden();
    }

    // ------------------------------------------------------------------ reply

    public function testComplainsAdminReply(): void
    {
        $admin = $this->makeUser('complain_reply_admin', User::CLASS_ADMINISTRATOR);
        $uuid = \Illuminate\Support\Str::uuid()->toString();
        $comp = $this->makeComplain('replytest@test.com', 'Original', 0, $uuid);

        $this->adminPost($admin, '/complains.php', [
            'action' => 'reply',
            'id' => $comp->id,
            'body' => 'Admin reply text',
        ])->assertRedirect();

        $this->assertDatabaseHas('complain_replies', [
            'complain' => $comp->id,
            'userid' => $admin->id,
            'body' => 'Admin reply text',
        ]);
    }

    // ------------------------------------------------------------------ toggle answered

    public function testComplainsAdminToggleAnswered(): void
    {
        $admin = $this->makeUser('complain_toggle_admin', User::CLASS_ADMINISTRATOR);
        $uuid = \Illuminate\Support\Str::uuid()->toString();
        $comp = $this->makeComplain('toggletest@test.com', 'Toggle me', 0, $uuid);

        $this->adminPost($admin, '/complains.php', [
            'action' => 'answered',
            'id' => $comp->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('complains', ['id' => $comp->id, 'answered' => 1]);
    }

    public function testComplainsAdminToggleUnanswered(): void
    {
        $admin = $this->makeUser('complain_untoggle_admin', User::CLASS_ADMINISTRATOR);
        $uuid = \Illuminate\Support\Str::uuid()->toString();
        $comp = $this->makeComplain('untoggle@test.com', 'Toggle me back', 1, $uuid);

        $this->adminPost($admin, '/complains.php', [
            'action' => 'unanswered',
            'id' => $comp->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('complains', ['id' => $comp->id, 'answered' => 0]);
    }

    // ------------------------------------------------------------------ guest reply

    public function testComplainsGuestReply(): void
    {
        $uuid = \Illuminate\Support\Str::uuid()->toString();
        $comp = $this->makeComplain('guestreply@test.com', 'Guest reply original', 0, $uuid);

        $this->guestPost('/complains.php', [
            'action' => 'reply',
            'id' => $comp->id,
            'body' => 'Guest reply text',
        ])->assertRedirect();

        $this->assertDatabaseHas('complain_replies', [
            'complain' => $comp->id,
            'userid' => 0,
            'body' => 'Guest reply text',
        ]);
    }

    public function testComplainsViewShowsReplies(): void
    {
        $uuid = \Illuminate\Support\Str::uuid()->toString();
        $comp = $this->makeComplain('replies@test.com', 'Original', 0, $uuid);
        DB::table('complain_replies')->insert([
            'complain' => $comp->id,
            'userid' => 0,
            'added' => now(),
            'body' => 'A reply from complainer',
            'ip' => '127.0.0.1',
        ]);

        $this->guestGet('/complains.php?action=view&id=' . $uuid)
            ->assertOk()
            ->assertSee('A reply from complainer')
            ->assertSee('Complainer');
    }
}