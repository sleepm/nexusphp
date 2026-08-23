<?php

namespace Tests\Feature;

use App\Models\Sub;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the downloadsubs.php migration
 * (DownloadSubsController::web) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes the users and subs this test created.
 */
class DownloadSubsPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdSubIds = [];

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
        if ($this->createdSubIds !== []) {
            DB::table('subs')->whereIn('id', $this->createdSubIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $username, array $overrides = []): User
    {
        $user = User::query()->create(array_merge([
            'username' => $username,
            'passhash' => str_repeat('a', 32),
            'secret' => 'secret',
            'auth_key' => 'authkey_' . $username,
            'email' => $username . '@example.com',
            'status' => 'confirmed',
            'enabled' => 'yes',
            'class' => User::CLASS_USER,
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

    private function requestSubs(User $user, string $uri)
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get($uri);
    }

    // ------------------------------------------------------------------ auth

    public function testGuestRedirectsToHome()
    {
        $this->get('/downloadsubs.php?subid=1&torrentid=1')->assertRedirect('/');
    }

    // ------------------------------------------------------------- validation

    public function testMissingParamsReturns400()
    {
        $user = $this->makeUser('downloadsubs_missing');
        $this->requestSubs($user, '/downloadsubs.php')->assertStatus(400);
    }

    public function testNotFoundSubReturns404()
    {
        $user = $this->makeUser('downloadsubs_notfound');
        $this->requestSubs($user, '/downloadsubs.php?subid=99999&torrentid=1')->assertStatus(404);
    }

    // ------------------------------------------------------------- successful

    public function testIncrementsHitsAndStreamsFile()
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64; rv:100.0) Gecko/20100101 Firefox/100.0';
        $user = $this->makeUser('downloadsubs_ok');

        $subsPath = trim((string) get_setting('main.subspath', 'subs'), '/');
        $dir = ROOT_PATH . $subsPath . '/42';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $sub = Sub::query()->create([
            'torrent_id' => 42,
            'lang_id' => 1,
            'title' => 'English',
            'filename' => 'sample.srt',
            'added' => now(),
            'size' => 12,
            'uppedby' => $user->id,
            'anonymous' => 'no',
            'hits' => 3,
            'ext' => 'srt',
        ]);
        $this->createdSubIds[] = $sub->id;

        $file = $dir . '/' . $sub->id . '.srt';
        file_put_contents($file, "1\n00:00:00,000 --> 00:00:01,000\nHello\n");

        $response = $this->requestSubs($user, '/downloadsubs.php?subid=' . $sub->id . '&torrentid=42');
        $response->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream')
            ->assertHeader('Content-Disposition', 'attachment; filename="sample.srt" ; charset=utf-8')
            ->assertSee('Hello');

        $this->assertEquals(4, (int) DB::table('subs')->where('id', $sub->id)->value('hits'));

        @unlink($file);
        @rmdir($dir);
    }
}