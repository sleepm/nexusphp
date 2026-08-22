<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the offers.php migration
 * (OfferController::web) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class OfferPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdOfferIds = [];

    private ?int $categoryId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';

        // pick or create a visible category for the offer
        $this->categoryId = DB::table('categories')->where('mode', 1)->value('id');
        if (! $this->categoryId) {
            $this->categoryId = DB::table('categories')->insertGetId([
                'mode' => 1,
                'name' => 'Offer Test Cat',
                'image' => '',
                'sort_index' => 0,
            ]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOfferIds as $offerId) {
            DB::table('offervotes')->where('offerid', $offerId)->delete();
            DB::table('comments')->where('offer', $offerId)->delete();
            DB::table('offers')->where('id', $offerId)->delete();
        }
        if ($this->createdUserIds !== []) {
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('offers')->whereIn('userid', $ids)->delete();
            DB::table('offervotes')->whereIn('userid', $ids)->delete();
            DB::table('comments')->whereIn('user', $ids)->delete();
            DB::table('messages')->whereIn('receiver', $ids)->where('sender', 0)->delete();
            DB::table('sitelog')->whereIn('uid', $ids)->where('txt', 'like', 'offer %')->delete();
            DB::table('staffmessages')->whereIn('sender', $ids)->delete();
            DB::table('users')->whereIn('id', $ids)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $username, $class = User::CLASS_USER, array $overrides = []): User
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
            'last_offer' => now()->subDay()->toDateTimeString(),
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

    private function getOffer(User $user, string $query = '')
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/offers.php' . $query);
    }

    private function postOffer(User $user, string $query = '', array $data = [])
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->post('/offers.php' . $query, $data);
    }

    private function makeOffer(User $user, array $overrides = []): int
    {
        $id = DB::table('offers')->insertGetId(array_merge([
            'userid' => $user->id,
            'name' => 'Test Offer',
            'descr' => 'This is a test offer description.',
            'category' => $this->categoryId,
            'added' => now(),
            'allowed' => 'pending',
            'yeah' => 0,
            'against' => 0,
            'comments' => 0,
        ], $overrides));
        $this->createdOfferIds[] = $id;

        return $id;
    }

    // ------------------------------------------------------------------ auth

    public function testOfferRequiresLogin()
    {
        $this->get('/offers.php')->assertRedirect();
    }

    // ------------------------------------------------------------- main list

    public function testMainListRenders()
    {
        $user = $this->makeUser('offer_list_user');
        $offerId = $this->makeOffer($user);

        $response = $this->getOffer($user);
        $response->assertOk();
        $response->assertSee('Offers Section');
        $response->assertSee('Test Offer');
        $response->assertSee('Pending');
    }

    public function testMainListWithSearch()
    {
        $user = $this->makeUser('offer_search_user');
        $this->makeOffer($user, ['name' => 'Unique Searchable Offer']);
        $this->makeOffer($user, ['name' => 'Another Offer']);

        $response = $this->getOffer($user, '?search=Unique');
        $response->assertOk();
        $response->assertSee('Unique Searchable Offer');
        $response->assertDontSee('Another Offer');
    }

    // ----------------------------------------------------------- add offer

    public function testAddOfferFormRenders()
    {
        $user = $this->makeUser('offer_add_form_user');

        $response = $this->getOffer($user, '?add_offer=1');
        $response->assertOk();
        $response->assertSee('Offers are open to all users');
        $response->assertSee('name="compose"', false);
        $response->assertSee('textarea');
    }

    public function testCreateOffer()
    {
        $user = $this->makeUser('offer_create_user');

        $response = $this->postOffer($user, '?new_offer=1', [
            'name' => 'Newly Created Offer',
            'type' => $this->categoryId,
            'body' => 'This is the description of the new offer.',
        ]);
        $response->assertRedirect();

        $offer = DB::table('offers')->where('name', 'Newly Created Offer')->first();
        $this->assertNotNull($offer);
        $this->assertEquals($user->id, $offer->userid);
        $this->assertEquals('pending', $offer->allowed);
    }

    public function testCreateOfferWithoutNameReturnsError()
    {
        $user = $this->makeUser('offer_create_no_name');

        $response = $this->postOffer($user, '?new_offer=1', [
            'type' => $this->categoryId,
            'body' => 'desc',
        ]);
        $response->assertOk();
        $response->assertSee('You must enter a name');
    }

    // ---------------------------------------------------------- details

    public function testOfferDetailsRenders()
    {
        $user = $this->makeUser('offer_details_user');
        $offerId = $this->makeOffer($user, ['name' => 'Details Offer']);

        $response = $this->getOffer($user, '?id=' . $offerId . '&off_details=1');
        $response->assertOk();
        $response->assertSee('Details Offer');
        $response->assertSee('Pending');
    }

    public function testOfferDetailsShowsForVoteLink()
    {
        $user = $this->makeUser('offer_details_vote');
        $offerId = $this->makeOffer($user, ['name' => 'Votable Offer']);

        $response = $this->getOffer($user, '?id=' . $offerId . '&off_details=1');
        $response->assertOk();
        $response->assertSee('vote=yeah');
    }

    public function testOfferDetailsWithComments()
    {
        $owner = $this->makeUser('offer_details_cmt');
        $commenter = $this->makeUser('offer_details_cmt_user');
        $offerId = $this->makeOffer($owner, ['name' => 'Commented Offer']);

        DB::table('comments')->insert([
            'user' => $commenter->id,
            'offer' => $offerId,
            'added' => now(),
            'text' => 'A nice comment on the offer.',
        ]);
        DB::table('offers')->where('id', $offerId)->update(['comments' => 1]);

        $response = $this->getOffer($owner, '?id=' . $offerId . '&off_details=1');
        $response->assertOk();
        $response->assertSee('Commented Offer');
        $response->assertSee('A nice comment on the offer.');
    }

    // -------------------------------------------------------------- vote

    public function testCastVote()
    {
        $owner = $this->makeUser('offer_vote_owner');
        $voter = $this->makeUser('offer_vote_voter');
        $offerId = $this->makeOffer($owner, ['name' => 'Votable Offer']);

        $response = $this->getOffer($voter, '?id=' . $offerId . '&vote=yeah');
        $response->assertOk();
        $response->assertSee('Vote accepted');

        $this->assertEquals(1, DB::table('offervotes')->where('offerid', $offerId)->where('userid', $voter->id)->count());
        $this->assertEquals(1, DB::table('offers')->where('id', $offerId)->value('yeah'));
    }

    public function testCannotVoteOwnOffer()
    {
        $owner = $this->makeUser('offer_vote_own');
        $offerId = $this->makeOffer($owner);

        $response = $this->getOffer($owner, '?id=' . $offerId . '&vote=yeah');
        $response->assertOk();
        $response->assertSee('You cannot vote for your own offers');
    }

    public function testCannotVoteTwice()
    {
        $owner = $this->makeUser('offer_vote_twice_owner');
        $voter = $this->makeUser('offer_vote_twice_voter');
        $offerId = $this->makeOffer($owner);

        $this->getOffer($voter, '?id=' . $offerId . '&vote=yeah');
        $response = $this->getOffer($voter, '?id=' . $offerId . '&vote=yeah');
        $response->assertSee('already voted');
    }

    // ----------------------------------------------------------- delete

    public function testDeleteOfferConfirmPage()
    {
        $owner = $this->makeUser('offer_del_confirm');
        $offerId = $this->makeOffer($owner);

        $response = $this->getOffer($owner, '?id=' . $offerId . '&del_offer=1&sure=0');
        $response->assertOk();
        $response->assertSee('Delete Offer');
        $response->assertSee('confirm');
    }

    public function testDeleteOffer()
    {
        $owner = $this->makeUser('offer_del_exec');
        $offerId = $this->makeOffer($owner, ['name' => 'Offer To Delete']);

        $response = $this->postOffer($owner, '?id=' . $offerId . '&del_offer=1&sure=1', ['reason' => 'Test deletion']);
        $response->assertRedirect();
        $response->assertRedirect('/offers.php');

        $this->assertNull(DB::table('offers')->where('id', $offerId)->first());
    }

    // ----------------------------------------------------------- edit

    public function testEditOfferFormRenders()
    {
        $owner = $this->makeUser('offer_edit_form');
        $offerId = $this->makeOffer($owner);

        $response = $this->getOffer($owner, '?id=' . $offerId . '&edit_offer=1');
        $response->assertOk();
        $response->assertSee('Edit Offer');
        $response->assertSee('take_off_edit');
        $response->assertSee('textarea');
    }

    public function testUpdateOffer()
    {
        $owner = $this->makeUser('offer_edit_exec');
        $offerId = $this->makeOffer($owner, ['name' => 'Old Name']);

        $this->postOffer($owner, '?id=' . $offerId . '&take_off_edit=1', [
            'name' => 'Updated Name',
            'body' => 'Updated description',
            'category' => $this->categoryId,
        ]);

        $this->assertEquals('Updated Name', DB::table('offers')->where('id', $offerId)->value('name'));
    }

    // ---------------------------------------------------------- vote list

    public function testVoteListRenders()
    {
        $owner = $this->makeUser('offer_votelist_owner');
        $voter = $this->makeUser('offer_votelist_voter');
        $offerId = $this->makeOffer($owner, ['name' => 'Vote List Offer']);

        DB::table('offervotes')->insert([
            'offerid' => $offerId,
            'userid' => $voter->id,
            'vote' => 'yeah',
        ]);

        $response = $this->getOffer($owner, '?id=' . $offerId . '&offer_vote=1');
        $response->assertOk();
        $response->assertSee('Vote List Offer');
        $response->assertSee($voter->username);
    }

    // ---------------------------------------------------------------- staff actions

    public function testAllowOfferByStaff()
    {
        $mod = $this->makeUser('offer_allow_mod', User::CLASS_MODERATOR);
        $owner = $this->makeUser('offer_allow_owner');
        $offerId = $this->makeOffer($owner, ['name' => 'Allowable Offer']);

        $response = $this->postOffer($mod, '?allow_offer=1', ['offerid' => $offerId]);
        $response->assertRedirect();

        $this->assertEquals('allowed', DB::table('offers')->where('id', $offerId)->value('allowed'));
    }

    public function testFinishOfferByVoteAllowed()
    {
        $mod = $this->makeUser('offer_finish_mod', User::CLASS_MODERATOR);
        $owner = $this->makeUser('offer_finish_owner');
        $offerId = $this->makeOffer($owner, ['name' => 'Finishable Offer']);

        // add enough votes to meet the min threshold (default 15)
        $minVotes = (int) get_setting('main.minoffervotes', 15);
        for ($i = 0; $i < $minVotes; $i++) {
            $voter = $this->makeUser('offer_finish_voter_' . $i);
            DB::table('offervotes')->insert([
                'offerid' => $offerId,
                'userid' => $voter->id,
                'vote' => 'yeah',
            ]);
        }

        $response = $this->postOffer($mod, '?id=' . $offerId . '&finish_offer=1', ['finish' => $offerId]);
        $response->assertRedirect();

        $this->assertEquals('allowed', DB::table('offers')->where('id', $offerId)->value('allowed'));
    }
}