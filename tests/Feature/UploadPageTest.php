<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the upload.php migration
 * (UploadController::web) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class UploadPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdOfferIds = [];

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
        if ($this->createdOfferIds !== []) {
            DB::table('offers')->whereIn('id', $this->createdOfferIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('torrents')->whereIn('owner', $ids)->delete();
            DB::table('users')->whereIn('id', $ids)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $username, int $class = User::CLASS_USER, array $overrides = []): User
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

    private function requestAs(User $user, string $uri)
    {
        app('auth')->forgetGuards();
        return $this->withCookie('c_secure_pass', $this->cookieFor($user))->get($uri);
    }

    private function makeOffer(User $user, array $overrides = []): int
    {
        $id = DB::table('offers')->insertGetId(array_merge([
            'userid' => $user->id,
            'name' => 'Offer ' . $user->username,
            'descr' => 'offer description',
            'category' => 401,
            'allowed' => 'allowed',
            'added' => now(),
        ], $overrides));
        $this->createdOfferIds[] = $id;

        return $id;
    }

    // ------------------------------------------------------------------ auth

    public function testUploadRequiresLogin()
    {
        $this->get('/upload.php')->assertRedirect();
    }

    // -------------------------------------------------------------- rendering

    public function testUploadDeniedWhenUploadposIsNo()
    {
        $user = $this->makeUser(
            'upload_denied',
            User::CLASS_UPLOADER,
            ['offer_allowed_count' => 999]
        );
        // uploadpos is not mass-assignable, so set it directly like the legacy schema
        DB::table('users')->where('id', $user->id)->update(['uploadpos' => 'no']);

        $this->requestAs($user, '/upload.php')->assertForbidden();
    }

    public function testUploadPageRendersFormForAllowedUser()
    {
        $user = $this->makeUser(
            'upload_ok',
            User::CLASS_UPLOADER,
            ['uploadpos' => 'yes', 'offer_allowed_count' => 999]
        );

        $this->requestAs($user, '/upload.php')
            ->assertOk()
            ->assertSee('compose', false)
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('takeupload.php', false)
            ->assertSee('name="file"', false)
            ->assertSee('name="descr"', false);
    }

    public function testUploadForbiddenWhenNoUploadRightsAtAll()
    {
        $user = $this->makeUser(
            'upload_no_rights',
            User::CLASS_PEASANT,
            ['uploadpos' => 'yes', 'offer_allowed_count' => 0]
        );

        if (user_can('upload') || get_setting('main.offer_skip_approved_count') == 0) {
            $this->markTestSkipped('peasant can upload in this environment');
        }
        $this->requestAs($user, '/upload.php')->assertForbidden();
    }

    public function testUploadPageShowsOfferDropdownWhenUserHasOffers()
    {
        $user = $this->makeUser(
            'upload_offer_owner',
            User::CLASS_USER,
            ['uploadpos' => 'yes', 'offer_allowed_count' => 0]
        );
        $this->makeOffer($user);

        // the user cannot upload freely, so the page is reachable only via the offer path
        $this->requestAs($user, '/upload.php')
            ->assertOk()
            ->assertSee('name="offer"', false)
            ->assertSee('Offer upload_offer_owner', false);
    }
}