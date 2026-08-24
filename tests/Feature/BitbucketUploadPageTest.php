<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * End-to-end coverage of the bitbucket-upload.php migration
 * (BitbucketController::webUpload) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class BitbucketUploadPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdBitbucketFiles = [];

    private array $originalSettings = [];

    private array $bitbucketInitialFiles = [];

    /**
     * Bitbucket storage directory for the test. Read straight from the DB (not
     * via get_setting()) to avoid populating get_setting()'s static cache in
     * setUp, which would make later setSetting() changes invisible to it.
     */
    private function bitbucketDir(): string
    {
        return ROOT_PATH . (DB::table('settings')->where('name', 'main.bitbucket')->value('value') ?: 'bitbucket');
    }

    private function bitbucketFiles(): array
    {
        $dir = $this->bitbucketDir();
        $files = [];
        if (is_dir($dir)) {
            foreach (scandir($dir) as $entry) {
                if ($entry !== '.' && $entry !== '..' && is_file("$dir/$entry")) {
                    $files[] = "$dir/$entry";
                }
            }
        }
        return $files;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        $this->setSetting('basic.SITENAME', 'TestSite');
        $this->setSetting('main.enablebitbucket', 'yes');
        $this->setSetting('main.bitbucket', 'bitbucket');
        $this->bitbucketInitialFiles = $this->bitbucketFiles();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdBitbucketFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        foreach (array_diff($this->bitbucketFiles(), $this->bitbucketInitialFiles) as $file) {
            @unlink($file);
        }
        if ($this->createdUserIds !== []) {
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('bitbucket')->whereIn('owner', $ids)->delete();
            DB::table('users')->whereIn('id', $ids)->delete();
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

    private function authed(string $path, User $user, array $data = [], string $method = 'get')
    {
        app('auth')->forgetGuards();
        $with = $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en');

        return $method === 'post' ? $with->post($path, $data) : $with->get($path);
    }

    /**
     * A real (GD-generated) image upload that passes getimagesize().
     */
    private function imageUpload(string $filename = 'avatar.png', int $width = 300, int $height = 200): UploadedFile
    {
        $img = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($img, 255, 0, 0);
        imagefill($img, 0, 0, $color);
        $tmp = tempnam(sys_get_temp_dir(), 'bbimg');
        imagepng($img, $tmp);
        imagedestroy($img);
        $content = (string) file_get_contents($tmp);
        @unlink($tmp);

        return UploadedFile::fake()->createWithContent($filename, $content);
    }

    // ------------------------------------------------------------------ auth

    public function testBitbucketUploadRequiresLogin()
    {
        $this->get('/bitbucket-upload.php')->assertRedirect();
    }

    public function testBitbucketUploadRejectsParked()
    {
        $user = $this->makeUser('bbupload_parked');
        DB::table('users')->where('id', $user->id)->update(['parked' => 'yes']);

        $this->authed('/bitbucket-upload.php', $user)->assertForbidden();
    }

    /**
     * Runs in a separate process because it flips main.enablebitbucket after
     * get_setting()'s static cache may already have been populated by an
     * earlier request in the same process.
     */
    #[RunInSeparateProcess]
    public function testBitbucketUploadRejectsWhenDisabled()
    {
        $this->setSetting('main.enablebitbucket', 'no');
        $user = $this->makeUser('bbupload_disabled');

        $this->authed('/bitbucket-upload.php', $user)
            ->assertOk()
            ->assertSee('Permission denied');
    }

    // -------------------------------------------------------------- rendering

    public function testBitbucketUploadFormRenders()
    {
        $user = $this->makeUser('bbupload_form');

        $this->authed('/bitbucket-upload.php', $user)
            ->assertOk()
            ->assertSee('AVATAR Upload')
            ->assertSee('name="file"', false)
            ->assertSee('name="public"', false)
            ->assertSee('Maximum file size');
    }

    // ---------------------------------------------------------------- uploads

    public function testBitbucketUploadRejectsEmptyFile()
    {
        $user = $this->makeUser('bbupload_empty');

        $this->authed('/bitbucket-upload.php', $user, [
            'file' => UploadedFile::fake()->create('avatar.png', 0),
        ], 'post')
            ->assertOk()
            ->assertSee('Nothing received');
    }

    public function testBitbucketUploadRejectsOversizedFile()
    {
        $user = $this->makeUser('bbupload_large');

        $this->authed('/bitbucket-upload.php', $user, [
            'file' => UploadedFile::fake()->create('avatar.png', 300),
        ], 'post')
            ->assertOk()
            ->assertSee('file is too large');
    }

    // The legacy "bad file name" (path traversal) guard is not reachable via
    // the HTTP test stack: Symfony's UploadedFile already strips directory
    // components from client-supplied original names, so basename() always
    // equals the filename.

    public function testBitbucketUploadRejectsDuplicateFilename()
    {
        $user = $this->makeUser('bbupload_dup');
        $bitbucketDir = $this->bitbucketDir();
        @mkdir($bitbucketDir, 0777, true);
        $existing = $bitbucketDir . '/existing.png';
        file_put_contents($existing, 'stale');
        $this->createdBitbucketFiles[] = $existing;

        $this->authed('/bitbucket-upload.php', $user, [
            'file' => $this->imageUpload('existing.png'),
        ], 'post')
            ->assertOk()
            ->assertSee('already exists');
    }

    public function testBitbucketUploadRejectsInvalidImage()
    {
        $user = $this->makeUser('bbupload_badimg');

        $response = $this->authed('/bitbucket-upload.php', $user, [
            'file' => UploadedFile::fake()->create('avatar.txt', 10),
        ], 'post');

        $response->assertOk()
            ->assertSee('Invalid extension');
    }

    public function testBitbucketUploadStoresImageAndUpdatesAvatar()
    {
        $user = $this->makeUser('bbupload_ok');
        $bitbucketDir = $this->bitbucketDir();
        @mkdir($bitbucketDir, 0777, true);

        $response = $this->authed('/bitbucket-upload.php', $user, [
            'file' => $this->imageUpload('my-avatar.png'),
        ], 'post');

        $response->assertOk()
            ->assertSee('Use the following URL', false)
            ->assertSee('my-avatar.png');

        $row = DB::table('bitbucket')
            ->where('owner', $user->id)
            ->where('name', 'my-avatar.png')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($row);
        $this->assertEquals('0', $row->public);

        $stored = $bitbucketDir . '/my-avatar.png';
        $this->assertFileExists($stored);
        $this->createdBitbucketFiles[] = $stored;

        $this->assertStringContainsString(
            '/bitbucket/my-avatar.png',
            (string) DB::table('users')->where('id', $user->id)->value('avatar')
        );
    }

    public function testBitbucketUploadStoresPublicFlag()
    {
        $user = $this->makeUser('bbupload_public');
        $bitbucketDir = $this->bitbucketDir();
        @mkdir($bitbucketDir, 0777, true);

        $this->authed('/bitbucket-upload.php', $user, [
            'file' => $this->imageUpload('shared-avatar.png'),
            'public' => 'yes',
        ], 'post')->assertOk();

        $row = DB::table('bitbucket')
            ->where('owner', $user->id)
            ->where('name', 'shared-avatar.png')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($row);
        $this->assertEquals('1', $row->public);

        $this->createdBitbucketFiles[] = $bitbucketDir . '/shared-avatar.png';
    }
}
