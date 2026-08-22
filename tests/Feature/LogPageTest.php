<?php

namespace Tests\Feature;

use App\Models\Chronicle;
use App\Models\Fun;
use App\Models\News;
use App\Models\Poll;
use App\Models\PollAnswer;
use App\Models\SiteLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/log.php migration (LogController::web):
 * the daily site log, chronicle (add/edit/delete), funbox, news and the
 * previous-polls overview (with poll deletion).
 */
class LogPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdLogIds = [];

    private array $createdChronicleIds = [];

    private array $createdFunIds = [];

    private array $createdNewsIds = [];

    private array $createdPollIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        Cache::flush();
    }

    protected function tearDown(): void
    {
        if ($this->createdPollIds !== []) {
            DB::table('pollanswers')->whereIn('pollid', $this->createdPollIds)->delete();
            DB::table('polls')->whereIn('id', $this->createdPollIds)->delete();
        }
        if ($this->createdNewsIds !== []) {
            DB::table('news')->whereIn('id', $this->createdNewsIds)->delete();
        }
        if ($this->createdFunIds !== []) {
            DB::table('fun')->whereIn('id', $this->createdFunIds)->delete();
        }
        if ($this->createdChronicleIds !== []) {
            DB::table('chronicle')->whereIn('id', $this->createdChronicleIds)->delete();
        }
        if ($this->createdLogIds !== []) {
            DB::table('sitelog')->whereIn('id', $this->createdLogIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser($class = User::CLASS_INSANE_USER): User
    {
        $username = 'log_' . substr(md5((string) mt_rand()), 0, 6);
        $user = User::query()->create([
            'username' => $username,
            'passhash' => str_repeat('c', 32),
            'secret' => 'secret',
            'auth_key' => 'authkey_log_' . $username,
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

    private function asUser(User $user)
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en');
    }

    private function makeLog(array $overrides = []): SiteLog
    {
        $log = SiteLog::query()->create(array_merge([
            'added' => now(),
            'txt' => 'A torrent was uploaded by a user',
            'security_level' => 'normal',
            'uid' => 0,
        ], $overrides));
        $this->createdLogIds[] = $log->id;

        return $log;
    }

    private function makeChronicle(array $overrides = []): Chronicle
    {
        $row = Chronicle::query()->create(array_merge([
            'userid' => 0,
            'added' => now(),
            'txt' => 'A chronicle entry',
        ], $overrides));
        $this->createdChronicleIds[] = $row->id;

        return $row;
    }

    private function makeFun(array $overrides = []): Fun
    {
        $fun = Fun::query()->create(array_merge([
            'userid' => 0,
            'added' => now(),
            'body' => 'funny body',
            'title' => 'A Joke',
            'status' => Fun::STATUS_NORMAL,
        ], $overrides));
        $this->createdFunIds[] = $fun->id;

        return $fun;
    }

    private function makeNews(array $overrides = []): News
    {
        $news = News::query()->create(array_merge([
            'userid' => 0,
            'added' => now(),
            'title' => 'Site News',
            'body' => 'news body',
            'notify' => 0,
        ], $overrides));
        $this->createdNewsIds[] = $news->id;

        return $news;
    }

    private function makePoll(array $overrides = []): Poll
    {
        $poll = Poll::query()->create(array_merge([
            'added' => now(),
            'question' => 'Best movie?',
            'option0' => 'A',
            'option1' => 'B',
        ], $overrides));
        $this->createdPollIds[] = $poll->id;

        return $poll;
    }

    // ------------------------------------------------------------------ auth

    public function testLogRequiresLogin()
    {
        $this->get('/log.php')->assertRedirect();
    }

    public function testLogDeniedBelowPowerUser()
    {
        $user = $this->makeUser(User::CLASS_USER);

        $this->asUser($user)->get('/log.php')->assertOk()->assertSee('Permission denied');
    }

    // -------------------------------------------------------------- dailylog

    public function testDailyLogShowsEntries()
    {
        $user = $this->makeUser();
        $this->makeLog(['txt' => 'was uploaded by testuser']);

        $this->asUser($user)
            ->get('/log.php?action=dailylog')
            ->assertOk()
            ->assertSee('was uploaded by testuser');
    }

    public function testDailyLogSearchFilters()
    {
        $user = $this->makeUser();
        $this->makeLog(['txt' => 'needle text', 'security_level' => 'normal']);
        $this->makeLog(['txt' => 'other text', 'security_level' => 'normal']);

        $this->asUser($user)
            ->get('/log.php?action=dailylog&query=needle')
            ->assertOk()
            ->assertSee('needle text')
            ->assertDontSee('other text');
    }

    public function testDailyLogModHiddenFromNonConfilog()
    {
        $user = $this->makeUser();
        $this->makeLog(['txt' => 'secret mod entry', 'security_level' => 'mod']);

        $this->asUser($user)
            ->get('/log.php?action=dailylog')
            ->assertOk()
            ->assertDontSee('secret mod entry');
    }

    public function testConfilogSeesModEntries()
    {
        $mod = $this->makeUser(User::CLASS_MODERATOR);
        $this->makeLog(['txt' => 'secret mod entry', 'security_level' => 'mod']);

        $this->asUser($mod)
            ->get('/log.php?action=dailylog&search=mod')
            ->assertOk()
            ->assertSee('secret mod entry');
    }

    // -------------------------------------------------------------- chronicle

    public function testChronicleShowsEntries()
    {
        $user = $this->makeUser();
        $this->makeChronicle(['txt' => 'chronicle body text']);

        $this->asUser($user)
            ->get('/log.php?action=chronicle')
            ->assertOk()
            ->assertSee('chronicle body text');
    }

    public function testChronicleAddRequiresChrmanage()
    {
        $user = $this->makeUser();

        $this->asUser($user)->post('/log.php', ['action' => 'chronicle', 'do' => 'add', 'txt' => 'new entry'])
            ->assertOk()
            ->assertSee('not allowed');

        $this->assertFalse(Chronicle::query()->where('txt', 'new entry')->exists());
    }

    public function testChronicleModeratorCanAdd()
    {
        $mod = $this->makeUser(User::CLASS_MODERATOR);

        $this->asUser($mod)->post('/log.php', ['action' => 'chronicle', 'do' => 'add', 'txt' => 'moderator entry'])
            ->assertOk();

        $row = Chronicle::query()->where('txt', 'moderator entry')->first();
        $this->assertNotNull($row);
        $this->assertSame($mod->id, (int) $row->userid);
        $this->createdChronicleIds[] = $row->id;
    }

    public function testChronicleModeratorCanUpdateAndDelete()
    {
        $mod = $this->makeUser(User::CLASS_MODERATOR);
        $row = $this->makeChronicle(['txt' => 'old text']);

        $this->asUser($mod)->post('/log.php', ['action' => 'chronicle', 'do' => 'update', 'id' => $row->id, 'txt' => 'new text'])
            ->assertOk();
        $this->assertSame('new text', $row->fresh()->txt);

        $this->asUser($mod)->get('/log.php?action=chronicle&do=del&id=' . $row->id)
            ->assertOk();
        $this->assertNull(Chronicle::query()->find($row->id));
    }

    // ---------------------------------------------------------------- funbox

    public function testFunboxShowsEntriesAndHidesBanned()
    {
        $user = $this->makeUser();
        $this->makeFun(['title' => 'A Great Joke', 'body' => 'funny body text']);
        $this->makeFun(['title' => 'Banned joke', 'body' => 'spam', 'status' => Fun::STATUS_BANNED]);

        $this->asUser($user)
            ->get('/log.php?action=funbox')
            ->assertOk()
            ->assertSee('A Great Joke')
            ->assertDontSee('Banned joke');
    }

    // ------------------------------------------------------------------ news

    public function testNewsShowsEntries()
    {
        $user = $this->makeUser();
        $this->makeNews(['title' => 'Welcome News', 'body' => 'news body text']);

        $this->asUser($user)
            ->get('/log.php?action=news')
            ->assertOk()
            ->assertSee('Welcome News')
            ->assertSee('news body text');
    }

    // ------------------------------------------------------------------ poll

    public function testPollListsPreviousPolls()
    {
        $user = $this->makeUser();
        $this->makePoll(['question' => 'Oldest poll?']);
        $this->makePoll(['question' => 'Current poll?']);

        $this->asUser($user)
            ->get('/log.php?action=poll')
            ->assertOk()
            ->assertSee('Oldest poll?');
    }

    public function testPollDeleteRequiresChrmanage()
    {
        $user = $this->makeUser();
        $poll = $this->makePoll();

        $this->asUser($user)->get('/log.php?action=poll&do=delete&pollid=' . $poll->id)
            ->assertOk()
            ->assertSee('not allowed');

        $this->assertNotNull(Poll::query()->find($poll->id));
    }

    public function testPollDeleteConfirmThenDelete()
    {
        $mod = $this->makeUser(User::CLASS_MODERATOR);
        $poll = $this->makePoll();

        $this->asUser($mod)
            ->get('/log.php?action=poll&do=delete&pollid=' . $poll->id)
            ->assertOk()
            ->assertSee('Do you really want to delete a poll');

        $response = $this->asUser($mod)->get('/log.php?action=poll&do=delete&pollid=' . $poll->id . '&sure=1');
        $response->assertStatus(302);
        $this->assertNull(Poll::query()->find($poll->id));
    }
}
