<?php

namespace Tests\Feature;

use App\Models\Torrent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubtitlesPageTest extends TestCase
{
    private array $createdUserIds = [];
    private array $createdTorrentIds = [];
    private array $createdSubIds = [];
    private array $createdSubFiles = [];

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
        foreach ($this->createdSubFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        if ($this->createdSubIds !== []) {
            DB::table('subs')->whereIn('id', $this->createdSubIds)->delete();
        }
        if ($this->createdTorrentIds !== []) {
            DB::table('torrents')->whereIn('id', $this->createdTorrentIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('subs')->whereIn('uppedby', $ids)->delete();
            DB::table('torrents')->whereIn('owner', $ids)->delete();
            DB::table('users')->whereIn('id', $ids)->delete();
        }
        parent::tearDown();
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

    private function makeTorrent(User $owner, array $overrides = []): Torrent
    {
        $torrent = Torrent::query()->create(array_merge([
            'name' => 'Test Torrent ' . uniqid(),
            'filename' => 'test-' . uniqid() . '.torrent',
            'save_as' => 'test-torrent',
            'category' => 401,
            'owner' => $owner->id,
            'size' => 1024 * 1024,
            'added' => now()->subDays(1),
            'views' => 0,
        ], $overrides));
        $this->createdTorrentIds[] = $torrent->id;
        return $torrent;
    }

    private function makeSub(array $overrides = []): int
    {
        $id = DB::table('subs')->insertGetId(array_merge([
            'torrent_id' => 0,
            'lang_id' => 1,
            'title' => 'Test Subtitle',
            'filename' => 'test.srt',
            'added' => now(),
            'size' => 1024,
            'uppedby' => $this->createdUserIds[0] ?? 1,
            'anonymous' => 'no',
            'hits' => 10,
            'ext' => 'srt',
        ], $overrides));
        $this->createdSubIds[] = $id;
        return $id;
    }

    private function cookieFor(User $user): string
    {
        $tokenJson = json_encode(['user_id' => $user->id, 'expires' => time() + 3600]);
        $signature = hash_hmac('sha256', $tokenJson, $user->auth_key);
        return base64_encode($tokenJson . '.' . $signature);
    }

    private function requestSubtitles(User $user, string $method, string $uri, array $data = [])
    {
        app('auth')->forgetGuards();
        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->{$method}($uri, $data);
    }

    // ------------------------------------------------------------------ auth

    public function testRequiresLogin()
    {
        $this->get('/subtitles.php')->assertRedirect();
    }

    // -------------------------------------------------------------- rendering

    public function testRendersUploadFormAndRules()
    {
        $user = $this->makeUser('sub_upload_form_user');

        $response = $this->requestSubtitles($user, 'get', '/subtitles.php');
        $response->assertOk()
            ->assertSee('input type="file" name="file"', false)
            ->assertSee('input type="text" name="torrent_id"', false)
            ->assertSee('input type="text" name="title"', false)
            ->assertSee('select name="sel_lang"', false)
            ->assertSee('1.Please upload only files with English names!');
    }

    public function testRendersEmptyList()
    {
        $user = $this->makeUser('sub_empty_user');
        $response = $this->requestSubtitles($user, 'get', '/subtitles.php');
        $response->assertOk()
            ->assertSee('Sorry, nothing here pal');
    }

    public function testRendersSubtitleList()
    {
        $user = $this->makeUser('sub_list_user');
        $torrent = $this->makeTorrent($user);
        $this->makeSub([
            'torrent_id' => $torrent->id,
            'uppedby' => $user->id,
            'title' => 'My Awesome Sub',
            'ext' => 'srt',
            'filename' => 'awesome.srt',
        ]);

        $response = $this->requestSubtitles($user, 'get', '/subtitles.php');
        $response->assertOk()
            ->assertSee('My Awesome Sub')
            ->assertSee($user->username, false);
    }

    public function testSearchFiltersList()
    {
        $user = $this->makeUser('sub_search_user');
        $torrent = $this->makeTorrent($user);
        $this->makeSub([
            'torrent_id' => $torrent->id,
            'uppedby' => $user->id,
            'title' => 'Unique Search Term XYZ',
        ]);
        $this->makeSub([
            'torrent_id' => $torrent->id,
            'uppedby' => $user->id,
            'title' => 'Something Else',
        ]);

        $response = $this->requestSubtitles($user, 'get', '/subtitles.php?search=Unique');
        $response->assertOk()
            ->assertSee('Unique Search Term XYZ')
            ->assertDontSee('Something Else');
    }

    public function testLetterFilter()
    {
        $user = $this->makeUser('sub_letter_user');
        $torrent = $this->makeTorrent($user);
        $this->makeSub([
            'torrent_id' => $torrent->id,
            'uppedby' => $user->id,
            'title' => 'Apple Pie',
        ]);
        $this->makeSub([
            'torrent_id' => $torrent->id,
            'uppedby' => $user->id,
            'title' => 'Banana Split',
        ]);

        $response = $this->requestSubtitles($user, 'get', '/subtitles.php?letter=a');
        $response->assertOk()
            ->assertSee('Apple Pie')
            ->assertDontSee('Banana Split');
    }

    // --------------------------------------------------------------- upload

    public function testUploadValidSubtitle()
    {
        $user = $this->makeUser('sub_up_valid');
        $torrent = $this->makeTorrent($user);
        $subsPath = get_setting('main.subspath', 'subs');
        $bonusBefore = (int) DB::table('users')->where('id', $user->id)->value('seedbonus');
        $file = UploadedFile::fake()->create('mysub.srt', 100);

        $response = $this->requestSubtitles($user, 'post', '/subtitles.php', [
            'action' => 'upload',
            'torrent_id' => $torrent->id,
            'title' => 'My Uploaded Sub',
            'sel_lang' => 1,
            'file' => $file,
        ]);
        $response->assertOk()
            ->assertSee('My Uploaded Sub');

        $sub = DB::table('subs')->where('torrent_id', $torrent->id)->orderByDesc('id')->first();
        $this->assertNotNull($sub, 'sub should have been created');
        $this->assertSame('My Uploaded Sub', $sub->title);
        $this->assertSame($user->id, (int) $sub->uppedby);

        $expectedFile = ROOT_PATH . $subsPath . '/' . $torrent->id . '/' . $sub->id . '.srt';
        $this->assertFileExists($expectedFile);
        $this->createdSubFiles[] = $expectedFile;

        $bonusAfter = (int) DB::table('users')->where('id', $user->id)->value('seedbonus');
        $this->assertGreaterThan($bonusBefore, $bonusAfter, 'uploadsubtitle bonus should have been awarded');
    }

    public function testUploadRejectsMissingFile()
    {
        $user = $this->makeUser('sub_up_nofile');
        $torrent = $this->makeTorrent($user);

        $response = $this->requestSubtitles($user, 'post', '/subtitles.php', [
            'action' => 'upload',
            'torrent_id' => $torrent->id,
            'sel_lang' => 1,
        ]);
        $response->assertOk()
            ->assertSee('Nothing received!');
    }

    public function testUploadRejectsWrongExt()
    {
        $user = $this->makeUser('sub_up_wrongext');
        $torrent = $this->makeTorrent($user);
        $file = UploadedFile::fake()->create('malware.exe', 100);

        $response = $this->requestSubtitles($user, 'post', '/subtitles.php', [
            'action' => 'upload',
            'torrent_id' => $torrent->id,
            'sel_lang' => 1,
            'file' => $file,
        ]);
        $response->assertOk()
            ->assertSee('I am not allowed to save the file you send me');
    }

    public function testUploadRejectsInvalidTorrentId()
    {
        $user = $this->makeUser('sub_up_invalidtid');
        $file = UploadedFile::fake()->create('test.srt', 10);

        $response = $this->requestSubtitles($user, 'post', '/subtitles.php', [
            'action' => 'upload',
            'torrent_id' => 999999,
            'sel_lang' => 1,
            'file' => $file,
        ]);
        $response->assertOk()
            ->assertSee('it seems not a valid torrent ID');
    }

    public function testUploadRejectsNoPermissionForOthers()
    {
        $owner = $this->makeUser('sub_up_other_owner');
        $peasant = $this->makeUser('sub_up_peasant', User::CLASS_PEASANT);
        $torrent = $this->makeTorrent($owner);
        $file = UploadedFile::fake()->create('test.srt', 10);

        $response = $this->requestSubtitles($peasant, 'post', '/subtitles.php', [
            'action' => 'upload',
            'torrent_id' => $torrent->id,
            'sel_lang' => 1,
            'file' => $file,
        ]);
        $response->assertOk()
            ->assertSee('users of your class cannot upload subs to torrents of others!');
    }

    public function testUploadRejectsNoLanguage()
    {
        $user = $this->makeUser('sub_up_nolang');
        $torrent = $this->makeTorrent($user);
        $file = UploadedFile::fake()->create('test.srt', 10);

        $response = $this->requestSubtitles($user, 'post', '/subtitles.php', [
            'action' => 'upload',
            'torrent_id' => $torrent->id,
            'title' => 'No Lang Sub',
            'file' => $file,
        ]);
        $response->assertOk()
            ->assertSee('Please Choose a language for the subtitle');
    }

    // ------------------------------------------------------------ in_detail

    public function testInDetailPrefillsTorrentId()
    {
        $user = $this->makeUser('sub_indetail');
        $torrent = $this->makeTorrent($user);

        $response = $this->requestSubtitles($user, 'post', '/subtitles.php', [
            'in_detail' => 'in_detail',
            'detail_torrent_id' => $torrent->id,
            'torrent_name' => 'My Torrent Name',
        ]);
        $response->assertOk()
            ->assertSee('value="' . $torrent->id . '"', false)
            ->assertSee('My Torrent Name');
    }

    // --------------------------------------------------------------- delete

    public function testDeleteShowsConfirmPage()
    {
        $user = $this->makeUser('sub_del_confirm', User::CLASS_MODERATOR);
        $torrent = $this->makeTorrent($user);
        $subId = $this->makeSub([
            'torrent_id' => $torrent->id,
            'uppedby' => $user->id,
            'title' => 'To Delete Sub',
        ]);

        $response = $this->requestSubtitles($user, 'get', '/subtitles.php?delete=' . $subId);
        $response->assertOk()
            ->assertSee('Delete subtitle')
            ->assertSee('Confirm');
    }

    public function testDeleteRemovesSubAndFile()
    {
        $user = $this->makeUser('sub_del_exec', User::CLASS_MODERATOR);
        $torrent = $this->makeTorrent($user);
        $subsPath = get_setting('main.subspath', 'subs');
        $fileDir = ROOT_PATH . $subsPath . '/' . $torrent->id;
        @mkdir($fileDir, 0777, true);
        $id = DB::table('subs')->insertGetId([
            'torrent_id' => $torrent->id,
            'lang_id' => 1,
            'title' => 'Deletable',
            'filename' => 'deletable.srt',
            'added' => now(),
            'size' => 100,
            'uppedby' => $user->id,
            'anonymous' => 'no',
            'hits' => 0,
            'ext' => 'srt',
        ]);
        $this->createdSubIds[] = $id;
        $existingFile = $fileDir . '/' . $id . '.srt';
        file_put_contents($existingFile, 'dummy-content');
        $this->createdSubFiles[] = $existingFile;
        $this->assertFileExists($existingFile);

        $response = $this->requestSubtitles($user, 'get', '/subtitles.php?delete=' . $id . '&sure=1');
        $response->assertOk()
            ->assertSee('Subtitles');

        $this->assertNull(DB::table('subs')->where('id', $id)->first());
        $this->assertFileDoesNotExist($existingFile);
    }
}
