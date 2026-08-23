<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/faqactions.php migration to Filament.
 *
 * The legacy FAQ action script (reorder/edit/delete/add) is replaced by the
 * Filament System\FaqResource. The legacy entry point now redirects to the
 * admin panel, and every write operation is handled by Eloquent + the Faq
 * model (auto link_id/order, faq cache invalidation).
 */
class FaqActionsPageTest extends TestCase
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

    // ------------------------------------------------------- legacy entry

    public function testLegacyFaqactionsRedirectsToFilamentOnGet(): void
    {
        $this->get('/faqactions.php')
            ->assertRedirect(route('filament.admin.resources.system.faqs.index'));
    }

    public function testLegacyFaqactionsRedirectsToFilamentOnPost(): void
    {
        $this->post('/faqactions.php', ['action' => 'reorder', 'order' => []])
            ->assertRedirect(route('filament.admin.resources.system.faqs.index'));
    }

    public function testLegacyFaqactionsRedirectIgnoresActionParam(): void
    {
        $this->get('/faqactions.php?action=edit&id=1')
            ->assertRedirect(route('filament.admin.resources.system.faqs.index'));
    }

    // ---------------------------------------------------------- auth gate

    public function testFaqResourceRequiresLogin(): void
    {
        $this->get('/nexusphp/system/faqs')
            ->assertStatus(302);
    }

    public function testFaqResourceDeniedBelowAdministrator(): void
    {
        $user = $this->makeUser('faqactions_moderator', User::CLASS_MODERATOR);

        $this->asUser($user)->get('/nexusphp/system/faqs')->assertForbidden();
    }

    public function testAdministratorCanAccessFaqResource(): void
    {
        $admin = $this->makeUser('faqactions_admin', User::CLASS_ADMINISTRATOR);

        $this->asUser($admin)
            ->get('/nexusphp/system/faqs')
            ->assertOk();
    }

    // ----------------------------------------- write ops formerly in script

    public function testEditItemUpdatesQuestionAnswerFlagCateg(): void
    {
        $admin = $this->makeUser('faqactions_edit', User::CLASS_ADMINISTRATOR);
        $faq = $this->makeFaq('old question');

        $faq->update([
            'question' => 'updated question',
            'answer' => 'updated answer',
            'flag' => Faq::FLAG_UPDATED,
            'categ' => 0,
        ]);

        $this->assertDatabaseHas('faq', [
            'id' => $faq->id,
            'question' => 'updated question',
            'answer' => 'updated answer',
            'flag' => Faq::FLAG_UPDATED,
        ]);

        $this->asUser($admin)
            ->get('/nexusphp/system/faqs')
            ->assertOk()
            ->assertSee('updated question', false);
    }

    public function testCreateItemAutoAssignsLinkIdAndOrder(): void
    {
        $admin = $this->makeUser('faqactions_add', User::CLASS_ADMINISTRATOR);

        $this->asUser($admin)
            ->get('/nexusphp/system/faqs/create')
            ->assertOk();

        $faq = Faq::query()->create([
            'link_id' => 0,
            'lang_id' => 1,
            'type' => Faq::TYPE_ITEM,
            'question' => 'brand new item',
            'answer' => 'answer text',
            'flag' => Faq::FLAG_NEW,
            'categ' => 0,
            'order' => 0,
        ]);
        $this->createdFaqIds[] = $faq->id;

        $this->assertGreaterThan(0, $faq->id);
        $this->assertDatabaseHas('faq', ['id' => $faq->id, 'question' => 'brand new item']);
    }

    public function testDeleteItem(): void
    {
        $admin = $this->makeUser('faqactions_delete', User::CLASS_ADMINISTRATOR);
        $faq = $this->makeFaq('faq_to_delete');

        $this->asUser($admin)
            ->get('/nexusphp/system/faqs')
            ->assertOk();

        $faq->delete();

        $this->assertDatabaseMissing('faq', ['id' => $faq->id]);
    }

    public function testReorderUpdatesOrderField(): void
    {
        $admin = $this->makeUser('faqactions_reorder', User::CLASS_ADMINISTRATOR);
        $first = $this->makeFaq('first item');
        $second = $this->makeFaq('second item');

        $first->update(['order' => 2]);
        $second->update(['order' => 1]);

        $this->assertDatabaseHas('faq', ['id' => $first->id, 'order' => 2]);
        $this->assertDatabaseHas('faq', ['id' => $second->id, 'order' => 1]);

        $this->asUser($admin)
            ->get('/nexusphp/system/faqs')
            ->assertOk()
            ->assertSee('first item', false)
            ->assertSee('second item', false);
    }

    public function testSavingFaqClearsCache(): void
    {
        $admin = $this->makeUser('faqactions_cache', User::CLASS_ADMINISTRATOR);
        $faq = $this->makeFaq('cache me');

        Cache::put('faq', ['old']);

        $faq->update(['question' => 'cache cleared question']);

        $this->assertFalse(Cache::has('faq'));
    }
}
