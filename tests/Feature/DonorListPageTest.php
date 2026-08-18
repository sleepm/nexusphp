<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the donorlist.php migration
 * (DonorListController::web) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class DonorListPageTest extends TestCase
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
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('users')->whereIn('id', $ids)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $username, $class = User::CLASS_ADMINISTRATOR, array $overrides = []): User
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

    private function getDonorList(User $user, string $query = '')
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/donorlist.php' . $query);
    }

    // ------------------------------------------------------------------ auth

    public function testDonorListRequiresLogin()
    {
        $this->get('/donorlist.php')->assertRedirect();
    }

    public function testDonorListRejectsBelowAdministrator()
    {
        $mod = $this->makeUser('donorlist_mod', User::CLASS_MODERATOR);

        $this->getDonorList($mod)->assertForbidden();
    }

    // -------------------------------------------------------------- rendering

    public function testDonorListRendersEmptyList()
    {
        $admin = $this->makeUser('donorlist_empty_admin');

        $this->getDonorList($admin)
            ->assertOk()
            ->assertSee('Donorlist')
            ->assertSee('No donors yet.');
    }

    public function testDonorListRendersDonors()
    {
        $admin = $this->makeUser('donorlist_admin');
        $donor = $this->makeUser('donorlist_donor', User::CLASS_USER);
        DB::table('users')->where('id', $donor->id)->update([
            'donor' => 'yes',
            'donated' => 100.00,
        ]);

        $this->getDonorList($admin)
            ->assertOk()
            ->assertSee('donorlist_donor')
            ->assertSee('donorlist_donor@example.com')
            ->assertSee('How much?')
            ->assertSee('$100');
    }

    public function testDonorListExcludesNonDonors()
    {
        $admin = $this->makeUser('donorlist_nondonor_admin');
        $this->makeUser('donorlist_plain', User::CLASS_USER, [
            'donor' => 'no',
        ]);

        $this->getDonorList($admin)
            ->assertOk()
            ->assertDontSee('donorlist_plain');
    }
}
