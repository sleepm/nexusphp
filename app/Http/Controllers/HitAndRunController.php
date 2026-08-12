<?php

namespace App\Http\Controllers;

use App\Http\Resources\HitAndRunResource;
use App\Models\HitAndRun;
use App\Repositories\HitAndRunRepository;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class HitAndRunController extends Controller
{
    private $repository;

    public function __construct(HitAndRunRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * H&R list page, mirrors legacy public/myhr.php GET.
     */
    public function showPage(Request $request)
    {
        $currentUser = Auth::user();
        $userid = $currentUser->id;

        if ($request->filled('userid')) {
            if (!user_can('viewhistory') && (int) $request->get('userid') != $currentUser->id) {
                abort(403, 'Permission denied');
            }
            $userid = (int) $request->get('userid');
        }

        $userInfo = User::query()->find($userid, User::$commonFields);
        if (empty($userInfo)) {
            abort(404, 'User not exists.');
        }

        $status = $request->get('status', HitAndRun::STATUS_INSPECTING);
        if (!isset(HitAndRun::$status[$status])) {
            $status = HitAndRun::STATUS_INSPECTING;
        }

        $allStatus = HitAndRun::listStatus();
        $q = htmlspecialchars(trim((string) $request->get('q', '')));

        $baseQuery = HitAndRun::query()->where('uid', $userid)->where('status', $status);
        if ($q !== '') {
            $baseQuery->where('id', $q);
        }

        $perPage = 50;
        $pageIndex = max(0, (int) $request->get('page', 0));
        $page = $pageIndex + 1;
        $total = (clone $baseQuery)->count();

        $list = (clone $baseQuery)
            ->with([
                'torrent' => function ($query) {
                    $query->select(['id', 'size', 'name', 'category']);
                },
                'torrent.basic_category',
                'snatch',
                'user' => function ($query) {
                    $query->select(['id', 'lang']);
                },
                'user.language',
            ])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->orderBy('id', 'desc')
            ->get();

        $paginator = new LengthAwarePaginator(
            $list,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $rows = [];
        $hasActionRemove = false;
        foreach ($list as $row) {
            $canRemove = $row->uid == $currentUser->id && in_array($row->status, HitAndRun::CAN_PARDON_STATUS);
            if ($canRemove) {
                $hasActionRemove = true;
            }
            $rows[] = [
                'id' => $row->id,
                'torrent_id' => $row->torrent_id,
                'torrent_name' => optional($row->torrent)->name,
                'uploaded' => $row->snatch ? mksize($row->snatch->uploaded) : '',
                'downloaded' => $row->snatch ? mksize($row->snatch->downloaded) : '',
                'share_ratio' => $row->snatch ? get_hr_ratio($row->snatch->uploaded, $row->snatch->downloaded) : '',
                'seed_time_required' => $row->seedTimeRequired,
                'completed_at' => $row->snatch ? format_datetime($row->snatch->completedat) : '',
                'inspect_time_left' => $row->inspectTimeLeft,
                'comment' => nl2br(trim((string) $row->comment)),
                'can_remove' => $canRemove,
            ];
        }

        $lang = get_legacy_lang_file('myhr');

        return view('myhr', [
            'request' => $request,
            'rows' => $rows,
            'paginator' => $paginator,
            'allStatus' => $allStatus,
            'status' => $status,
            'q' => $q,
            'pagerParams' => ['userid' => $userid, 'status' => $status],
            'hasActionRemove' => $hasActionRemove,
            'lang' => $lang,
            'langFunctions' => get_legacy_lang_file('functions'),
            'pageTitle' => $userInfo->username . ' - H&R',
            'removeConfirmMsg' => nexus_trans('hr.remove_confirm_msg', ['bonus' => get_setting('bonus.cancel_hr')]),
        ]);
    }

    private function getRules(): array
    {
        return [
            'family_id' => 'required|numeric',
            'name' => 'required|string',
            'peer_id' => 'required|string',
            'agent' => 'required|string',
            'comment' => 'required|string',

        ];
    }
    /**
     * Display a listing of the resource.
     *
     * @return array
     */
    public function index(Request $request)
    {
        $result = $this->repository->getList($request->all());
        $resource = HitAndRunResource::collection($result);
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
        $request->validate($this->getRules());
        $result = $this->repository->store($request->all());
        $resource = new HitAndRunResource($result);
        return $this->success($resource);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return array
     */
    public function show($id)
    {
        $result = $this->repository->getDetail($id);
        $resource = new HitAndRunResource($result);
        return $this->success($resource);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return array
     */
    public function update(Request $request, $id)
    {
        $request->validate($this->getRules());
        $result = $this->repository->update($request->all(), $id);
        $resource = new HitAndRunResource($result);
        return $this->success($resource);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return array
     */
    public function destroy($id)
    {
        $result = $this->repository->delete($id);
        return $this->success($result);
    }

    public function listStatus(): array
    {
        $result = $this->repository->listStatus();
        return $this->success($result);
    }

    public function pardon($id): array
    {
        $result = $this->repository->pardon($id, Auth::user());
        return $this->success($result);
    }

    public function bulkPardon(Request $request): array
    {
        $result = $this->repository->bulkPardon($request->all(), Auth::user());
        return $this->success(['result' => $result],"Affected: " . intval($result));
    }

    public function bulkDelete(Request $request): array
    {
        $result = $this->repository->bulkDelete($request->all(), Auth::user());
        return $this->success(['result' => $result],"Affected: " . intval($result));
    }
}
