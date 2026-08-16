<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Rhilip\Bencode\Bencode;
use Tests\TestCase;

/**
 * End-to-end coverage of the takeupload.php migration
 * (UploadController::webTakeUpload) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class TakeUploadPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdTorrentIds = [];

    private array $createdOfferIds = [];

    private array $createdMessageIds = [];

    private array $tempFiles = [];

    private int $categoryId = 401;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        Queue::fake();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $tempFile) {
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        }
        $cleanup = function (callable $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                error_log('TakeUploadPageTest cleanup failed: ' . $e->getMessage());
            }
        };
        if ($this->createdMessageIds !== []) {
            $cleanup(fn () => DB::table('messages')->whereIn('id', $this->createdMessageIds)->delete());
        }
        if ($this->createdOfferIds !== []) {
            $cleanup(fn () => DB::table('offervotes')->whereIn('offerid', $this->createdOfferIds)->delete());
            $cleanup(fn () => DB::table('offers')->whereIn('id', $this->createdOfferIds)->delete());
        }
        if ($this->createdTorrentIds !== []) {
            $cleanup(fn () => DB::table('files')->whereIn('torrent', $this->createdTorrentIds)->delete());
            $cleanup(fn () => DB::table('torrent_extras')->whereIn('torrent_id', $this->createdTorrentIds)->delete());
            $cleanup(fn () => DB::table('torrent_tags')->whereIn('torrent_id', $this->createdTorrentIds)->delete());
            $cleanup(fn () => DB::table('torrent_operation_logs')->whereIn('torrent_id', $this->createdTorrentIds)->delete());
            $saveDir = getFullDirectory(get_setting('main.torrent_dir'));
            foreach ($this->createdTorrentIds as $id) {
                @unlink("$saveDir/$id.torrent");
            }
            $cleanup(fn () => DB::table('torrents')->whereIn('id', $this->createdTorrentIds)->delete());
        }
        if ($this->createdUserIds !== []) {
            $ids = array_values(array_unique($this->createdUserIds));
            $cleanup(fn () => DB::table('bonus_logs')->whereIn('uid', $ids)->delete());
            $cleanup(fn () => DB::table('torrents')->whereIn('owner', $ids)->delete());
            $cleanup(fn () => DB::table('users')->whereIn('id', $ids)->delete());
        }
        parent::tearDown();
    }

    private function makeUser(string $username, $class = User::CLASS_POWER_USER, array $overrides = []): User
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

    private function postUpload(User $user, array $data)
    {
        app('auth')->forgetGuards();
        return $this->withCookie('c_secure_pass', $this->cookieFor($user))->post('/takeupload.php', $data);
    }

    private function makeTorrentFile(string $name = 'e2e-takeupload'): string
    {
        $content = Bencode::encode([
            'announce' => 'http://tracker.example.com/announce',
            'info' => [
                'name' => $name,
                'piece length' => 262144,
                'pieces' => str_repeat('a', 60),
                'length' => 786432,
            ],
        ]);
        $tmp = tempnam(sys_get_temp_dir(), 'torrent_');
        $path = $tmp . '.torrent';
        @rename($tmp, $path);
        $this->tempFiles[] = $path;
        file_put_contents($path, $content);

        return $path;
    }

    private function uploadData(string $torrentPath, array $extra = []): array
    {
        return array_merge([
            'name' => 'E2E TakeUpload Torrent',
            'small_descr' => 'a small description',
            'descr' => 'an interesting description for the torrent',
            'type' => $this->categoryId,
            'url' => 'https://www.imdb.com/title/tt0111161/',
            'uplver' => 'no',
        ], $extra, [
            'file' => new UploadedFile($torrentPath, basename($torrentPath), 'application/x-bittorrent', null, true),
        ]);
    }

    // ------------------------------------------------------------------ auth

    public function testTakeUploadRequiresLogin()
    {
        $torrentPath = $this->makeTorrentFile();
        $this->post('/takeupload.php', $this->uploadData($torrentPath))->assertRedirect();
    }

    // ------------------------------------------------------------------ valid upload

    public function testTakeUploadCreatesTorrent()
    {
        $user = $this->makeUser('takeupload_ok', User::CLASS_UPLOADER);
        $torrentPath = $this->makeTorrentFile('e2e-takeupload-ok');

        $response = $this->postUpload($user, $this->uploadData($torrentPath));
        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertStringContainsString('details.php?id=', $location);
        $this->assertStringContainsString('uploaded=1', $location);
        preg_match('/details\.php\?id=(\d+)/', $location, $matches);
        $torrentId = (int) $matches[1];
        $this->assertGreaterThan(0, $torrentId);
        $this->createdTorrentIds[] = $torrentId;

        $torrent = DB::table('torrents')->where('id', $torrentId)->first();
        $this->assertNotNull($torrent);
        $this->assertEquals($this->categoryId, $torrent->category);
        $this->assertEquals($user->id, $torrent->owner);
        $this->assertEquals('yes', $torrent->visible);
        $this->assertEquals('no', $torrent->anonymous);
        $this->assertEquals('E2E TakeUpload Torrent', $torrent->name);
        $this->assertEquals(786432, $torrent->size);
        $this->assertEquals('single', $torrent->type);

        $extra = DB::table('torrent_extras')->where('torrent_id', $torrentId)->first();
        $this->assertNotNull($extra);
        $this->assertEquals('an interesting description for the torrent', $extra->descr);

        $files = DB::table('files')->where('torrent', $torrentId)->get();
        $this->assertCount(1, $files);
        $this->assertEquals(786432, $files->first()->size);

        $bonusLog = DB::table('bonus_logs')->where('uid', $user->id)->where('business_type', \App\Models\BonusLogs::BUSINESS_TYPE_UPLOAD_TORRENT)->first();
        $this->assertNotNull($bonusLog);
        $this->assertEquals(100 + (int) get_setting('bonus.uploadtorrent'), $bonusLog->new_total_value);
    }

    // --------------------------------------------------------------- duplicate

    public function testTakeUploadRedirectsToExistedTorrent()
    {
        $user = $this->makeUser('takeupload_dup', User::CLASS_UPLOADER);
        $torrentPath = $this->makeTorrentFile('e2e-takeupload-dup');

        $first = $this->postUpload($user, $this->uploadData($torrentPath));
        $first->assertRedirect();
        preg_match('/details\.php\?id=(\d+)/', $first->headers->get('Location'), $matches);
        $torrentId = (int) $matches[1];
        $this->assertGreaterThan(0, $torrentId);
        $this->createdTorrentIds[] = $torrentId;

        $second = $this->postUpload($user, $this->uploadData($torrentPath));
        $second->assertRedirect();
        $location = $second->headers->get('Location');
        $this->assertStringContainsString("id=$torrentId", $location);
        $this->assertStringContainsString('existed=1', $location);
    }

    // ------------------------------------------------------------------ validation

    public function testTakeUploadFailsOnBlankDescription()
    {
        $user = $this->makeUser('takeupload_nodescr', User::CLASS_UPLOADER);
        $torrentPath = $this->makeTorrentFile();

        $data = $this->uploadData($torrentPath);
        unset($data['descr']);

        $this->postUpload($user, $data)->assertRedirect();
    }

    public function testTakeUploadFailsOnInvalidTorrentFile()
    {
        $user = $this->makeUser('takeupload_badfile', User::CLASS_UPLOADER);
        $path = tempnam(sys_get_temp_dir(), 'bad_') . '.torrent';
        $this->tempFiles[] = $path;
        file_put_contents($path, 'this is not bencode');

        $this->postUpload($user, $this->uploadData($path))->assertRedirect();
    }

    // ------------------------------------------------------------------ offer

    public function testTakeUploadFinishesAllowedOffer()
    {
        $uploader = $this->makeUser('takeupload_offer_up', User::CLASS_POWER_USER);
        $voter = $this->makeUser('takeupload_offer_voter');
        $offerCountBefore = (int) DB::table('users')->where('id', $uploader->id)->value('offer_allowed_count');

        $offerId = DB::table('offers')->insertGetId([
            'userid' => $uploader->id,
            'name' => 'Offer to be fulfilled',
            'descr' => 'offer description',
            'category' => $this->categoryId,
            'allowed' => 'allowed',
            'added' => now(),
        ]);
        $this->createdOfferIds[] = $offerId;
        DB::table('offervotes')->insert([
            'offerid' => $offerId,
            'userid' => $voter->id,
            'vote' => 'yeah',
        ]);

        $torrentPath = $this->makeTorrentFile('e2e-takeupload-offer');
        $response = $this->postUpload($uploader, $this->uploadData($torrentPath, [
            'offer' => $offerId,
        ]));
        $response->assertRedirect();

        preg_match('/details\.php\?id=(\d+)/', $response->headers->get('Location'), $matches);
        $torrentId = (int) $matches[1];
        $this->assertGreaterThan(0, $torrentId);
        $this->createdTorrentIds[] = $torrentId;

        $this->assertSame(0, DB::table('offers')->where('id', $offerId)->count());
        $this->assertSame(0, DB::table('offervotes')->where('offerid', $offerId)->count());
        $this->assertEquals($offerCountBefore + 1, DB::table('users')->where('id', $uploader->id)->value('offer_allowed_count'));

        $message = DB::table('messages')->where('receiver', $voter->id)->orderByDesc('id')->first();
        $this->assertNotNull($message);
        $this->createdMessageIds[] = $message->id;
        $this->assertStringContainsString('E2E TakeUpload Torrent', $message->msg);
    }

    public function testTakeUploadRejectsForeignOffer()
    {
        $uploader = $this->makeUser('takeupload_foreign_up', User::CLASS_UPLOADER);
        $other = $this->makeUser('takeupload_foreign_owner');

        $offerId = DB::table('offers')->insertGetId([
            'userid' => $other->id,
            'name' => 'Someone else\'s offer',
            'descr' => 'not yours',
            'category' => $this->categoryId,
            'allowed' => 'allowed',
            'added' => now(),
        ]);
        $this->createdOfferIds[] = $offerId;

        $torrentPath = $this->makeTorrentFile();
        $this->postUpload($uploader, $this->uploadData($torrentPath, [
            'offer' => $offerId,
        ]))->assertRedirect();

        $this->assertSame(1, DB::table('offers')->where('id', $offerId)->count());
    }
}