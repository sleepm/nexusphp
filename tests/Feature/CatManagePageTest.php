<?php

namespace Tests\Feature;

use App\Models\AudioCodec;
use App\Models\Category;
use App\Models\Codec;
use App\Models\Icon;
use App\Models\Media;
use App\Models\Processing;
use App\Models\SearchBox;
use App\Models\SecondIcon;
use App\Models\Source;
use App\Models\Standard;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/catmanage.php migration to Filament.
 *
 * The legacy procedural page (category / section / icon / sub-category
 * management) is replaced by the Filament Section group resources
 * (Section\CategoryResource and friends). The legacy entry point now
 * redirects to the admin panel.
 */
class CatManagePageTest extends TestCase
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

    private function resourceRoutes(): array
    {
        return [
            'section/categories' => SearchBox::class,
            'section/sources' => Source::class,
            'section/media' => Media::class,
            'section/codecs' => Codec::class,
            'section/standards' => Standard::class,
            'section/processings' => Processing::class,
            'section/teams' => Team::class,
            'section/audio-codecs' => AudioCodec::class,
            'section/icons' => Icon::class,
            'section/second-icons' => SecondIcon::class,
            'sections' => SearchBox::class,
        ];
    }

    // ------------------------------------------------------------------ legacy entry

    public function testLegacyCatmanageRedirectsToFilament(): void
    {
        $this->get('/catmanage.php')
            ->assertRedirect(route('filament.admin.resources.section.categories.index'));
    }

    public function testLegacyCatmanageWithTypeStillRedirects(): void
    {
        $this->get('/catmanage.php?action=view&type=searchbox')
            ->assertRedirect(route('filament.admin.resources.section.categories.index'));
    }

    // ---------------------------------------------------------- auth on panel

    public function testSectionResourceRequiresLogin(): void
    {
        foreach (array_keys($this->resourceRoutes()) as $uri) {
            $this->get('/nexusphp/' . $uri)
                ->assertStatus(302);
        }
    }

    public function testSectionResourceDeniedBelowSysop(): void
    {
        $user = $this->makeUser('catmanage_admin', User::CLASS_ADMINISTRATOR);

        $this->asUser($user)->get('/nexusphp/section/categories')->assertForbidden();
    }

    // ------------------------------------------------------------ panel pages

    public function testSysopCanAccessAllSectionResources(): void
    {
        $sysop = $this->makeUser('catmanage_sysop', User::CLASS_SYSOP);

        foreach (array_keys($this->resourceRoutes()) as $uri) {
            $this->asUser($sysop)
                ->get('/nexusphp/' . $uri)
                ->assertOk();
        }
    }

    public function testSysopSeesCategoryList(): void
    {
        $sysop = $this->makeUser('catmanage_list_sysop', User::CLASS_SYSOP);
        $searchBox = SearchBox::query()->first();
        $category = Category::query()->create([
            'name' => 'cat_test_' . substr(md5((string) mt_rand()), 0, 6),
            'image' => 'cat.gif',
            'mode' => $searchBox ? $searchBox->id : 1,
            'icon_id' => Icon::query()->value('id') ?: 1,
            'class_name' => '',
            'sort_index' => 0,
        ]);

        try {
            $this->asUser($sysop)
                ->get('/nexusphp/section/categories')
                ->assertOk()
                ->assertSee($category->name, false);
        } finally {
            DB::table('categories')->where('id', $category->id)->delete();
        }
    }
}
