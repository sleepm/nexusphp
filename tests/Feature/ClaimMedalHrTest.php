<?php

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\HitAndRun;
use App\Models\Medal;
use App\Models\Snatch;
use App\Models\Torrent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the Phase 2 P1 migration
 * (claim.php / medal.php / myhr.php) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class ClaimMedalHrTest extends TestCase
{
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
    }

    protected function tearDown(): void
    {
        if ($this->createdUserIds !== []) {
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('hit_and_runs')->whereIn('uid', $ids)->delete();
            DB::table('claims')->whereIn('uid', $ids)->delete();
            DB::table('snatched')->whereIn('userid', $ids)->delete();
            DB::table('torrents')->whereIn('owner', $ids)->delete();
            DB::table('medals')->whereIn('id', $this->createdMedalIds ?? [])->delete();
            DB::table('users')->whereIn('id', $ids)->delete();
        }
        DB::table('user_medals')->whereIn('uid', $this->createdUserIds)->delete();
        parent::tearDown();
    }

    private array $createdMedalIds = [];

    private function makeUser(string $username = 'tester', int $class = User::CLASS_USER): User
    {
        $user = User::query()->create([
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
            'added' => now(),
            'last_access' => now(),
            'commentpm' => 'no',
        ]);
        $this->createdUserIds[] = $user->id;

        return $user;
    }

    private function makeTorrent(User $owner): Torrent
    {
        $torrent = Torrent::query()->create([
            'name' => 'ClaimE2E Torrent',
            'filename' => 'claims-e2e.torrent',
            'save_as' => 'claims-e2e',
            'category' => 1,
            'owner' => $owner->id,
            'size' => 1024 * 1024,
            'added' => now()->subDays(60),
        ]);
        $this->createdTorrentIds[] = $torrent->id;

        return $torrent;
    }

    private array $createdTorrentIds = [];

    private function makeSnatch(User $user, Torrent $torrent): Snatch
    {
        return Snatch::query()->create([
            'torrentid' => $torrent->id,
            'userid' => $user->id,
            'uploaded' => 500 * 1024,
            'downloaded' => 1024 * 1024,
            'to_go' => 0,
            'seedtime' => 100 * 3600,
            'leechtime' => 0,
            'last_action' => now(),
            'startdat' => now(),
            'completedat' => now(),
            'finished' => Snatch::FINISHED_YES,
        ]);
    }

    private function cookieFor(User $user): string
    {
        $tokenJson = json_encode(['user_id' => $user->id, 'expires' => time() + 3600]);
        $signature = hash_hmac('sha256', $tokenJson, $user->auth_key);
        return base64_encode($tokenJson . '.' . $signature);
    }

    private function requestAs(User $user, string $method, string $uri)
    {
        app('auth')->forgetGuards();
        $request = $this->withCookie('c_secure_pass', $this->cookieFor($user));
        if ($method === 'get') {
            return $request->get($uri);
        }

        return $request->post($uri);
    }

    public function testClaimPageRequiresLogin()
    {
        $this->get('/claim.php?uid=1')->assertRedirect();
        $this->get('/medal.php')->assertRedirect();
        $this->get('/myhr.php')->assertRedirect();
    }

    public function testClaimPageRequiresTorrentIdOrUid()
    {
        $user = $this->makeUser('claimnoparam');
        $this->requestAs($user, 'get', '/claim.php')->assertNotFound();
    }

    public function testClaimListByTorrent()
    {
        $user = $this->makeUser('claimuser');
        $owner = $this->makeUser('claimowner');
        $torrent = $this->makeTorrent($owner);
        $snatch = $this->makeSnatch($user, $torrent);
        Claim::query()->create([
            'uid' => $user->id,
            'torrent_id' => $torrent->id,
            'snatched_id' => $snatch->id,
            'seed_time_begin' => 0,
            'uploaded_begin' => 0,
            'last_settle_at' => now(),
        ]);

        $this->requestAs($user, 'get', '/claim.php?torrent_id=' . $torrent->id)
            ->assertOk()
            ->assertSee($torrent->name)
            ->assertSee($user->username);
    }

    public function testClaimListByUser()
    {
        $user = $this->makeUser('claimbyuser');
        $owner = $this->makeUser('claimbyowner');
        $torrent = $this->makeTorrent($owner);
        $snatch = $this->makeSnatch($user, $torrent);
        Claim::query()->create([
            'uid' => $user->id,
            'torrent_id' => $torrent->id,
            'snatched_id' => $snatch->id,
            'seed_time_begin' => 0,
            'uploaded_begin' => 0,
            'last_settle_at' => now(),
        ]);

        $this->requestAs($user, 'get', '/claim.php?uid=' . $user->id)
            ->assertOk()
            ->assertSee($user->username);
    }

    public function testClaimInvalidIdsReturn404()
    {
        $user = $this->makeUser('claiminvalid');
        $this->requestAs($user, 'get', '/claim.php?torrent_id=999999')->assertNotFound();
        $this->requestAs($user, 'get', '/claim.php?uid=999999')->assertNotFound();
    }

    public function testMedalPageListsBuyableMedals()
    {
        $user = $this->makeUser('medaluser');
        $medal = Medal::query()->create([
            'name' => 'E2E Golden Medal',
            'description' => 'test medal',
            'image_large' => 'https://example.com/large.png',
            'image_small' => 'https://example.com/small.png',
            'price' => 10,
            'duration' => 0,
            'get_type' => Medal::GET_TYPE_EXCHANGE,
            'display_on_medal_page' => 1,
            'priority' => 1,
        ]);
        $this->createdMedalIds[] = $medal->id;

        $this->requestAs($user, 'get', '/medal.php')
            ->assertOk()
            ->assertSee('E2E Golden Medal')
            ->assertSee(nexus_trans('medal.buy_btn'));
    }

    public function testMedalPageFiltersByName()
    {
        $user = $this->makeUser('medalq');
        $m1 = Medal::query()->create([
            'name' => 'FilterTargetMedal',
            'description' => '',
            'image_large' => 'https://example.com/1.png',
            'image_small' => 'https://example.com/1s.png',
            'price' => 5,
            'duration' => 0,
            'get_type' => Medal::GET_TYPE_EXCHANGE,
            'display_on_medal_page' => 1,
        ]);
        $m2 = Medal::query()->create([
            'name' => 'OtherMedal',
            'description' => '',
            'image_large' => 'https://example.com/2.png',
            'image_small' => 'https://example.com/2s.png',
            'price' => 5,
            'duration' => 0,
            'get_type' => Medal::GET_TYPE_EXCHANGE,
            'display_on_medal_page' => 1,
        ]);
        $this->createdMedalIds = [$m1->id, $m2->id];

        $response = $this->requestAs($user, 'get', '/medal.php?q=FilterTargetMedal')
            ->assertOk()
            ->assertSee('FilterTargetMedal');
        $this->assertStringNotContainsString('OtherMedal', $response->getContent());
    }

    public function testMyhrListsOwnHitAndRuns()
    {
        $user = $this->makeUser('hruser');
        $owner = $this->makeUser('hrowner');
        $torrent = $this->makeTorrent($owner);
        $snatch = $this->makeSnatch($user, $torrent);
        $hr = HitAndRun::query()->create([
            'uid' => $user->id,
            'snatched_id' => $snatch->id,
            'torrent_id' => $torrent->id,
            'status' => HitAndRun::STATUS_INSPECTING,
            'comment' => 'pending',
        ]);

        $this->requestAs($user, 'get', '/myhr.php')
            ->assertOk()
            ->assertSee((string) $hr->id)
            ->assertSee($torrent->name);
    }

    public function testMyhrOtherUsersRequireViewHistory()
    {
        $viewer = $this->makeUser('hrviewer');
        $target = $this->makeUser('hrtarget');
        $owner = $this->makeUser('hrtargetowner');
        $torrent = $this->makeTorrent($owner);
        $snatch = $this->makeSnatch($target, $torrent);
        HitAndRun::query()->create([
            'uid' => $target->id,
            'snatched_id' => $snatch->id,
            'torrent_id' => $torrent->id,
            'status' => HitAndRun::STATUS_INSPECTING,
            'comment' => '',
        ]);

        // a normal user cannot browse another user's H&R list
        $this->requestAs($viewer, 'get', '/myhr.php?userid=' . $target->id)
            ->assertForbidden();
    }
}