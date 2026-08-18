<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the bitbucketlog.php migration
 * (BitbucketController::web) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class BitbucketLogPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdBitbucketIds = [];

    private array $createdBitbucketFiles = [];

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
        foreach ($this->createdBitbucketFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        if ($this->createdBitbucketIds !== []) {
            DB::table('bitbucket')->whereIn('id', $this->createdBitbucketIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('bitbucket')->whereIn('owner', $ids)->delete();
            DB::table('users')->whereIn('id', $ids)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $username, $class = User::CLASS_ADMINISTRATOR, array $overrides = []): User
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

    private function getBitbucketLog(User $user, string $query = '')
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/bitbucketlog.php' . $query);
    }

    private function makeBitbucketEntry(User $owner, string $name, string $added): int
    {
        $id = DB::table('bitbucket')->insertGetId([
            'owner' => $owner->id,
            'name' => $name,
            'added' => $added,
            'public' => '0',
        ]);
        $this->createdBitbucketIds[] = $id;

        $file = ROOT_PATH . get_setting('main.bitbucket', 'bitbucket') . '/' . $name;
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, 'dummy-image-bytes');
        $this->createdBitbucketFiles[] = $file;

        return $id;
    }

    // ------------------------------------------------------------------ auth

    public function testBitbucketLogRequiresLogin()
    {
        $this->get('/bitbucketlog.php')->assertRedirect();
    }

    public function testBitbucketLogRejectsNonAdmin()
    {
        $mod = $this->makeUser('bitbucket_mod', User::CLASS_MODERATOR);

        $this->getBitbucketLog($mod)->assertForbidden();
    }

    // -------------------------------------------------------------- rendering

    public function testBitbucketLogRendersEmptyList()
    {
        $admin = $this->makeUser('bitbucket_empty_admin');

        $this->getBitbucketLog($admin)
            ->assertOk()
            ->assertSee('BitBucket Log')
            ->assertSee('BitBucket Log is empty')
            ->assertSee('Total Images Stored: 0');
    }

    public function testBitbucketLogRendersEntryList()
    {
        $admin = $this->makeUser('bitbucket_list_admin');
        $owner = $this->makeUser('bitbucket_list_owner', User::CLASS_USER);
        $id = $this->makeBitbucketEntry($owner, 'screenshot-one.png', '2026-08-18 10:20:30');

        $this->getBitbucketLog($admin)
            ->assertOk()
            ->assertSee('screenshot-one.png')
            ->assertSee('Total Images Stored: 1')
            ->assertSee('Uploaded by:')
            ->assertSee('href=?delete=' . $id, false);
    }

    // --------------------------------------------------------------- deletion

    public function testBitbucketLogDeleteRemovesRowAndFile()
    {
        $admin = $this->makeUser('bitbucket_del_admin');
        $owner = $this->makeUser('bitbucket_del_owner', User::CLASS_USER);
        $id = $this->makeBitbucketEntry($owner, 'to-be-removed.png', '2026-08-18 10:20:30');

        $file = ROOT_PATH . get_setting('main.bitbucket', 'bitbucket') . '/to-be-removed.png';
        $this->assertFileExists($file);

        $this->getBitbucketLog($admin, '?delete=' . $id)
            ->assertOk()
            ->assertSee('BitBucket Log is empty');

        $this->assertNull(DB::table('bitbucket')->where('id', $id)->first());
        $this->assertFileDoesNotExist($file);
    }

    public function testBitbucketLogDeleteIgnoresInvalidId()
    {
        $admin = $this->makeUser('bitbucket_invalid_admin');

        $this->getBitbucketLog($admin, '?delete=not-a-number')
            ->assertOk()
            ->assertSee('BitBucket Log is empty');
    }
}
