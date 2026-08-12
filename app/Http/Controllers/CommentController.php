<?php

namespace App\Http\Controllers;

use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\NexusModel;
use App\Models\User;
use App\Repositories\CommentRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CommentController extends Controller
{
    private $repository;

    public function __construct(CommentRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Web entry point, mirrors legacy public/comment.php action dispatch.
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Http\RedirectResponse
     */
    public function web(Request $request)
    {
        $langComment = get_legacy_lang_file('comment');
        $action = (string) $request->input('action', '');
        $type = (string) $request->input('type', '');
        if (!in_array($type, array_keys(Comment::TYPE_MAPS), true)) {
            abort(400, $langComment['std_error'] ?? 'Error');
        }
        switch ($action) {
            case 'add':
                if ($request->isMethod('POST')) {
                    return $this->webAdd($request, $langComment);
                }
                return $this->webAddForm($request, $langComment);
            case 'edit':
                if ($request->isMethod('POST')) {
                    return $this->webEdit($request, $langComment);
                }
                return $this->webEditForm($request, $langComment);
            case 'delete':
                return $this->webDelete($request, $langComment);
            case 'vieworiginal':
                return $this->webViewOriginal($request, $langComment);
            default:
                abort(400, $langComment['std_unknown_action'] ?? 'Unknown action.');
        }
    }

    private function webAddForm(Request $request, array $langComment)
    {
        /** @var User $user */
        $user = Auth::user();
        $this->checkParked($user, $langComment);
        $type = (string) $request->input('type');
        $parentId = (int) $request->input('pid', 0);
        if ($parentId <= 0) {
            abort(400, $langComment['std_no_torrent_id'] ?? 'No torrent with this ID.');
        }
        $target = $this->findCommentTarget($type, $parentId, $langComment);

        $quoteContent = '';
        $quoteUser = '';
        if ($request->input('sub') == 'quote') {
            $commentId = (int) $request->input('cid', 0);
            if ($commentId > 0) {
                $quoteComment = Comment::query()->with('create_user')->find($commentId);
                if ($quoteComment) {
                    $quoteUser = $quoteComment->create_user->username ?? '';
                    $quoteContent = $quoteComment->text;
                }
            }
        }
        return $this->renderCommentView($request, $langComment, 'add', [
            'user' => $user,
            'type' => $type,
            'target' => $target,
            'targetName' => $target->{Comment::TYPE_MAPS[$type]['target_name_field']},
            'targetUrl' => $this->commentTargetScript($type, $parentId),
            'quoteUser' => $quoteUser,
            'quoteContent' => $quoteContent,
        ]);
    }

    private function webAdd(Request $request, array $langComment)
    {
        /** @var User $user */
        $user = Auth::user();
        $this->checkParked($user, $langComment);
        if (!user_can('commanage')) {
            $lastComment = $user->last_comment;
            if (!empty($lastComment) && strtotime($lastComment) > (TIMENOW - 10)) {
                $secs = 10 - (TIMENOW - strtotime($lastComment));
                return back()->with('error', ($langComment['std_comment_flooding_denied'] ?? '') . $secs . ($langComment['std_before_posting_another'] ?? ''));
            }
        }
        $type = (string) $request->input('type');
        $parentId = (int) $request->input('pid', 0);
        if ($parentId <= 0) {
            return back()->with('error', $langComment['std_no_torrent_id'] ?? 'No torrent with this ID.');
        }
        $text = trim((string) $request->input('body', ''));
        if ($text === '') {
            return back()->with('error', $langComment['std_comment_body_empty'] ?? 'Comment body cannot be empty!');
        }
        try {
            $comment = $this->repository->store([
                'type' => $type,
                $type => $parentId,
                'text' => $text,
            ], $user);
        } catch (\Throwable $exception) {
            do_log(sprintf("webAdd comment fail: %s", $exception->getMessage()), 'error');
            return back()->with('error', $exception->getMessage());
        }
        $this->clearLastCommentCache($type, $parentId);
        return redirect($this->commentTargetScript($type, $parentId) . '#' . $comment->id);
    }

    private function webEditForm(Request $request, array $langComment)
    {
        /** @var User $user */
        $user = Auth::user();
        $this->checkParked($user, $langComment);
        $type = (string) $request->input('type');
        $comment = $this->findCommentForWeb($request, $langComment);
        if ($comment->user != $user->id && !user_can('commanage')) {
            abort(403, $langComment['std_permission_denied'] ?? 'Permission denied');
        }
        $parentId = $this->commentParentId($comment);
        $target = $this->findCommentTarget($type, $parentId, $langComment);
        return $this->renderCommentView($request, $langComment, 'edit', [
            'user' => $user,
            'type' => $type,
            'target' => $target,
            'targetName' => $target->{Comment::TYPE_MAPS[$type]['target_name_field']},
            'targetUrl' => $this->commentTargetScript($type, $parentId),
            'comment' => $comment,
        ]);
    }

    private function webEdit(Request $request, array $langComment)
    {
        /** @var User $user */
        $user = Auth::user();
        $this->checkParked($user, $langComment);
        $type = (string) $request->input('type');
        $comment = $this->findCommentForWeb($request, $langComment);
        if ($comment->user != $user->id && !user_can('commanage')) {
            return back()->with('error', $langComment['std_permission_denied'] ?? 'Permission denied');
        }
        $text = trim((string) $request->input('body', ''));
        if ($text === '') {
            return back()->with('error', $langComment['std_comment_body_empty'] ?? 'Comment body cannot be empty!');
        }
        $parentId = $this->commentParentId($comment);
        $comment->text = $text;
        $comment->editdate = now();
        $comment->editedby = $user->id;
        $comment->save();
        $this->clearLastCommentCache($type, $parentId);
        return redirect($this->safeRedirectUrl($request, $this->commentTargetScript($type, $parentId)));
    }

    private function webDelete(Request $request, array $langComment)
    {
        if (!user_can('commanage')) {
            abort(403, $langComment['std_permission_denied'] ?? 'Permission denied');
        }
        $type = (string) $request->input('type');
        $commentId = (int) $request->input('cid', 0);
        if ($commentId <= 0) {
            abort(404, $langComment['std_invalid_id'] ?? 'Invalid ID.');
        }
        $comment = Comment::query()->find($commentId);
        if (!$comment) {
            abort(404, $langComment['std_invalid_id'] ?? 'Invalid ID.');
        }
        $parentId = $this->commentParentId($comment);
        if (!$request->input('sure')) {
            $returnto = (string) $request->input('returnto', $this->currentReferer($request));
            $confirmUrl = '/comment.php?action=delete&cid=' . $commentId . '&sure=1&type=' . $type
                . ($returnto !== '' ? '&returnto=' . rawurlencode($returnto) : '');
            return $this->renderCommentView($request, $langComment, 'delete', [
                'user' => Auth::user(),
                'type' => $type,
                'target' => null,
                'targetUrl' => $this->commentTargetScript($type, $parentId),
                'comment' => $comment,
                'confirmUrl' => $confirmUrl,
            ]);
        }
        DB::transaction(function () use ($comment) {
            $comment->delete();
            $this->decrementTargetCommentCount($comment);
        });
        $this->clearLastCommentCache($type, $parentId);
        if ($user = Auth::user()) {
            $this->adjustBonusForCommenter($user, $comment, -1);
        }
        return redirect($this->safeRedirectUrl($request, $this->commentTargetScript($type, $parentId)));
    }

    private function webViewOriginal(Request $request, array $langComment)
    {
        if (!user_can('commanage')) {
            abort(403, $langComment['std_permission_denied'] ?? 'Permission denied');
        }
        $type = (string) $request->input('type');
        $commentId = (int) $request->input('cid', 0);
        if ($commentId <= 0) {
            abort(404, $langComment['std_invalid_id'] ?? 'Invalid ID.');
        }
        $comment = Comment::query()->with('create_user')->find($commentId);
        if (!$comment) {
            abort(404, $langComment['std_invalid_id'] ?? 'Invalid ID.');
        }
        $parentId = $this->commentParentId($comment);
        $target = $this->findCommentTarget($type, $parentId, $langComment);
        return $this->renderCommentView($request, $langComment, 'vieworiginal', [
            'user' => Auth::user(),
            'type' => $type,
            'target' => $target,
            'targetName' => $target->{Comment::TYPE_MAPS[$type]['target_name_field']},
            'targetUrl' => $this->commentTargetScript($type, $parentId),
            'comment' => $comment,
        ]);
    }

    private function renderCommentView(Request $request, array $langComment, string $mode, array $data)
    {
        $GLOBALS['CURUSER'] = $data['user']->toArray();
        $data['request'] = $request;
        $data['lang'] = $langComment;
        $data['mode'] = $mode;
        return view('comment', $data);
    }

    private function checkParked($user, array $langComment)
    {
        if ($user && $user->parked == 'yes') {
            $langFunctions = get_legacy_lang_file('functions');
            abort(403, $langFunctions['std_your_account_parked'] ?? 'Your account is parked.');
        }
    }

    /**
     * Find the parent (torrent/offer/request) a comment belongs to.
     *
     * @return NexusModel|null
     */
    private function findCommentTarget(string $type, int $id, array $langComment)
    {
        $modelName = Comment::TYPE_MAPS[$type]['model'];
        $target = (new $modelName)->newQuery()->find($id);
        if (!$target) {
            abort(404, $langComment['std_no_torrent_id'] ?? 'No torrent with this ID.');
        }
        return $target;
    }

    private function findCommentForWeb(Request $request, array $langComment)
    {
        $commentId = (int) $request->input('cid', 0);
        if ($commentId <= 0) {
            abort(404, $langComment['std_invalid_id'] ?? 'Invalid ID.');
        }
        $comment = Comment::query()->find($commentId);
        if (!$comment) {
            abort(404, $langComment['std_invalid_id'] ?? 'Invalid ID.');
        }
        return $comment;
    }

    private function commentParentId(Comment $comment): int
    {
        foreach (['torrent', 'offer', 'request'] as $field) {
            if (!empty($comment->{$field})) {
                return (int) $comment->{$field};
            }
        }
        return 0;
    }

    private function commentTargetScript(string $type, int $parentId): string
    {
        return sprintf(Comment::TYPE_MAPS[$type]['target_script'], $parentId);
    }

    private function clearLastCommentCache(string $type, int $parentId)
    {
        $cacheKey = $type == 'torrent'
            ? "torrent_{$parentId}_last_comment_content"
            : ($type == 'offer' ? "offer_{$parentId}_last_comment_content" : '');
        if ($cacheKey !== '' && !empty($GLOBALS['Cache'])) {
            $GLOBALS['Cache']->delete_value($cacheKey);
        }
    }

    private function decrementTargetCommentCount(Comment $comment)
    {
        foreach (Comment::TYPE_MAPS as $typeMap) {
            $field = $typeMap['foreign_key'];
            if (!empty($comment->{$field})) {
                $table = (new $typeMap['model'])->getTable();
                // the comments column is unsigned; never let it go below 0
                DB::table($table)
                    ->where('id', $comment->{$field})
                    ->where('comments', '>', 0)
                    ->decrement('comments');
                break;
            }
        }
    }

    private function adjustBonusForCommenter(User $user, Comment $comment, int $delta)
    {
        if ($user->id != $comment->user) {
            return;
        }
        $bonus = (int) \App\Models\Setting::get('bonus.addcomment', 0);
        if ($bonus <= 0) {
            return;
        }
        \App\Models\User::query()
            ->where('id', $user->id)
            ->where('seedbonus', $user->seedbonus)
            ->update(['seedbonus' => \Nexus\Database\NexusDB::raw(sprintf('seedbonus %s %d', $delta > 0 ? '+' : '-', abs($bonus)))]);
    }

    private function safeRedirectUrl(Request $request, string $fallback): string
    {
        $returnto = trim((string) $request->input('returnto', ''));
        if ($returnto === '') {
            $returnto = $this->currentReferer($request);
        }
        return $this->isLocalUrl($returnto) ? $returnto : $fallback;
    }

    private function currentReferer(Request $request): string
    {
        $referer = (string) $request->headers->get('referer', '');
        return $this->isLocalUrl($referer) ? $referer : '';
    }

    private function isLocalUrl(string $url): bool
    {
        if ($url === '' || $url[0] === '#' || str_starts_with($url, '//')) {
            return false;
        }
        if (!$this->startsWith($url, 'http://') && !$this->startsWith($url, 'https://')) {
            return true;
        }
        $parsed = parse_url($url);
        if (empty($parsed['host'])) {
            return false;
        }
        return strtolower($parsed['host']) === strtolower((string) config('app.url_host', request()->getHost()));
    }

    private function startsWith(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
    /**
     * Display a listing of the resource.
     *
     * @return array
     */
    public function index(Request $request)
    {
        $comments = $this->repository->getList($request, Auth::user());
        $resource = CommentResource::collection($comments);
        return $this->success($resource);
    }

    private function prepareData(Request $request)
    {
        $allTypes = array_keys(Comment::TYPE_MAPS);
        $request->validate([
            'type' => ['required', Rule::in($allTypes)],
            'torrent_id' => 'nullable|integer',
            'text' => 'required',
            'offer_id' => 'nullable|integer',
            'request_id' => 'nullable|integer',
            'anonymous' => 'nullable',
        ]);
        $data = [
            'type' => $request->type,
            'torrent' => $request->torrent_id,
            'text' => $request->text,
            'ori_text' => $request->text,
            'offer' => $request->offer_id,
            'request' => $request->request_id,
            'anonymous' => $request->anonymous,
        ];
        $data =  array_filter($data);
        foreach ($allTypes as $type) {
            if ($data['type'] == $type && empty($data[$type])) {
                throw new \InvalidArgumentException("require {$type}_id");
            }
        }
        return $data;
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        $comment = $this->repository->store($this->prepareData($request), $user);
        $resource = new CommentResource($comment);
        return $this->success($resource);
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
