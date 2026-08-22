<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the viewrequests.php migration
 * (RequestController::web) against a real database.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class RequestPageTest extends TestCase
{
    private array $createdUserIds = [];

    private array $createdRequestIds = [];

    private array $createdTorrentIds = [];

    private int $categoryId = 401;

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
        if ($this->createdRequestIds !== []) {
            $reqIds = array_values(array_unique($this->createdRequestIds));
            DB::table('resreq')->whereIn('reqid', $reqIds)->delete();
            DB::table('comments')->whereIn('request', $reqIds)->delete();
            DB::table('requests')->whereIn('id', $reqIds)->delete();
        }
        if ($this->createdTorrentIds !== []) {
            DB::table('torrents')->whereIn('id', $this->createdTorrentIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            $ids = array_values(array_unique($this->createdUserIds));
            DB::table('requests')->whereIn('userid', $ids)->delete();
            DB::table('resreq')->whereIn('reqid', function ($query) use ($ids) {
                $query->select('id')->from('requests')->whereIn('userid', $ids);
            })->delete();
            DB::table('comments')->whereIn('user', $ids)->delete();
            DB::table('messages')->whereIn('receiver', $ids)->where('sender', 0)->delete();
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
            'seedbonus' => 10000,
            'showfb' => 'yes',
            'hidehb' => 'no',
            'added' => now(),
            'last_access' => now(),
            'commentpm' => 'no',
            'last_pm' => now()->subMinutes(5),
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

    private function getRequests(User $user, string $query = '')
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/viewrequests.php' . $query);
    }

    private function postRequests(User $user, array $data, string $query = '')
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->post('/viewrequests.php' . $query, $data);
    }

    private function makeRequest(User $user, array $overrides = []): int
    {
        $id = DB::table('requests')->insertGetId(array_merge([
            'userid' => $user->id,
            'request' => 'Test Request',
            'descr' => 'This is a test request description.',
            'ori_descr' => 'This is a test request description.',
            'comments' => 0,
            'hits' => 0,
            'finish' => 'no',
            'amount' => 1000,
            'ori_amount' => 1000,
            'added' => now(),
        ], $overrides));
        $this->createdRequestIds[] = $id;

        return $id;
    }

    private function makeTorrent(User $owner, array $overrides = []): int
    {
        $id = DB::table('torrents')->insertGetId(array_merge([
            'name' => 'Request Supply Torrent',
            'small_descr' => 'a small description',
            'category' => $this->categoryId,
            'owner' => $owner->id,
            'added' => now(),
            'visible' => 'yes',
            'banned' => 'no',
            'anonymous' => 'no',
            'sp_state' => 1,
            'promotion_time_type' => 0,
            'pos_state' => 'normal',
            'picktype' => 'normal',
            'url' => '',
        ], $overrides));
        $this->createdTorrentIds[] = $id;

        return $id;
    }

    private function makeSupply(int $requestId, int $torrentId, array $overrides = []): void
    {
        DB::table('resreq')->insert(array_merge([
            'reqid' => $requestId,
            'torrentid' => $torrentId,
            'chosen' => 'no',
        ], $overrides));
    }

    // ------------------------------------------------------------------ auth

    public function testRequestRequiresLogin()
    {
        $this->get('/viewrequests.php')->assertRedirect();
    }

    // ------------------------------------------------------------- main list

    public function testMainListRenders()
    {
        $user = $this->makeUser('request_list_user');
        $this->makeRequest($user, ['request' => 'Visible List Request']);

        $response = $this->getRequests($user);
        $response->assertOk();
        $response->assertSee('Requests', false);
        $response->assertSee('Visible List Request');
        $response->assertSee('Add request');
    }

    public function testListFiltersByFinished()
    {
        $user = $this->makeUser('request_finished_user');
        $this->makeRequest($user, ['request' => 'Open Request', 'finish' => 'no']);
        $this->makeRequest($user, ['request' => 'Resolved Request', 'finish' => 'yes']);

        $this->getRequests($user, '?finished=yes')
            ->assertOk()
            ->assertSee('Resolved Request')
            ->assertDontSee('Open Request');

        $this->getRequests($user, '?finished=no')
            ->assertOk()
            ->assertSee('Open Request')
            ->assertDontSee('Resolved Request');
    }

    public function testListFilterMy()
    {
        $owner = $this->makeUser('request_my_owner');
        $other = $this->makeUser('request_my_other');
        $this->makeRequest($owner, ['request' => 'My Own Request', 'finish' => 'no']);
        $this->makeRequest($other, ['request' => 'Other User Request', 'finish' => 'no']);

        $this->getRequests($owner, '?finished=my')
            ->assertOk()
            ->assertSee('My Own Request')
            ->assertDontSee('Other User Request');
    }

    public function testListSearchQuery()
    {
        $user = $this->makeUser('request_search_user');
        $this->makeRequest($user, ['request' => 'Unique Searchable Thing', 'finish' => 'no']);
        $this->makeRequest($user, ['request' => 'Another Request', 'finish' => 'no']);

        $response = $this->postRequests($user, [
            'action' => 'list',
            'finished' => 'all',
            'query' => 'Unique Searchable',
        ]);
        $response->assertOk();
        $response->assertSee('Unique Searchable Thing');
        $response->assertDontSee('Another Request');
    }

    public function testListFilterIngOnlyWhenSupplied()
    {
        $user = $this->makeUser('request_ing_user');
        $supplier = $this->makeUser('request_ing_supplier');
        $id = $this->makeRequest($user, ['request' => 'Supplied Request', 'finish' => 'no']);
        $this->makeRequest($user, ['request' => 'Unsolicited Request', 'finish' => 'no']);
        $torrentId = $this->makeTorrent($supplier);
        $this->makeSupply($id, $torrentId);

        $this->getRequests($user, '?finished=ing')
            ->assertOk()
            ->assertSee('Supplied Request')
            ->assertDontSee('Unsolicited Request');
    }

    // ------------------------------------------------------------- details

    public function testRequestDetailsRenders()
    {
        $user = $this->makeUser('request_details_user');
        $id = $this->makeRequest($user, ['request' => 'Detail View Request']);

        $response = $this->getRequests($user, '?action=view&id=' . $id);
        $response->assertOk();
        $response->assertSee('Detail View Request');
        $response->assertSee('This is a test request description.');
    }

    public function testRequestDetailsShowsSupply()
    {
        $owner = $this->makeUser('request_details_supply_owner');
        $supplier = $this->makeUser('request_details_supplier');
        $id = $this->makeRequest($owner);
        $torrentId = $this->makeTorrent($supplier, ['name' => 'Supply Name In Details']);
        $this->makeSupply($id, $torrentId);

        $response = $this->getRequests($owner, '?action=view&id=' . $id);
        $response->assertOk();
        $response->assertSee('Supply Name In Details');
        $response->assertSee('Use select resource for request', false);
    }

    public function testRequestDetailsMissingIdErrors()
    {
        $user = $this->makeUser('request_details_missing');
        $this->getRequests($user, '?action=view&id=999999')
            ->assertOk()
            ->assertSee('Target not exists', false);
    }

    public function testRequestDetailsWithComments()
    {
        $user = $this->makeUser('request_details_cmt');
        $commenter = $this->makeUser('request_details_cmt_user');
        $id = $this->makeRequest($user, ['request' => 'Commented Request']);

        DB::table('comments')->insert([
            'user' => $commenter->id,
            'request' => $id,
            'added' => now(),
            'text' => 'A nice comment on the request.',
            'ori_text' => 'A nice comment on the request.',
        ]);
        DB::table('requests')->where('id', $id)->update(['comments' => 1]);

        $response = $this->getRequests($user, '?action=view&id=' . $id);
        $response->assertOk();
        $response->assertSee('Commented Request');
        $response->assertSee('A nice comment on the request.');
    }

    // ------------------------------------------------------------- add request

    public function testNewRequestFormRenders()
    {
        $user = $this->makeUser('request_new_form_user');

        $response = $this->getRequests($user, '?action=new');
        $response->assertOk();
        $response->assertSee('Add request');
        $response->assertSee('name=request', false);
        $response->assertSee('textarea');
    }

    public function testTakeAddedCreatesRequest()
    {
        $user = $this->makeUser('request_takeadded_user');

        $response = $this->postRequests($user, [
            'action' => 'takeadded',
            'request' => 'Brand New Request',
            'amount' => '2000',
            'descr' => 'A brand new request description.',
        ]);
        $response->assertOk();
        $response->assertSee('Add request success', false);

        $row = DB::table('requests')->where('request', 'Brand New Request')->first();
        $this->assertNotNull($row);
        $this->assertEquals($user->id, $row->userid);
        $this->assertEquals(2000, $row->amount);
        $this->assertEquals(2000, $row->ori_amount);
        // 100 bonus service charge deducted on top of the reward
        $this->assertEquals(10000 - 2100, DB::table('users')->where('id', $user->id)->value('seedbonus'));
    }

    public function testTakeAddedRejectsTooSmallReward()
    {
        $user = $this->makeUser('request_takeadded_small');

        $this->postRequests($user, [
            'action' => 'takeadded',
            'request' => 'Cheap Request',
            'amount' => '50',
            'descr' => 'desc',
        ])
            ->assertOk()
            ->assertSee('Reward can not less than 100 bouns', false);
    }

    public function testTakeAddedRejectsMissingDescription()
    {
        $user = $this->makeUser('request_takeadded_nodesc');

        $this->postRequests($user, [
            'action' => 'takeadded',
            'request' => 'No Desc Request',
            'amount' => '2000',
            'descr' => '',
        ])
            ->assertOk()
            ->assertSee('Description required', false);
    }

    // ------------------------------------------------------------- edit request

    public function testEditFormRenders()
    {
        $user = $this->makeUser('request_edit_form_user');
        $id = $this->makeRequest($user, ['request' => 'Editable Request']);

        $response = $this->getRequests($user, '?action=edit&id=' . $id);
        $response->assertOk();
        $response->assertSee('Editable Request');
        $response->assertSee('takeedit');
    }

    public function testTakeEditUpdatesRequest()
    {
        $user = $this->makeUser('request_takeedit_user');
        $id = $this->makeRequest($user, ['request' => 'Old Request Name']);

        $this->postRequests($user, [
            'action' => 'takeedit',
            'reqid' => $id,
            'request' => 'Updated Request Name',
            'descr' => 'Updated request description.',
        ])
            ->assertOk()
            ->assertSee('Edit request success', false);

        $row = DB::table('requests')->where('id', $id)->first();
        $this->assertEquals('Updated Request Name', $row->request);
        $this->assertEquals('Updated request description.', $row->descr);
    }

    public function testCannotEditOthersRequest()
    {
        $owner = $this->makeUser('request_edit_owner');
        $other = $this->makeUser('request_edit_other');
        $id = $this->makeRequest($owner);

        $this->getRequests($other, '?action=edit&id=' . $id)
            ->assertOk()
            ->assertSee('Permission denied', false);
    }

    // ------------------------------------------------------------- supply (res)

    public function testResFormRenders()
    {
        $owner = $this->makeUser('request_res_owner');
        $other = $this->makeUser('request_res_other');
        $id = $this->makeRequest($owner);

        $response = $this->getRequests($other, '?action=res&id=' . $id);
        $response->assertOk();
        $response->assertSee('I want request', false);
        $response->assertSee('name=torrentid', false);
    }

    public function testTakeResAddsSupplyAndPmsRequester()
    {
        $owner = $this->makeUser('request_takeres_owner');
        $supplier = $this->makeUser('request_takeres_supplier');
        $id = $this->makeRequest($owner, ['request' => 'Supply Target Request']);
        $torrentId = $this->makeTorrent($supplier);

        $this->postRequests($supplier, [
            'action' => 'takeres',
            'reqid' => $id,
            'torrentid' => $torrentId,
        ])
            ->assertOk()
            ->assertSee('Request success', false);

        $this->assertEquals(1, DB::table('resreq')->where('reqid', $id)->where('torrentid', $torrentId)->count());

        $message = DB::table('messages')
            ->where('receiver', $owner->id)
            ->where('sender', 0)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($message);
        $this->assertStringContainsString('Supply Target Request', $message->msg);
    }

    public function testTakeResRejectsDuplicateSupply()
    {
        $owner = $this->makeUser('request_takeres_dup_owner');
        $supplier = $this->makeUser('request_takeres_dup_supplier');
        $id = $this->makeRequest($owner);
        $torrentId = $this->makeTorrent($supplier);
        $this->makeSupply($id, $torrentId);

        $this->postRequests($supplier, [
            'action' => 'takeres',
            'reqid' => $id,
            'torrentid' => $torrentId,
        ])
            ->assertOk()
            ->assertSee('This supply already exists', false);
    }

    public function testTakeResRejectsMissingTorrent()
    {
        $owner = $this->makeUser('request_takeres_missing_owner');
        $supplier = $this->makeUser('request_takeres_missing_supplier');
        $id = $this->makeRequest($owner);

        $this->postRequests($supplier, [
            'action' => 'takeres',
            'reqid' => $id,
            'torrentid' => 999999,
        ])
            ->assertOk()
            ->assertSee('Target not exists', false);
    }

    // ------------------------------------------------------------- add reward

    public function testAddAmount()
    {
        $user = $this->makeUser('request_addamount_user');
        $id = $this->makeRequest($user, ['amount' => 1000, 'ori_amount' => 1000]);

        $this->postRequests($user, [
            'action' => 'addamount',
            'reqid' => $id,
            'amount' => '500',
        ])
            ->assertOk()
            ->assertSee('Add reward success', false);

        $this->assertEquals(1500, DB::table('requests')->where('id', $id)->value('amount'));
        // 25 bonus service charge
        $this->assertEquals(10000 - 525, DB::table('users')->where('id', $user->id)->value('seedbonus'));
    }

    public function testAddAmountRejectsTooLarge()
    {
        $user = $this->makeUser('request_addamount_large');
        $id = $this->makeRequest($user);

        $this->postRequests($user, [
            'action' => 'addamount',
            'reqid' => $id,
            'amount' => '6000',
        ])
            ->assertOk()
            ->assertSee('Add reward amount can not more than 5000 bonus', false);
    }

    // ------------------------------------------------------------- delete

    public function testDeleteRequestWithoutSupplyRefundsEightyPercent()
    {
        $user = $this->makeUser('request_delete_refund');
        $id = $this->makeRequest($user, ['amount' => 1000, 'ori_amount' => 1000]);

        $this->getRequests($user, '?action=delete&id=' . $id)
            ->assertOk()
            ->assertSee('Delete request success', false);

        $this->assertNull(DB::table('requests')->where('id', $id)->first());
        // 80% of the reward is given back
        $this->assertEquals(10000 + 800, DB::table('users')->where('id', $user->id)->value('seedbonus'));
    }

    public function testDeleteRequestWithSupplyNoRefund()
    {
        $user = $this->makeUser('request_delete_norefund');
        $supplier = $this->makeUser('request_delete_norefund_supplier');
        $id = $this->makeRequest($user, ['amount' => 1000, 'ori_amount' => 1000]);
        $torrentId = $this->makeTorrent($supplier);
        $this->makeSupply($id, $torrentId);

        $this->getRequests($user, '?action=delete&id=' . $id)
            ->assertOk()
            ->assertSee('Delete request success', false);

        $this->assertNull(DB::table('requests')->where('id', $id)->first());
        $this->assertEquals(10000, DB::table('users')->where('id', $user->id)->value('seedbonus'));
    }

    public function testDeleteOthersRequestRejected()
    {
        $owner = $this->makeUser('request_delete_owner');
        $other = $this->makeUser('request_delete_other');
        $id = $this->makeRequest($owner);

        $this->getRequests($other, '?action=delete&id=' . $id)
            ->assertOk()
            ->assertSee('Permission denied', false);
        $this->assertNotNull(DB::table('requests')->where('id', $id)->first());
    }

    // ------------------------------------------------------------- confirm

    public function testConfirmFinishesRequestAndPaysUploaders()
    {
        $owner = $this->makeUser('request_confirm_owner');
        $uploader = $this->makeUser('request_confirm_uploader');
        $id = $this->makeRequest($owner, ['request' => 'Confirmable Request', 'amount' => 1000]);
        $torrentId = $this->makeTorrent($uploader, ['name' => 'Confirmed Supply Torrent']);
        $this->makeSupply($id, $torrentId);

        $response = $this->postRequests($owner, [
            'action' => 'confirm',
            'id' => $id,
            'torrentid' => [$torrentId],
        ]);
        $response->assertOk();
        $response->assertSee('Confirm success', false);

        $this->assertEquals('yes', DB::table('requests')->where('id', $id)->value('finish'));
        $this->assertEquals('yes', DB::table('resreq')->where('reqid', $id)->where('torrentid', $torrentId)->value('chosen'));
        // uploader gets the full reward split across selected torrents
        $this->assertEquals(10000 + 1000, DB::table('users')->where('id', $uploader->id)->value('seedbonus'));

        $message = DB::table('messages')
            ->where('receiver', $uploader->id)
            ->where('sender', 0)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($message);
        $this->assertStringContainsString('Confirmable Request', $message->msg);
    }

    // ------------------------------------------------------------- message

    public function testMessageAddsCommentAndPmsRequester()
    {
        $owner = $this->makeUser('request_message_owner');
        $commenter = $this->makeUser('request_message_commenter');
        $id = $this->makeRequest($owner, ['request' => 'Commentable Request']);

        $response = $this->postRequests($commenter, [
            'action' => 'message',
            'id' => $id,
            'message' => 'A comment on the request.',
        ]);
        $response->assertRedirect();
        $response->assertRedirect('/viewrequests.php?action=view&id=' . $id);

        $this->assertEquals(1, DB::table('comments')->where('request', $id)->count());

        $message = DB::table('messages')
            ->where('receiver', $owner->id)
            ->where('sender', 0)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($message);
        $this->assertStringContainsString('Commentable Request', $message->msg);
    }

    public function testMessageRequiresText()
    {
        $owner = $this->makeUser('request_message_required_owner');
        $commenter = $this->makeUser('request_message_required_commenter');
        $id = $this->makeRequest($owner);

        $this->postRequests($commenter, [
            'action' => 'message',
            'id' => $id,
            'message' => '',
        ])
            ->assertOk()
            ->assertSee('Message required', false);
    }
}
