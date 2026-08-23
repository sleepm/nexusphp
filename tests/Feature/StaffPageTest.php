<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/staff.php migration
 * (StaffController::web).
 *
 * The staff page requires the staffmem permission (default class >=
 * MODERATOR). It renders firstline support, critics, forum moderators,
 * general staff and VIP sections with online/offline indicators.
 */
class StaffPageTest extends TestCase
{
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        Cache::forget('staff_page');
    }

    protected function tearDown(): void
    {
        Cache::forget('staff_page');
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(int $class = User::CLASS_USER): User
    {
        $username = 'staff_' . substr(md5((string) mt_rand()), 0, 6);
        $user = User::query()->create([
            'username' => $username,
            'passhash' => str_repeat('d', 32),
            'secret' => 'secret',
            'auth_key' => 'authkey_staff_' . $username,
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
            'support' => 'no',
            'picker' => 'no',
            'stafffor' => '',
            'supportfor' => '',
            'supportlang' => '',
            'pickfor' => '',
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

    public function testPageRequiresLogin()
    {
        $this->get('/staff.php')->assertRedirect();
    }

    public function testPageRejectsNonStaff()
    {
        $user = $this->makeUser(User::CLASS_USER);

        $this->asUser($user)
            ->get('/staff.php')
            ->assertStatus(403);
    }

    public function testPageRendersForModerator()
    {
        $user = $this->makeUser(User::CLASS_MODERATOR);

        $this->asUser($user)
            ->get('/staff.php')
            ->assertOk()
            ->assertSee('Firstline Support', false)
            ->assertSee('Critics', false)
            ->assertSee('Forum Moderators', false)
            ->assertSee('General Staff', false)
            ->assertSee('VIP', false);
    }

    public function testPageRendersSupportUser()
    {
        $user = $this->makeUser(User::CLASS_MODERATOR);
        $support = $this->makeUser(User::CLASS_USER);
        DB::table('users')->where('id', $support->id)->update([
            'support' => 'yes',
            'supportlang' => 'English',
            'supportfor' => 'General',
        ]);

        $this->asUser($user)
            ->get('/staff.php')
            ->assertOk()
            ->assertSee($support->username, false);
    }

    public function testPageRendersVipUser()
    {
        $user = $this->makeUser(User::CLASS_MODERATOR);
        $vip = $this->makeUser(User::CLASS_VIP);
        DB::table('users')->where('id', $vip->id)->update(['stafffor' => 'Donation']);

        $this->asUser($user)
            ->get('/staff.php')
            ->assertOk()
            ->assertSee($vip->username, false);
    }
}