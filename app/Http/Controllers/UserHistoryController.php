<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * User forum-post / comment history, replaces legacy public/userhistory.php (Phase 2 P2).
 */
class UserHistoryController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request)
    {
        $currentUser = Auth::user();
        if ($currentUser && $currentUser->parked == 'yes') {
            abort(403, 'Your account is parked.');
        }
        $userid = (int) $request->get('id', 0);
        if ($userid <= 0) {
            abort(404, 'Invalid ID.');
        }
        if ($currentUser->id != $userid && !user_can('viewhistory')) {
            abort(403, 'Permission denied.');
        }

        $action = (string) $request->get('action', '');
        $lang = get_legacy_lang_file('userhistory');

        switch ($action) {
            case 'viewposts':
                return $this->viewPosts($request, $currentUser, $userid, $lang);
            case 'viewcomments':
                return $this->viewComments($request, $currentUser, $userid, $lang);
            case '':
                abort(400, $lang['std_invalid_or_no_query'] ?? 'Invalid or no query.');
            default:
                abort(400, $lang['std_unkown_action'] ?? 'Unknown action.');
        }
    }

    private function viewPosts(Request $request, User $currentUser, int $userid, array $lang)
    {
        $user = User::query()->find($userid, ['id', 'username']);
        $subject = $user ? get_username($userid) : "unknown[$userid]";

        $countQuery = DB::table('posts as p')
            ->join('topics as t', 'p.topicid', '=', 't.id')
            ->join('forums as f', 't.forumid', '=', 'f.id')
            ->where('p.userid', $userid)
            ->where('f.minclassread', '<=', $currentUser->class);
        $postCount = $countQuery->count();

        $pageIndex = max(0, (int) $request->get('page', 0));
        $page = $pageIndex + 1;

        $posts = (clone $countQuery)
            ->selectRaw('f.id AS f_id, f.name, t.id AS t_id, t.subject, t.lastpost, r.lastpostread, p.*')
            ->leftJoin('readposts as r', function ($join) use ($userid) {
                $join->on('p.topicid', '=', 'r.topicid')
                    ->on('p.userid', '=', 'r.userid');
            })
            ->orderByRaw('p.id DESC')
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE)
            ->get();

        $rows = [];
        foreach ($posts as $post) {
            $rows[] = [
                'id' => $post->id,
                'added' => gettime($post->added, true, false, false),
                'forum_id' => $post->f_id,
                'forum_name' => $post->name,
                'topic_id' => $post->t_id,
                'topic_name' => $post->subject,
                'new' => ($post->lastpostread < $post->lastpost) && $currentUser->id == $userid,
                'body' => $this->renderPostBody($post, $lang),
            ];
        }

        $paginator = new LengthAwarePaginator(
            $posts,
            $postCount,
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('userhistory', [
            'request' => $request,
            'lang' => $lang,
            'langFunctions' => get_legacy_lang_file('functions'),
            'action' => 'viewposts',
            'subject' => $subject,
            'rows' => $rows,
            'paginator' => $paginator,
            'pageTitle' => $lang['head_posts_history'],
            'heading' => $lang['text_posts_history_for'] . $subject,
        ]);
    }

    private function renderPostBody($post, array $lang): string
    {
        $body = format_comment($post->body);
        if ($post->editedby && is_valid_id($post->editedby)) {
            $editor = DB::table('users')->where('id', $post->editedby)->value('username');
            if ($editor) {
                $body .= '<p><font size="1" class="small">'
                    . ($lang['text_last_edited'] ?? '')
                    . get_username($post->editedby)
                    . ($lang['text_at'] ?? '')
                    . $post->editdate
                    . '</font></p>';
            }
        }

        return $body;
    }

    private function viewComments(Request $request, User $currentUser, int $userid, array $lang)
    {
        $user = User::query()->find($userid, ['id', 'username']);
        $subject = $user ? get_username($userid) : "unknown[$userid]";

        $commentQuery = DB::table('comments as c')
            ->leftJoin('torrents as t', 'c.torrent', '=', 't.id')
            ->where('c.user', $userid);
        $commentCount = (clone $commentQuery)->count();

        $pageIndex = max(0, (int) $request->get('page', 0));
        $page = $pageIndex + 1;

        $comments = (clone $commentQuery)
            ->selectRaw('t.name, c.torrent AS t_id, c.id, c.added, c.text')
            ->orderByRaw('c.id DESC')
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE)
            ->get();

        $rows = [];
        foreach ($comments as $comment) {
            $torrentName = $comment->name;
            if ($torrentName !== null && strlen($torrentName) > 55) {
                $torrentName = substr($torrentName, 0, 52) . '...';
            }
            $countBefore = DB::table('comments')
                ->where('torrent', $comment->t_id)
                ->where('id', '<', $comment->id)
                ->count();
            $commentPage = floor($countBefore / 20);

            $rows[] = [
                'id' => $comment->id,
                'added' => gettime($comment->added, true, false, false),
                'torrent_id' => $comment->t_id,
                'torrent_name' => $torrentName,
                'comment_page' => $commentPage,
                'body' => format_comment($comment->text),
            ];
        }

        $paginator = new LengthAwarePaginator(
            $comments,
            $commentCount,
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('userhistory', [
            'request' => $request,
            'lang' => $lang,
            'langFunctions' => get_legacy_lang_file('functions'),
            'action' => 'viewcomments',
            'subject' => $subject,
            'rows' => $rows,
            'paginator' => $paginator,
            'pageTitle' => $lang['head_comments_history'],
            'heading' => $lang['text_comments_history_for'] . $subject,
        ]);
    }
}