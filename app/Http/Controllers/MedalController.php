<?php

namespace App\Http\Controllers;

use App\Http\Resources\MedalResource;
use App\Models\Medal;
use App\Repositories\MedalRepository;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class MedalController extends Controller
{
    private $repository;

    public function __construct(MedalRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Medal shop page, mirrors legacy public/medal.php GET.
     */
    public function showPage(Request $request)
    {
        $query = Medal::query()
            ->where('display_on_medal_page', 1)
            ->orderBy('priority', 'desc')
            ->orderBy('id', 'desc');

        $q = htmlspecialchars(trim((string) $request->get('q', '')));
        if ($q !== '') {
            $query->where('name', 'like', "%{$q}%");
        }

        $perPage = 20;
        $pageIndex = max(0, (int) $request->get('page', 0));
        $page = $pageIndex + 1;
        $total = (clone $query)->count();
        $medals = (clone $query)->skip(($page - 1) * $perPage)->take($perPage)->get();

        $paginator = new LengthAwarePaginator(
            $medals,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $user = Auth::user();
        $userMedals = $user->valid_medals->keyBy('id');
        $seedBonus = $user->seedbonus;

        $rows = [];
        foreach ($medals as $medal) {
            $buyDisabled = $giftDisabled = ' disabled';
            $buyClass = $giftClass = '';
            try {
                $medal->checkCanBeBuy();
                if ($userMedals->has($medal->id)) {
                    $buyBtnText = nexus_trans('medal.buy_already');
                } elseif ($seedBonus < $medal->price) {
                    $buyBtnText = nexus_trans('medal.require_more_bonus');
                } else {
                    $buyBtnText = nexus_trans('medal.buy_btn');
                    $buyDisabled = '';
                    $buyClass = 'buy';
                }
                if ($seedBonus < $medal->price * (1 + ($medal->gift_fee_factor ?? 0))) {
                    $giftBtnText = nexus_trans('medal.require_more_bonus');
                } else {
                    $giftBtnText = nexus_trans('medal.gift_btn');
                    $giftDisabled = '';
                    $giftClass = 'gift';
                }
            } catch (\Exception $exception) {
                $buyBtnText = $giftBtnText = $exception->getMessage();
            }
            $rows[] = [
                'medal' => $medal,
                'buy_class' => $buyClass,
                'buy_btn' => $buyBtnText,
                'buy_disabled' => $buyDisabled,
                'gift_class' => $giftClass,
                'gift_btn' => $giftBtnText,
                'gift_disabled' => $giftDisabled,
                'gift_fee_factor' => ($medal->gift_fee_factor ?? 0) * 100,
                'price' => number_format($medal->price),
            ];
        }

        return view('medal', [
            'request' => $request,
            'rows' => $rows,
            'paginator' => $paginator,
            'q' => $q,
            'confirm_buy_msg' => nexus_trans('medal.confirm_to_buy'),
            'confirm_gift_msg' => nexus_trans('medal.confirm_to_gift'),
        ]);
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return array
     */
    public function index(Request $request)
    {
        $result = $this->repository->getList($request->all());
        $resource = MedalResource::collection($result);
        $resource->additional([
            'page_title' => nexus_trans('medal.admin.list.page_title'),
        ]);
        return $this->success($resource);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function store(Request $request)
    {
        $rules = [
            'name' => 'required|string',
            'price' => 'required|integer|min:1',
            'image_large' => 'required|url',
            'image_small' => 'required|url',
            'duration' => 'nullable|integer|min:-1',
        ];
        $request->validate($rules);
        $result = $this->repository->store($request->all());
        $resource = new MedalResource($result);
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
        $resource = new MedalResource($result);
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
        $rules = [
            'name' => 'required|string',
            'price' => 'required|integer|min:1',
            'image_large' => 'required|url',
            'image_small' => 'required|url',
            'duration' => 'nullable|integer|min:-1',
        ];
        $request->validate($rules);
        $result = $this->repository->update($request->all(), $id);
        $resource = new MedalResource($result);
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
        return $this->success($result, 'Delete medal success!');
    }


}
