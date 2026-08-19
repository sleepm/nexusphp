<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the mybar.php migration (MyBarController::web)
 * against a real database: the generated PNG, the privacy / class gates and
 * the legacy URL format (?userid=ID.png).
 */
class MyBarPageTest extends TestCase
{
    private array $createdUserIds = [];

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
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $username, $class = User::CLASS_POWER_USER, array $overrides = []): User
    {
        $user = User::query()->create(array_merge([
            'username' => $username,
            'passhash' => str_repeat('c', 32),
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
            'uploaded' => 0,
            'downloaded' => 0,
            'showfb' => 'yes',
            'hidehb' => 'no',
            'added' => now(),
            'last_access' => now(),
            'commentpm' => 'no',
        ], $overrides));
        $user->forceFill($overrides)->save();
        $user->refresh();
        $this->createdUserIds[] = $user->id;

        return $user;
    }

    private function getBar(int $userId, string $query = '')
    {
        $key = 'userbar_/mybar.php?userid=' . $userId . '.png' . ($query !== '' ? '&' . $query : '');
        if (! empty($GLOBALS['Cache'])) {
            $GLOBALS['Cache']->delete_value($key);
        }
        return $this->get('/mybar.php?userid=' . $userId . '.png' . ($query !== '' ? '&' . $query : ''));
    }

    private function assertPng($response)
    {
        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $image = @imagecreatefromstring((string) $response->getContent());
        $this->assertNotFalse($image);
        imagedestroy($image);
    }

    public function testBarRendersPng()
    {
        $user = $this->makeUser('mybar_user', User::CLASS_POWER_USER);

        $this->assertPng($this->getBar($user->id));
    }

    public function testBarHonoursBgpicAndOverrides()
    {
        $user = $this->makeUser('mybar_custom', User::CLASS_POWER_USER);

        $this->assertPng($this->getBar($user->id, 'bgpic=0&noname=1&noup=1&nodown=1&namesize=3'));
    }

    public function testBarRejectsStrongPrivacy()
    {
        $user = $this->makeUser('mybar_private', User::CLASS_POWER_USER, [
            'privacy' => 'strong',
        ]);

        $this->getBar($user->id)->assertStatus(404);
    }

    public function testBarRejectsLowClass()
    {
        $user = $this->makeUser('mybar_lowclass', User::CLASS_USER);

        $this->getBar($user->id)->assertStatus(404);
    }

    public function testBarRejectsInvalidUseridFormat()
    {
        $this->get('/mybar.php?userid=abc')->assertStatus(404);
        $this->get('/mybar.php?userid=123')->assertStatus(404);
    }

    public function testBarRejectsUnknownUser()
    {
        $this->getBar(999999)->assertStatus(404);
    }
}