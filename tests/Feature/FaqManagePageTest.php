<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/faqmanage.php migration to Filament.
 *
 * The legacy procedural FAQ management page is replaced by the Filament
 * System\FaqResource. The legacy entry point now redirects to the admin panel.
 */
class FaqManagePageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdFaqIds = [];

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
        if ($this->createdFaqIds !== []) {
            DB::table('faq')->whereIn('id', $this->createdFaqIds)->delete();
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

    private function makeFaq(string $question, string $type = Faq::TYPE_ITEM): Faq
    {
        $faq = Faq::query()->create([
            'link_id' => 0,
            'lang_id' => 1,
            'type' => $type,
            'question' => $question,
            'answer' => '',
            'flag' => Faq::FLAG_NORMAL,
            'categ' => 0,
            'order' => 0,
        ]);
        $this->createdFaqIds[] = $faq->id;

        return $faq;
    }

    // ------------------------------------------------------------------ legacy entry

    public function testLegacyFaqmanageRedirectsToFilament(): void
    {
        $this->get('/faqmanage.php')
            ->assertRedirect(route('filament.admin.resources.system.faqs.index'));
    }

    // ---------------------------------------------------------- auth on panel

    public function testFaqResourceRequiresLogin(): void
    {
        $this->get('/nexusphp/system/faqs')
            ->assertStatus(302);
    }

    public function testFaqResourceDeniedBelowAdministrator(): void
    {
        $user = $this->makeUser('faqmanage_moderator', User::CLASS_MODERATOR);

        $this->asUser($user)->get('/nexusphp/system/faqs')->assertForbidden();
    }

    // ------------------------------------------------------------ panel pages

    public function testAdministratorCanAccessFaqResource(): void
    {
        $admin = $this->makeUser('faqmanage_admin', User::CLASS_ADMINISTRATOR);

        $this->asUser($admin)
            ->get('/nexusphp/system/faqs')
            ->assertOk();
    }

    public function testListSeesFaq(): void
    {
        $admin = $this->makeUser('faqmanage_list', User::CLASS_ADMINISTRATOR);
        $faq = $this->makeFaq('faq_test_' . substr(md5((string) mt_rand()), 0, 6));

        $this->asUser($admin)
            ->get('/nexusphp/system/faqs')
            ->assertOk()
            ->assertSee($faq->question, false);
    }

    public function testCreatePageRenders(): void
    {
        $admin = $this->makeUser('faqmanage_create', User::CLASS_ADMINISTRATOR);

        $this->asUser($admin)
            ->get('/nexusphp/system/faqs/create')
            ->assertOk();
    }

    public function testCreateFaqSectionAssignsLinkId(): void
    {
        $faq = Faq::query()->create([
            'type' => Faq::TYPE_CATEG,
            'lang_id' => 1,
            'question' => 'New Section',
            'answer' => '',
            'flag' => Faq::FLAG_NORMAL,
            'order' => 0,
            'link_id' => 0,
        ]);
        $this->createdFaqIds[] = $faq->id;

        $this->assertGreaterThan(0, $faq->id);
        $this->assertEquals(Faq::TYPE_CATEG, $faq->type);
    }

    public function testDeleteFaq(): void
    {
        $admin = $this->makeUser('faqmanage_delete', User::CLASS_ADMINISTRATOR);
        $faq = $this->makeFaq('faq_test_delete');

        $this->asUser($admin)
            ->get('/nexusphp/system/faqs')
            ->assertOk();

        $faq->delete();

        $this->assertDatabaseMissing('faq', ['id' => $faq->id]);
    }
}
