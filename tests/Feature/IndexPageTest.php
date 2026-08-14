<?php

namespace Tests\Feature;

use App\Http\Controllers\IndexController;
use App\Models\News;
use App\Models\Poll;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the Phase 3 P0 migration (index.php) against a real
 * database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class IndexPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdPollIds = [];

    private array $createdNewsIds = [];

    private array $createdFunIds = [];

    private array $createdForumIds = [];

    private array $createdTopicIds = [];

    private array $createdPostIds = [];

    private array $createdTorrentIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $this->clearIndexCache();
    }

    private function clearIndexCache(): void
    {
        // the test env keeps a persistent (redis) cache, so drop the entries the
        // index page relies on to avoid cross-test/process staleness
        foreach ([
            IndexController::CACHE_NEWS,
            IndexController::CACHE_LINKS,
            IndexController::CACHE_STATS_USERS,
            IndexController::CACHE_STATS_TORRENTS,
            IndexController::CACHE_STATS_CLASSES,
            IndexController::CACHE_TOP_UPLOADER_ALL,
            IndexController::CACHE_TOP_UPLOADER_RECENTLY,
            IndexController::CACHE_POLL_CONTENT,
            IndexController::CACHE_POLL_RESULT,
            IndexController::CACHE_FUN_CONTENT,
            IndexController::CACHE_FUN_VOTE_COUNT,
            IndexController::CACHE_FUN_VOTE_FUNNY_COUNT,
        ] as $key) {
            Cache::forget($key);
        }
    }

    protected function tearDown(): void
    {
        if ($this->createdUserIds !== []) {
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('pollanswers')->whereIn('userid', $ids)->delete();
            DB::table('users')->whereIn('id', $ids)->delete();
        }
        if ($this->createdPollIds !== []) {
            DB::table('polls')->whereIn('id', $this->createdPollIds)->delete();
        }
        if ($this->createdNewsIds !== []) {
            DB::table('news')->whereIn('id', $this->createdNewsIds)->delete();
        }
        if ($this->createdPostIds !== []) {
            DB::table('posts')->whereIn('id', $this->createdPostIds)->delete();
        }
        if ($this->createdTopicIds !== []) {
            DB::table('topics')->whereIn('id', $this->createdTopicIds)->delete();
        }
        if ($this->createdForumIds !== []) {
            DB::table('forums')->whereIn('id', $this->createdForumIds)->delete();
        }
        if ($this->createdTorrentIds !== []) {
            DB::table('torrents')->whereIn('id', $this->createdTorrentIds)->delete();
        }
        if ($this->createdFunIds !== []) {
            DB::table('fun')->whereIn('id', $this->createdFunIds)->delete();
        }
        parent::tearDown();
    }

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
            'seedbonus' => 100,
            'showfb' => 'yes',
            'hidehb' => 'no',
            'added' => now(),
            'last_access' => now(),
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

    private function requestAs(User $user, string $method, string $uri, array $data = [])
    {
        app('auth')->forgetGuards();
        $request = $this->withCookie('c_secure_pass', $this->cookieFor($user));
        if ($method === 'get') {
            return $request->get($uri);
        }

        return $request->post($uri, $data);
    }

    // ------------------------------------------------------------------ auth

    public function testHomeRequiresLogin()
    {
        $this->get('/')->assertRedirect();
    }

    public function testIndexPhpRequiresLogin()
    {
        $this->get('/index.php')->assertRedirect();
    }

    // -------------------------------------------------------------- rendering

    public function testHomeRendersModules()
    {
        $user = $this->makeUser('home_viewer');
        $poll = Poll::query()->create([
            'added' => now(),
            'question' => 'Pick a color',
            'option0' => 'Red',
            'option1' => 'Blue',
        ]);
        $this->createdPollIds[] = $poll->id;

        $this->requestAs($user, 'get', '/')
            ->assertOk()
            ->assertSee('Recent news')
            ->assertSee('Tracker Statistics')
            ->assertSee('Pick a color')
            ->assertSee('Vote!')
            ->assertSee('Disclaimer')
            ->assertSee('Links')
            ->assertSee('Nexus');
    }

    public function testLegacyIndexPhpPathServesHome()
    {
        $user = $this->makeUser('home_legacy_path');
        $this->requestAs($user, 'get', '/index.php')
            ->assertOk()
            ->assertSee('Recent news');
    }

    public function testNewsIsRendered()
    {
        $user = $this->makeUser('news_viewer');
        $news = News::query()->create([
            'userid' => $user->id,
            'added' => now(),
            'title' => 'Site maintenance announcement',
            'body' => 'We will be down tonight for maintenance',
            'notify' => 'no',
        ]);
        $this->createdNewsIds[] = $news->id;

        $this->requestAs($user, 'get', '/')
            ->assertOk()
            ->assertSee('Site maintenance announcement');
    }

    // ------------------------------------------------------------------- polls

    public function testPollVoteCreatesAnswerAndBonus()
    {
        $user = $this->makeUser('poll_voter');
        $poll = Poll::query()->create([
            'added' => now(),
            'question' => 'Pick a color',
            'option0' => 'Red',
            'option1' => 'Blue',
        ]);
        $this->createdPollIds[] = $poll->id;
        $before = $user->fresh()->seedbonus;

        $this->requestAs($user, 'post', '/index.php', ['choice' => 0])
            ->assertRedirect();

        $this->assertDatabaseHas('pollanswers', [
            'pollid' => $poll->id,
            'userid' => $user->id,
            'selection' => 0,
        ]);
        $this->assertEquals($before + 1, $user->fresh()->seedbonus);
    }

    public function testPollDuplicateVoteRejected()
    {
        $user = $this->makeUser('poll_dup');
        $poll = Poll::query()->create([
            'added' => now(),
            'question' => 'Pick a color',
            'option0' => 'Red',
            'option1' => 'Blue',
        ]);
        $this->createdPollIds[] = $poll->id;

        $this->requestAs($user, 'post', '/index.php', ['choice' => 0])->assertRedirect();
        $this->requestAs($user, 'post', '/index.php', ['choice' => 1])
            ->assertSessionHas('error');

        $this->assertEquals(
            1,
            DB::table('pollanswers')->where('pollid', $poll->id)->where('userid', $user->id)->count()
        );
    }

    public function testPollVoteWithoutSelectionShowsError()
    {
        $user = $this->makeUser('poll_none');
        $poll = Poll::query()->create([
            'added' => now(),
            'question' => 'Pick a color',
            'option0' => 'Red',
            'option1' => 'Blue',
        ]);
        $this->createdPollIds[] = $poll->id;

        $this->requestAs($user, 'post', '/index.php')
            ->assertSessionHas('error');
    }

    public function testPollShowsResultsAfterVote()
    {
        $user = $this->makeUser('poll_results');
        $poll = Poll::query()->create([
            'added' => now(),
            'question' => 'Pick a color',
            'option0' => 'Red',
            'option1' => 'Blue',
        ]);
        $this->createdPollIds[] = $poll->id;

        $this->requestAs($user, 'post', '/index.php', ['choice' => 0]);

        $this->requestAs($user, 'get', '/')
            ->assertOk()
            ->assertSee('Pick a color')
            ->assertDontSee('<input type="radio" name="choice"', false)
            ->assertSee('100%');
    }

    // -------------------------------------------- optional (setting-gated) modules

    /**
     * The optional home modules (funbox, latest forum posts, latest torrents,
     * top uploader) are gated by settings that default to 'no' and whose values
     * are cached in a process-level static by get_setting(), so they cannot be
     * toggled reliably inside a shared test process. Instead, exercise the
     * private build*() methods via buildHomeViewData() with all flags enabled
     * and assert the rendered Blade output.
     */
    public function testOptionalModulesRender()
    {
        $user = $this->makeUser('optional_viewer');
        $uploaderUser = $this->makeUser('optional_uploader');
        $posterUser = $this->makeUser('optional_poster');

        $this->createdFunIds[] = DB::table('fun')->insertGetId([
            'userid' => $posterUser->id,
            'added' => now(),
            'body' => 'Why did the chicken cross the road?',
            'title' => 'Joke of the day',
            'status' => 'normal',
        ]);

        $this->createdForumIds[] = DB::table('forums')->insertGetId([
            'sort' => 1,
            'name' => 'General Discussion',
            'description' => '',
            'minclassread' => 0,
        ]);
        $this->createdTopicIds[] = DB::table('topics')->insertGetId([
            'userid' => $posterUser->id,
            'subject' => 'Latest forum topic',
            'forumid' => $this->createdForumIds[0],
        ]);
        $this->createdPostIds[] = DB::table('posts')->insertGetId([
            'topicid' => $this->createdTopicIds[0],
            'userid' => $posterUser->id,
            'added' => now(),
            'body' => 'First reply',
        ]);

        $torrentNames = ['Optional torrent A', 'Optional torrent B'];
        foreach ([$uploaderUser->id, $user->id] as $i => $owner) {
            $this->createdTorrentIds[] = DB::table('torrents')->insertGetId([
                'name' => $torrentNames[$i],
                'small_descr' => 'a small description',
                'owner' => $owner,
                'added' => now(),
                'visible' => 'yes',
                'seeders' => 1,
                'leechers' => 0,
            ]);
        }

        $controller = new IndexController();
        $buildViewData = new \ReflectionMethod(IndexController::class, 'buildHomeViewData');
        $viewData = $buildViewData->invokeArgs($controller, [
            $user,
            get_legacy_lang_file('index'),
            request(),
            [
                'showFunbox' => true,
                'showShoutbox' => false,
                'showLatestForumPosts' => true,
                'showLatestTorrents' => true,
                'showTopUploader' => true,
                'showPolls' => false,
                'showStats' => true,
                'showTrackerLoad' => false,
            ],
        ]);

        $html = view('index', $viewData)->render();

        $this->assertStringContainsString("name='funbox'", $html);
        $this->assertStringContainsString('Optional torrent A', $html);
        $this->assertStringContainsString('forums.php?action=viewtopic', $html);
        $this->assertStringContainsString('Latest forum topic', $html);
        $this->assertStringContainsString('top-uploader-recently', $html);
        $this->assertStringContainsString('Tracker Statistics', $html);
    }
}