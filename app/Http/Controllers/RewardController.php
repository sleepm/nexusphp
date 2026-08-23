<?php

namespace App\Http\Controllers;

use App\Http\Resources\RewardResource;
use App\Http\Resources\PeerResource;
use App\Http\Resources\SnatchResource;
use App\Models\BonusLogs;
use App\Models\Peer;
use App\Models\Reward;
use App\Models\Setting;
use App\Models\Snatch;
use App\Models\Torrent;
use App\Models\User;
use App\Repositories\RewardRepository;
use App\Repositories\TorrentRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RewardController extends Controller
{
    private $repository;

    public function __construct(RewardRepository $repository)
    {
        $this->repository = $repository;
    }
    /**
     * @param Request $request
     * @return array
     */
    public function index(Request $request)
    {
        $request->validate([
            'torrent_id' => 'required',
        ]);
        $result = $this->repository->getList($request->all());
        $resource = RewardResource::collection($result);
        $resource->additional([
            'page_title' => nexus_trans('reward.index.page_title'),
        ]);

        return $this->success($resource);
    }


    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate([
            'torrent_id' => 'required',
            'value' => 'required',
        ]);
        $result = $this->repository->store($request->torrent_id, $request->value, Auth::user());
        $resource = new RewardResource($result);
        return $this->success($resource, '赠魔成功！');
    }

    /**
     * Give magic (seed bonus) to a torrent. Mirrors legacy public/magic.php: a
     * logged-in session posts {id, value}; the value must be one of the
     * configured reward options, the giver must own enough seed bonus, the
     * torrent must exist and not be owned by the giver, each torrent may only
     * be rewarded once per user and there is a daily per-user cap. On success
     * the giver's seed bonus is transferred to the torrent owner, both sides
     * are recorded in bonus logs and the magic table, and a JSON success/fail
     * response is returned (the legacy shape: {ret, msg, data}).
     */
    public function web(Request $request)
    {
        $user = Auth::guard('nexus')->user();
        $torrentId = (int) $request->post('id', 0);
        $value = (int) abs((int) $request->post('value', 0));
        $payload = $request->all();

        if (!in_array($value, Setting::getBonusRewardOptions())) {
            return response()->json(fail("Invalid value.", $payload));
        }

        if ($value > (int) $user->seedbonus) {
            return response()->json(fail('You do not have such bonus!', $payload));
        }

        $torrent = Torrent::query()->where('id', $torrentId)->first(['id', 'owner']);
        if (!$torrent) {
            return response()->json(fail("Invalid torrent id!", $payload));
        }

        $torrentOwner = (int) $torrent->owner;
        if ($torrentOwner == $user->id) {
            return response()->json(fail('You are giving magic to yourself.', $payload));
        }

        $alreadyGave = Reward::query()
            ->where('torrentid', $torrentId)
            ->where('userid', $user->id)
            ->exists();
        if ($alreadyGave) {
            return response()->json(fail("You already gave the magic value!", $payload));
        }

        $todayStr = now()->startOfDay();
        $todayCount = Reward::query()
            ->where('userid', $user->id)
            ->where('created_at', '>=', $todayStr)
            ->count();
        $timesLimit = Setting::getBonusRewardTimesLimit();
        if ($timesLimit > 0 && $todayCount >= $timesLimit) {
            return response()->json(fail("You already reach times limit!", $payload));
        }

        $torrentOwnerInfo = User::query()->find($torrentOwner, User::$commonFields);
        if (!$torrentOwnerInfo) {
            return response()->json(fail("Invalid torrent owner!", $payload));
        }

        DB::transaction(function () use ($torrentId, $user, $value, $torrentOwner, $torrentOwnerInfo) {
            Reward::query()->create([
                'torrentid' => $torrentId,
                'userid' => $user->id,
                'value' => $value,
            ]);

            User::query()->where('id', $user->id)->decrement('seedbonus', $value);
            BonusLogs::add(
                $user->id,
                (float) $user->seedbonus,
                $value,
                (float) $user->seedbonus - $value,
                "",
                BonusLogs::BUSINESS_TYPE_REWARD_TORRENT
            );

            User::query()->where('id', $torrentOwner)->increment('seedbonus', $value);
            BonusLogs::add(
                $torrentOwnerInfo['id'],
                (float) $torrentOwnerInfo['seedbonus'],
                $value,
                (float) $torrentOwnerInfo['seedbonus'] + $value,
                "",
                BonusLogs::BUSINESS_TYPE_TORRENT_BE_REWARD
            );
        });

        return response()->json(success("OK", $payload));
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
