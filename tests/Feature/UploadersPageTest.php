<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the uploaders.php migration
 * (UploadersController::web) against a real database.
 */
class UploadersPageTest extends TestCase
{
    protected array $createdUserIds = [];

    protected array $createdTorrentIds = [];

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
        if ($this->createdTorrentIds !== []) {
            DB::table('torrents')->whereIn('id', $this->createdTorrentIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $name, int $class): User
    {
        $user = User::query()->create([
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
            'added' => now()->format('Y-m-d H:i:s'),
            'last_access' => now()->format('Y-m-d H:i:s'),
            'commentpm' => 'no',
        ]);
        $this->createdUserIds[] = $user->id;

        return $user;
    }

    private function cookieFor(User $user): string
    {
        $tokenJson = json_encode(['user_id' => $user->id, 'expires' => time() + 3600]);
        $signature = hash_hmac('sha256', $tokenJson, $user->auth_key);
        return base64_encode($tokenJson . '.' . $signature);
    }

    private function getUploaders(User $user, string $query = '')
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/uploaders.php' . $query);
    }

    private function makeTorrent(User $owner, int $size = 1048576, ?string $added = null): int
    {
        $id = DB::table('torrents')->insertGetId([
            'name' => 'Uploaders Torrent ' . $owner->username,
            'small_descr' => 'a small description',
            'category' => 1,
            'owner' => $owner->id,
            'added' => $added ?? now()->format('Y-m-d H:i:s'),
            'visible' => 'yes',
            'banned' => 'no',
            'anonymous' => 'no',
            'sp_state' => 1,
            'promotion_time_type' => 0,
            'pos_state' => 'normal',
            'picktype' => 'normal',
            'size' => $size,
        ]);
        $this->createdTorrentIds[] = $id;

        return $id;
    }

    // ------------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->get('/uploaders.php')->assertRedirect();
    }

    public function testDeniedBelowUploader()
    {
        $user = $this->makeUser('uploaders_user', User::CLASS_ELITE_USER);

        $this->getUploaders($user)->assertForbidden();
    }

    // ------------------------------------------------------------------ view

    public function testUploaderSeesMonthlyUploadStats()
    {
        $uploader = $this->makeUser('uploaders_active', User::CLASS_UPLOADER);
        $idleUploader = $this->makeUser('uploaders_idle', User::CLASS_UPLOADER);
        $torrentId = $this->makeTorrent($uploader, 2097152);

        $this->getUploaders($uploader)
            ->assertOk()
            ->assertSee('uploaders_active', false)
            ->assertSee('uploaders_idle', false)
            ->assertSee('Uploaders Torrent uploaders_active', false)
            ->assertSee('details.php?id=' . $torrentId, false)
            ->assertSee('1', false);
    }

    public function testIdleUploaderShowsZeroCounts()
    {
        $uploader = $this->makeUser('uploaders_active2', User::CLASS_UPLOADER);
        $idleUploader = $this->makeUser('uploaders_idle2', User::CLASS_UPLOADER);
        $this->makeTorrent($uploader, 524288);

        $response = $this->getUploaders($uploader)
            ->assertOk()
            ->assertSee('uploaders_idle2', false);

        // the idle uploader row has zero size / zero count cells
        $this->assertMatchesRegularExpression('/uploaders_idle2.*<\/td>\s*<td class="colfollow">0<\/td>/s', $response->getContent());
    }

    public function testUploadsInOtherMonthAreNotCounted()
    {
        $uploader = $this->makeUser('uploaders_lastmonth', User::CLASS_UPLOADER);
        $this->makeTorrent($uploader, 1048576, now()->subMonth()->format('Y-m-d H:i:s'));

        $response = $this->getUploaders($uploader)
            ->assertOk()
            ->assertSee('uploaders_lastmonth', false);

        // the torrent was added last month, so the current-month row shows 0
        $this->assertMatchesRegularExpression('/uploaders_lastmonth.*<\/td>\s*<td class="colfollow">0<\/td>/s', $response->getContent());
    }

    public function testSortingParamsRender()
    {
        $uploader = $this->makeUser('uploaders_sort', User::CLASS_UPLOADER);

        foreach (['order=username', 'order=torrent_size', 'order=torrent_count'] as $query) {
            $this->getUploaders($uploader, '?' . $query)
                ->assertOk()
                ->assertSee('uploaders_sort', false);
        }
    }
}
