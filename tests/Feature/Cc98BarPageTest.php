<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the cc98bar.php migration (Cc98BarController::web)
 * against a real database: the external-forum "options in the URL path" format,
 * the privacy / class gates and malformed-URL handling.
 */
class Cc98BarPageTest extends TestCase
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
            'passhash' => str_repeat('d', 32),
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

    private function getBar(string $suffix)
    {
        $uri = '/cc98bar.php/' . $suffix;
        if (! empty($GLOBALS['Cache'])) {
            $GLOBALS['Cache']->delete_value('userbar_' . $uri);
        }
        return $this->get($uri);
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
        $user = $this->makeUser('cc98bar_user', User::CLASS_POWER_USER);

        $this->assertPng($this->getBar('id' . $user->id . '.png'));
    }

    public function testBarRendersWithOptions()
    {
        $user = $this->makeUser('cc98bar_opts', User::CLASS_POWER_USER);

        $this->assertPng($this->getBar('nn1nu1nd1bg0id' . $user->id . '.png'));
    }

    public function testBarRejectsStrongPrivacy()
    {
        $user = $this->makeUser('cc98bar_private', User::CLASS_POWER_USER, [
            'privacy' => 'strong',
        ]);

        $this->getBar('id' . $user->id . '.png')->assertStatus(404);
    }

    public function testBarRejectsLowClass()
    {
        $user = $this->makeUser('cc98bar_lowclass', User::CLASS_USER);

        $this->getBar('id' . $user->id . '.png')->assertStatus(404);
    }

    public function testBarRejectsUnknownUser()
    {
        $this->getBar('id999999.png')->assertStatus(404);
    }

    public function testBarRejectsMalformedUrl()
    {
        $this->getBar('just-some-text.png')->assertStatus(404);
        $this->get('/cc98bar.php')->assertStatus(404);
    }
}