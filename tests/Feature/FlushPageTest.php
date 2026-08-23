<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the takeflush.php migration
 * (FlushController::web) against a real database.
 */
class FlushPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdPeerIds = [];

    private array $originalSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';

        // Pre-set the required settings so the first get_setting() call in
        // the controller loads them.  The get_setting() static cache is
        // per-process so we can only mutate the DB before the first fetch.
        $this->setSetting('basic.SITENAME', 'NexusPHP');
        $this->setSetting('basic.BASEURL', 'localhost');
        $this->setSetting('main.anninterthree', '3600');
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
        if ($this->createdPeerIds !== []) {
            DB::table('peers')->whereIn('id', $this->createdPeerIds)->delete();
        }
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

    private function makeUser(string $name, int $class, array $overrides = []): User
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
            'warned' => 'no',
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

    private function getFlush(User $user, int $id)
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/takeflush.php?id=' . $id);
    }

    private function makePeer(int $userId, string $lastAction): int
    {
        $id = DB::table('peers')->insertGetId([
            'torrent' => 1,
            'peer_id' => random_bytes(20),
            'ip' => '127.0.0.1',
            'port' => 51413,
            'uploaded' => 0,
            'downloaded' => 0,
            'to_go' => 0,
            'seeder' => 'no',
            'started' => now(),
            'last_action' => $lastAction,
            'prev_action' => $lastAction,
            'connectable' => 'yes',
            'userid' => $userId,
            'agent' => 'PHPUnit',
            'finishedat' => 0,
            'downloadoffset' => 0,
            'uploadoffset' => 0,
            'passkey' => md5((string) $userId),
        ]);
        $this->createdPeerIds[] = $id;

        return $id;
    }

    private function ghostDeadline(): string
    {
        $anninterthree = (int) get_setting('main.anninterthree');
        $deadtime = time() - (int) floor($anninterthree * 1.3);

        return date('Y-m-d H:i:s', $deadtime - 60);
    }

    // ------------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->get('/takeflush.php?id=1')->assertRedirect();
    }

    // ----------------------------------------------------------- permissions

    public function testUserCannotFlushOthers()
    {
        $user = $this->makeUser('flush_user', User::CLASS_USER);
        $target = $this->makeUser('flush_target', User::CLASS_USER);
        $peerId = $this->makePeer($target->id, $this->ghostDeadline());

        $this->getFlush($user, $target->id)
            ->assertOk()
            ->assertSee('Failed', false)
            ->assertSee('You can only clean your own ghost torrents', false);

        $this->assertDatabaseHas('peers', ['id' => $peerId]);
    }

    public function testModeratorCanFlushOthers()
    {
        $mod = $this->makeUser('flush_mod', User::CLASS_MODERATOR);
        $target = $this->makeUser('flush_mod_target', User::CLASS_USER);
        $peerId = $this->makePeer($target->id, $this->ghostDeadline());

        $this->getFlush($mod, $target->id)
            ->assertOk()
            ->assertSee('Success', false)
            ->assertSee('ghost torrents were sucessfully cleaned.', false);

        $this->assertDatabaseMissing('peers', ['id' => $peerId]);
    }

    // -------------------------------------------------------------- flushing

    public function testUserFlushesOwnGhostPeers()
    {
        $user = $this->makeUser('flush_own', User::CLASS_USER);

        $ghost1 = $this->makePeer($user->id, $this->ghostDeadline());
        $ghost2 = $this->makePeer($user->id, date('Y-m-d H:i:s', time() - 86400));
        $fresh = $this->makePeer($user->id, date('Y-m-d H:i:s'));

        $this->getFlush($user, $user->id)
            ->assertOk()
            ->assertSee('Success', false)
            ->assertSee('2 ghost torrents were sucessfully cleaned.', false);

        $this->assertDatabaseMissing('peers', ['id' => $ghost1]);
        $this->assertDatabaseMissing('peers', ['id' => $ghost2]);
        $this->assertDatabaseHas('peers', ['id' => $fresh]);
    }

    public function testOtherUsersPeersUntouched()
    {
        $user = $this->makeUser('flush_isolated', User::CLASS_USER);
        $other = $this->makeUser('flush_isolated_other', User::CLASS_USER);
        $otherGhost = $this->makePeer($other->id, $this->ghostDeadline());

        $this->getFlush($user, $user->id)->assertOk();

        $this->assertDatabaseHas('peers', ['id' => $otherGhost]);
    }

    public function testInvalidIdRejected()
    {
        $user = $this->makeUser('flush_badid', User::CLASS_MODERATOR);

        $this->getFlush($user, 0)->assertStatus(400);
        $this->getFlush($user, -5)->assertStatus(400);
    }
}
