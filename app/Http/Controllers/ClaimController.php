<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use App\Models\Torrent;
use App\Models\User;
use App\Repositories\ClaimRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

/**
 * Web endpoints replacing legacy public/claim.php (Phase 2 P1).
 */
class ClaimController extends Controller
{
    private $repository;

    public function __construct(ClaimRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Claim list, mirrors legacy public/claim.php GET.
     */
    public function index(Request $request)
    {
        if (!$request->filled('torrent_id') && !$request->filled('uid')) {
            abort(404, 'Require torrent_id or uid');
        }

        $sortAllowed = [
            'created_at' => nexus_trans('claim.th_claim_at'),
            'last_settle_at' => nexus_trans('claim.th_last_settle'),
            'seed_time' => nexus_trans('claim.th_seed_time_this_month'),
            'uploaded' => nexus_trans('claim.th_uploaded_this_month'),
        ];
        $orderAllowed = [
            'asc' => nexus_trans('nexus.asc'),
            'desc' => nexus_trans('nexus.desc'),
        ];

        $sort = $request->get('sort', 'created_at');
        if (!isset($sortAllowed[$sort])) {
            $sort = 'created_at';
        }
        $order = $request->get('order', 'asc');
        if (!isset($orderAllowed[$order])) {
            $order = 'asc';
        }

        $torrentId = $uid = 0;
        $query = Claim::query();

        if ($request->filled('torrent_id')) {
            $torrentId = (int) $request->get('torrent_id');
            $torrent = Torrent::query()->find($torrentId, Torrent::$commentFields);
            if (!$torrent) {
                abort(404, "Invalid torrent_id: $torrentId");
            }
            $query->where('torrent_id', $torrentId);
        } elseif ($request->filled('uid')) {
            $uid = (int) $request->get('uid');
            $user = User::query()->find($uid, User::$commonFields);
            if (!$user) {
                abort(404, "Invalid uid: $uid");
            }
            $query->where('uid', $uid);
        }

        $pageIndex = max(0, (int) $request->get('page', 0));
        $page = $pageIndex + 1;
        $perPage = 50;
        $total = (clone $query)->count();

        if ($sort == 'seed_time') {
            $query->join('snatched', 'claims.snatched_id', '=', 'snatched.id')
                ->orderByRaw("(snatched.seedtime - claims.seed_time_begin) $order");
        } elseif ($sort == 'uploaded') {
            $query->join('snatched', 'claims.snatched_id', '=', 'snatched.id')
                ->orderByRaw("(snatched.uploaded - claims.uploaded_begin) $order");
        } else {
            $query->orderBy($sort, $order);
        }

        $list = $query
            ->selectRaw('claims.*')
            ->with(['user', 'torrent', 'snatch'])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $paginator = new LengthAwarePaginator(
            $list,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $currentUser = Auth::user();
        $claimRepository = new ClaimRepository();
        $torrentTool = new \Nexus\Torrent\Torrent();
        $torrentIdList = $list->pluck('torrent_id')->toArray();
        $leechingSeedingStatus = [];
        if ($currentUser && $torrentIdList) {
            $leechingSeedingStatus = $torrentTool->listLeechingSeedingStatus($currentUser->id, $torrentIdList);
        }

        $now = Carbon::now();
        $rows = $list->map(function (Claim $row) use ($claimRepository, $leechingSeedingStatus, $now) {
            $torrentName = $row->torrent ? $row->torrent->name : '';
            $torrentId = $row->torrent_id;
            if (isset($leechingSeedingStatus[$torrentId])) {
                $torrentName .= (new \Nexus\Torrent\Torrent())->renderProgressBar(
                    $leechingSeedingStatus[$torrentId]['active_status'],
                    $leechingSeedingStatus[$torrentId]['progress']
                );
            }
            return [
                'id' => $row->id,
                'uid' => $row->uid,
                'username' => $row->user->username ?? '',
                'torrent_id' => $torrentId,
                'torrent_name' => $torrentName,
                'torrent_size' => $row->torrent ? mksize($row->torrent->size) : '',
                'torrent_ttl' => $row->torrent ? mkprettytime($row->torrent->added->diffInSeconds($now, true)) : '',
                'created_at' => format_datetime($row->created_at),
                'last_settle_at' => format_datetime($row->last_settle_at),
                'seed_time_this_month' => $row->snatch ? mkprettytime($row->snatch->seedtime - $row->seed_time_begin) : '',
                'uploaded_this_month' => $row->snatch ? mksize($row->snatch->uploaded - $row->uploaded_begin) : '',
                'reached' => $row->is_reached_this_month ? 'Yes' : 'No',
                'action_buttons' => $row->snatch ? $claimRepository->buildActionButtons($row->torrent_id, $row, 1) : '',
            ];
        });

        $data = [
            'request' => $request,
            'rows' => $rows,
            'paginator' => $paginator,
            'sort' => $sort,
            'order' => $order,
            'sortOptions' => $sortAllowed,
            'orderOptions' => $orderAllowed,
            'torrentId' => $torrentId,
            'uid' => $uid,
            'showAction' => $currentUser && $uid > 0 && $uid === $currentUser->id,
        ];

        if ($request->filled('torrent_id')) {
            $data['torrent'] = $torrent;
            $data['pageTitle'] = nexus_trans('claim.title_for_torrent');
        } else {
            $data['user'] = $user;
            $data['pageTitle'] = nexus_trans('claim.title_for_user');
        }

        return view('claim', $data);
    }
}