<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the attachment.php / getattachment.php migration
 * (AttachmentController::webUpload / webDownload) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class AttachmentPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdAttachmentIds = [];

    private array $createdAttachmentFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        // basic.SITENAME is needed by Setting::getSiteName() when rendering
        // the guest layout; seed it before the first request so the Setting
        // static cache sees it.
        $this->setSetting('basic.SITENAME', 'TestSite');
        // deterministic attachment quotas so the upload/limit assertions hold
        // regardless of the site's real settings.
        $this->setSetting('attachment.enableattach', 'yes');
        $this->setSetting('attachment.classone', 1);
        $this->setSetting('attachment.countone', 10);
        $this->setSetting('attachment.sizeone', 1024);
        $this->setSetting('attachment.extone', 'txt, jpg, jpeg, png, gif');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdAttachmentFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        if ($this->createdAttachmentIds !== []) {
            DB::table('attachments')->whereIn('id', $this->createdAttachmentIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('attachments')->whereIn('userid', $ids)->delete();
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

    private array $originalSettings = [];

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

    private function authed(string $path, User $user, array $query = [])
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get($path . ($query ? '?' . http_build_query($query) : ''));
    }

    private function makeAttachment(User $owner, string $dlkey, string $location, array $overrides = []): Attachment
    {
        $attachment = Attachment::query()->create(array_merge([
            'userid' => $owner->id,
            'width' => 0,
            'added' => now(),
            'filename' => 'readme.txt',
            'filetype' => 'text/plain',
            'filesize' => 13,
            'location' => $location,
            'dlkey' => $dlkey,
            'downloads' => 0,
            'isimage' => 0,
            'thumb' => 0,
            'driver' => 'local',
        ], $overrides));
        $this->createdAttachmentIds[] = $attachment->id;

        return $attachment;
    }

    // ------------------------------------------------------------------ auth

    public function testAttachmentPageRequiresLogin()
    {
        $this->get('/attachment.php')->assertRedirect();
    }

    public function testGetAttachmentRequiresLogin()
    {
        $this->get('/getattachment.php?id=1&dlkey=abc')->assertRedirect();
    }

    // ---------------------------------------------------------- upload form

    public function testAttachmentUploadFormRenders()
    {
        $user = $this->makeUser('attach_form_user');

        $this->authed('/attachment.php', $user)
            ->assertOk()
            ->assertSee('name="file"', false)
            ->assertSee('name="altsize"', false)
            ->assertSee('name="submit"', false);
    }

    public function testAttachmentUploadFormRejectsParked()
    {
        $user = $this->makeUser('attach_parked_user', User::CLASS_USER);
        // parked is not mass-assignable; flip it directly so the auth guard sees it.
        DB::table('users')->where('id', $user->id)->update(['parked' => 'yes']);

        $this->authed('/attachment.php', $user)->assertForbidden();
    }

    // --------------------------------------------------------------- upload

    public function testAttachmentUploadStoresNonImageFile()
    {
        $user = $this->makeUser('attach_upload_user');
        app('auth')->forgetGuards();

        $response = $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->post('/attachment.php', [
                'file' => UploadedFile::fake()->create('hello.txt', 10, 'text/plain'),
            ]);

        $response->assertOk();

        $row = DB::table('attachments')
            ->where('userid', $user->id)
            ->where('filename', 'hello.txt')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($row);
        $this->assertEquals('local', $row->driver);
        $this->assertEquals(0, $row->isimage);
        $this->assertStringContainsString('parent.tag_extimage', $response->getContent());

        $file = ROOT_PATH . get_setting('attachment.httpdirectory', 'attachments') . '/' . trim($row->location, '/');
        $this->assertFileExists($file);
        $this->createdAttachmentFiles[] = $file;
    }

    // ------------------------------------------------------------- download

    public function testGetAttachmentRejectsMissingParameters()
    {
        $user = $this->makeUser('attach_dl_missing');

        $this->authed('/getattachment.php', $user, ['id' => 0])->assertNotFound();
        $this->authed('/getattachment.php', $user, ['id' => 1, 'dlkey' => ''])->assertNotFound();
    }

    public function testGetAttachmentRejectsUnknownAttachment()
    {
        $user = $this->makeUser('attach_dl_unknown');

        $this->authed('/getattachment.php', $user, ['id' => 999999, 'dlkey' => str_repeat('a', 32)])
            ->assertNotFound();
    }

    public function testGetAttachmentStreamsFileAndIncrementsDownloads()
    {
        $user = $this->makeUser('attach_dl_user');
        $dlkey = str_repeat('b', 32);
        $attachment = $this->makeAttachment($user, $dlkey, '202608/readme.txt');

        $file = ROOT_PATH . get_setting('attachment.httpdirectory', 'attachments') . '/202608/readme.txt';
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, 'Hello, world!');
        $this->createdAttachmentFiles[] = $file;

        $response = $this->authed('/getattachment.php', $user, ['id' => $attachment->id, 'dlkey' => $dlkey]);

        $response->assertOk();
        $this->assertEquals('Hello, world!', $response->streamedContent());
        $this->assertEquals('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertEquals(13, (int) $response->headers->get('Content-Length'));

        $this->assertEquals(1, DB::table('attachments')->where('id', $attachment->id)->value('downloads'));
    }
}
