<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class TaskController extends Controller
{
    /**
     * Task list page. Mirrors legacy public/task.php: a paginated table of
     * claimable tasks (Exam::TYPE_TASK) with a "Claim" button per row, plus
     * the current user's already-claimed tasks shown as disabled.
     *
     * GET /task.php requires login (auth.nexus).
     */
    public function web(Request $request)
    {
        $currentUser = Auth::guard('nexus')->user();
        if (! $currentUser) {
            abort(401);
        }

        $query = Exam::query()
            ->where('type', Exam::TYPE_TASK)
            ->where('status', Exam::STATUS_ENABLED);

        $perPage = 20;
        $total = (clone $query)->count();
        $page = max(1, (int) $request->query('page', 0) + 1);
        $rows = (clone $query)
            ->orderBy('id', 'desc')
            ->withCount('onGoingUsers')
            ->offset(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        $paginator = new LengthAwarePaginator(
            $rows,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $userInfo = User::query()->findOrFail($currentUser->id, User::$commonFields);
        $userTasks = $userInfo->onGoingExamAndTasks()
            ->where('type', Exam::TYPE_TASK)
            ->orderBy('id', 'desc')
            ->get()
            ->keyBy('id');

        return view('task', [
            'request' => $request,
            'rows' => $rows,
            'paginator' => $paginator,
            'userTasks' => $userTasks,
            'pageTitle' => nexus_trans('exam.type_task'),
            'confirm_claim_msg' => nexus_trans('exam.confirm_to_claim'),
        ]);
    }
}
