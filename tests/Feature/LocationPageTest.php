<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/location.php migration to the Filament
 * LocationResource.
 *
 * The legacy procedural location (地区) management page is replaced by the
 * System\LocationResource. The legacy entry point now redirects to the admin
 * panel.
 */
class LocationPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdLocationIds = [];

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
        if ($this->createdLocationIds !== []) {
            DB::table('locations')->whereIn('id', $this->createdLocationIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $name, int $class): User
    {
        $user = User::query()->create([
            'username' => $name,
            'passhash' => str_repeat('a', 32),
            'secret' => 'secret',
            'auth_key' => 'authkey_' . $name,
            'email' => $name . '@example.com',
            'status' => 'confirmed',
            'enabled' => 'yes',
            'class' => $class,
            'passkey' => md5($name),
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

    private function makeLocation(string $name = 'China', string $main = 'Asia', string $sub = 'Beijing'): Location
    {
        $location = Location::query()->create([
            'name' => $name,
            'flagpic' => 'china.png',
            'location_main' => $main,
            'location_sub' => $sub,
            'start_ip' => '10.0.0.0',
            'end_ip' => '10.0.0.255',
            'theory_upspeed' => 10,
            'practical_upspeed' => 10,
            'theory_downspeed' => 10,
            'practical_downspeed' => 10,
        ]);
        $this->createdLocationIds[] = $location->id;

        return $location;
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

    // ------------------------------------------------------------------ legacy entry

    public function testLegacyLocationRedirectsToFilament(): void
    {
        $this->get('/location.php')
            ->assertRedirect(route('filament.admin.resources.system.locations.index'));
    }

    // ---------------------------------------------------------- auth on panel

    public function testLocationResourceRequiresLogin(): void
    {
        $this->get('/nexusphp/system/locations')
            ->assertStatus(302);
    }

    public function testLocationResourceDeniedBelowAdministrator(): void
    {
        $user = $this->makeUser('locations_moderator', User::CLASS_MODERATOR);

        $this->asUser($user)->get('/nexusphp/system/locations')->assertForbidden();
    }

    // ------------------------------------------------------------ panel pages

    public function testAdministratorCanAccessLocationResource(): void
    {
        $admin = $this->makeUser('locations_admin', User::CLASS_ADMINISTRATOR);

        $this->asUser($admin)
            ->get('/nexusphp/system/locations')
            ->assertOk();
    }

    public function testListSeesLocation(): void
    {
        $admin = $this->makeUser('locations_list', User::CLASS_ADMINISTRATOR);
        $location = $this->makeLocation();

        $this->asUser($admin)
            ->get('/nexusphp/system/locations')
            ->assertOk()
            ->assertSee($location->name, false)
            ->assertSee($location->location_sub, false);
    }

    public function testCreatePageRenders(): void
    {
        $admin = $this->makeUser('locations_create', User::CLASS_ADMINISTRATOR);

        $this->asUser($admin)
            ->get('/nexusphp/system/locations/create')
            ->assertOk();
    }

    public function testCreateLocationStoresRecord(): void
    {
        $admin = $this->makeUser('locations_create_store', User::CLASS_ADMINISTRATOR);
        $location = $this->makeLocation('United States', 'North America', 'California');

        $this->assertEquals('United States', $location->name);
        $this->assertEquals('10.0.0.0', $location->start_ip);
        $this->assertEquals('10.0.0.255', $location->end_ip);
        $this->assertEquals(10, $location->theory_upspeed);
    }

    public function testEditLocationRenders(): void
    {
        $admin = $this->makeUser('locations_edit', User::CLASS_ADMINISTRATOR);
        $location = $this->makeLocation();

        $this->asUser($admin)
            ->get('/nexusphp/system/locations/' . $location->id . '/edit')
            ->assertOk();
    }

    public function testDeleteLocation(): void
    {
        $admin = $this->makeUser('locations_delete', User::CLASS_ADMINISTRATOR);
        $location = $this->makeLocation();

        $this->asUser($admin)
            ->get('/nexusphp/system/locations')
            ->assertOk();

        $location->delete();

        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
    }
}
