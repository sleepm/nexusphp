<?php

namespace Tests\Feature;

use App\Models\AgentAllow;
use App\Models\AgentDeny;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the public/allagents.php migration to the
 * Filament AgentAllowResource / AgentDenyResource.
 *
 * The legacy procedural "All Clients" page (UC_MODERATOR gate, listing
 * agents from the peers table) is replaced by the System\AgentAllowResource
 * and System\AgentDenyResource. The legacy entry point now redirects to
 * the admin panel.
 */
class AllAgentsPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdAllowIds = [];

    private array $createdDenyIds = [];

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
        if ($this->createdAllowIds !== []) {
            DB::table('agent_allowed_family')->whereIn('id', $this->createdAllowIds)->delete();
        }
        if ($this->createdDenyIds !== []) {
            DB::table('agent_allowed_exception')->whereIn('id', $this->createdDenyIds)->delete();
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

    private function makeAgentAllow(string $family = 'TestClient'): AgentAllow
    {
        $allow = AgentAllow::query()->create([
            'family' => $family,
            'start_name' => $family,
            'peer_id_start' => '-ABC',
            'peer_id_pattern' => '^[-]ABC',
            'peer_id_match_num' => 4,
            'peer_id_matchtype' => 'dec',
            'agent_start' => $family,
            'agent_pattern' => '^' . $family,
            'agent_match_num' => 4,
            'agent_matchtype' => 'dec',
            'exception' => 'no',
            'allowhttps' => 'yes',
            'comment' => 'test allow',
        ]);
        $this->createdAllowIds[] = $allow->id;

        return $allow;
    }

    private function makeAgentDeny(int $familyId, string $name = 'BadClient'): AgentDeny
    {
        $deny = AgentDeny::query()->create([
            'family_id' => $familyId,
            'name' => $name,
            'peer_id' => '-BAD',
            'agent' => $name,
            'comment' => 'test deny',
        ]);
        $this->createdDenyIds[] = $deny->id;

        return $deny;
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

    public function testLegacyAllAgentsRedirectsToFilament(): void
    {
        $this->get('/allagents.php')
            ->assertRedirect(route('filament.admin.resources.system.agent-allows.index'));
    }

    // ---------------------------------------------------------- auth on panel

    public function testAgentAllowResourceRequiresLogin(): void
    {
        $this->get('/nexusphp/system/agent-allows')
            ->assertStatus(302);
    }

    // ------------------------------------------------------------ panel pages

    public function testAuthenticatedUserCanAccessAgentAllowResource(): void
    {
        $user = $this->makeUser('allagents_user', User::CLASS_ADMINISTRATOR);

        $this->asUser($user)
            ->get('/nexusphp/system/agent-allows')
            ->assertOk();
    }

    public function testAgentAllowListSeesFamily(): void
    {
        $user = $this->makeUser('allagents_list', User::CLASS_ADMINISTRATOR);
        $this->makeAgentAllow('uTorrent');

        $this->asUser($user)
            ->get('/nexusphp/system/agent-allows')
            ->assertOk()
            ->assertSee('uTorrent', false);
    }

    public function testAgentDenyResourceRequiresLogin(): void
    {
        $this->get('/nexusphp/system/agent-denies')
            ->assertStatus(302);
    }

    public function testAuthenticatedUserCanAccessAgentDenyResource(): void
    {
        $user = $this->makeUser('allagents_deny_user', User::CLASS_ADMINISTRATOR);

        $this->asUser($user)
            ->get('/nexusphp/system/agent-denies')
            ->assertOk();
    }

    public function testAgentDenyListSeesName(): void
    {
        $user = $this->makeUser('allagents_deny_list', User::CLASS_ADMINISTRATOR);
        $allow = $this->makeAgentAllow('TestClient');
        $this->makeAgentDeny($allow->id, 'BadClient');

        $this->asUser($user)
            ->get('/nexusphp/system/agent-denies')
            ->assertOk()
            ->assertSee('BadClient', false);
    }

    public function testCreateAgentAllowPersistsRecord(): void
    {
        $allow = AgentAllow::query()->create([
            'family' => 'PersistedClient',
            'start_name' => 'PersistedClient',
            'peer_id_start' => '-XYZ',
            'peer_id_pattern' => '^[-]XYZ',
            'peer_id_match_num' => 4,
            'peer_id_matchtype' => 'dec',
            'agent_start' => 'PersistedClient',
            'agent_pattern' => '^PersistedClient',
            'agent_match_num' => 4,
            'agent_matchtype' => 'dec',
            'exception' => 'no',
            'allowhttps' => 'yes',
            'comment' => 'persisted',
        ]);
        $this->createdAllowIds[] = $allow->id;

        $this->assertDatabaseHas('agent_allowed_family', [
            'id' => $allow->id,
            'family' => 'PersistedClient',
        ]);
    }

    public function testDeleteAgentAllowRemovesRow(): void
    {
        $allow = $this->makeAgentAllow('ToDelete');
        $allowId = $allow->id;

        $allow->delete();

        $this->assertDatabaseMissing('agent_allowed_family', ['id' => $allowId]);
    }

    public function testCreateAgentDenyPersistsRecord(): void
    {
        $allow = $this->makeAgentAllow('ParentClient');
        $deny = AgentDeny::query()->create([
            'family_id' => $allow->id,
            'name' => 'PersistedDeny',
            'peer_id' => '-PDN',
            'agent' => 'PersistedDeny',
            'comment' => 'persisted deny',
        ]);
        $this->createdDenyIds[] = $deny->id;

        $this->assertDatabaseHas('agent_allowed_exception', [
            'id' => $deny->id,
            'name' => 'PersistedDeny',
        ]);
    }

    public function testDeleteAgentDenyRemovesRow(): void
    {
        $allow = $this->makeAgentAllow('ParentForDeny');
        $deny = $this->makeAgentDeny($allow->id, 'ToDeleteDeny');

        $this->createdDenyIds = array_diff($this->createdDenyIds, [$deny->id]);
        $deny->delete();

        $this->assertDatabaseMissing('agent_allowed_exception', ['id' => $deny->id]);
    }
}