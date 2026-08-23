<?php

namespace Tests\Feature;

use App\Models\News;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/news.php migration
 * (NewsController::web).
 *
 * The news management page requires the newsmanage permission (default class
 * >= ADMINISTRATOR). It supports ?action=add / ?action=edit&newsid= /
 * ?action=delete&newsid= plus a default submit form.
 */
class NewsPageTest extends TestCase
{
    private array $createdUserIds = [];
    private array $createdNewsIds = [];

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
        if ($this->createdNewsIds !== []) {
            DB::table('news')->whereIn('id', $this->createdNewsIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(int $class = User::CLASS_ADMINISTRATOR): User
    {
        $username = 'news_' . substr(md5((string) mt_rand()), 0, 6);
        $user = User::query()->create([
            'username' => $username,
            'passhash' => str_repeat('d', 32),
            'secret' => 'secret',
            'auth_key' => 'authkey_news_' . $username,
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

    private function makeNews(User $user): News
    {
        $news = News::query()->create([
            'userid' => $user->id,
            'added' => now(),
            'body' => 'news body ' . md5((string) mt_rand()),
            'title' => 'news title ' . md5((string) mt_rand()),
            'notify' => 'no',
        ]);
        $this->createdNewsIds[] = $news->id;

        return $news;
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

    public function testPageRequiresLogin()
    {
        $this->get('/news.php')->assertRedirect();
    }

    public function testPageRejectsNonStaff()
    {
        $user = $this->makeUser(User::CLASS_USER);

        $this->asUser($user)
            ->get('/news.php')
            ->assertStatus(403);
    }

    public function testPageRendersSubmitForm()
    {
        $user = $this->makeUser();

        $this->asUser($user)
            ->get('/news.php')
            ->assertOk()
            ->assertSee('Submit news item', false)
            ->assertSee('Notify users of this.', false);
    }

    public function testPageAddsNewsItem()
    {
        $user = $this->makeUser();
        $title = 'Added by test ' . md5((string) mt_rand());
        $body = 'Body of ' . $title;

        $this->asUser($user)
            ->post('/news.php?action=add', [
                'subject' => $title,
                'body' => $body,
                'notify' => 'no',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('news', [
            'title' => $title,
            'body' => $body,
            'userid' => $user->id,
        ]);

        $created = DB::table('news')->where('title', $title)->value('id');
        if ($created) {
            $this->createdNewsIds[] = $created;
        }
    }

    public function testPageRejectsEmptyAdd()
    {
        $user = $this->makeUser();

        $this->asUser($user)
            ->post('/news.php?action=add', ['subject' => '', 'body' => ''])
            ->assertStatus(400);
    }

    public function testPageRendersEditForm()
    {
        $user = $this->makeUser();
        $news = $this->makeNews($user);

        $this->asUser($user)
            ->get('/news.php?action=edit&newsid=' . $news->id)
            ->assertOk()
            ->assertSee('Edit Site News', false)
            ->assertSee($news->title, false);
    }

    public function testPageUpdatesNewsItem()
    {
        $user = $this->makeUser();
        $news = $this->makeNews($user);
        $newTitle = 'Edited by test ' . md5((string) mt_rand());
        $newBody = 'New body of ' . $newTitle;

        $this->asUser($user)
            ->post('/news.php?action=edit&newsid=' . $news->id, [
                'subject' => $newTitle,
                'body' => $newBody,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('news', [
            'id' => $news->id,
            'title' => $newTitle,
            'body' => $newBody,
        ]);
    }

    public function testPageDeletesNewsItemWithConfirmation()
    {
        $user = $this->makeUser();
        $news = $this->makeNews($user);

        $this->asUser($user)
            ->get('/news.php?action=delete&newsid=' . $news->id)
            ->assertOk()
            ->assertSee('Do you really want to delete a news item?', false);

        $this->assertDatabaseHas('news', ['id' => $news->id]);
    }

    public function testPageDeletesNewsItemAfterSure()
    {
        $user = $this->makeUser();
        $news = $this->makeNews($user);

        $this->asUser($user)
            ->get('/news.php?action=delete&newsid=' . $news->id . '&sure=1')
            ->assertRedirect();

        $this->assertDatabaseMissing('news', ['id' => $news->id]);
    }
}