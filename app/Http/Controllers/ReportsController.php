<?php

namespace App\Http\Controllers;

use App\Models\Offer;
use App\Models\Report;
use App\Models\Setting;
use App\Models\Torrent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReportsController extends Controller
{
    public function web(Request $request)
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        if (! $currentUser) {
            abort(401);
        }
        $curUser = $currentUser->toArray();
        if (($curUser['parked'] ?? '') == 'yes') {
            abort(403, 'Your account is parked.');
        }
        if (! user_can('staffmem', false, $currentUser->id)) {
            abort(403, 'Access denied.');
        }

        $lang = get_legacy_lang_file('reports');

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_reports'] = $lang;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();

        // POST: bulk actions (mirrors legacy public/takeupdate.php)
        if ($request->isMethod('POST')) {
            return $this->bulkAction($request, $curUser, $lang);
        }

        $count = Report::query()->count();
        if (! $count) {
            return $this->messagePage($lang['std_oho'], $lang['std_no_report'], $lang['head_reports']);
        }

        $perpage = 10;
        $page = max(0, (int) $request->query('page', 0));
        $reports = Report::query()
            ->orderBy('dealtwith')
            ->orderByDesc('id')
            ->offset($page * $perpage)
            ->limit($perpage)
            ->get();

        $rows = $reports->map(function (Report $row) use ($lang) {
            return [
                'id' => $row->id,
                'added' => $row->added,
                'addedby' => $row->addedby,
                'type' => $row->type,
                'reason' => $row->reason,
                'dealtwith' => $row->dealtwith,
                'dealtby' => $row->dealtby,
                'reporting' => $this->reportingCell($row, $lang),
                'typeLabel' => $this->typeLabel($row->type, $lang),
                'dealtwithLabel' => $row->dealtwith
                    ? '<font color=green>' . $lang['text_yes'] . '</font> - ' . get_username($row->dealtby)
                    : '<font color=red>' . $lang['text_no'] . '</font>',
            ];
        });

        return view('reports', [
            'pageTitle' => $lang['head_reports'],
            'lang' => $lang,
            'rows' => $rows,
            'count' => $count,
            'perpage' => $perpage,
            'page' => $page,
        ]);
    }

    private function bulkAction(Request $request, array $curUser, array $lang)
    {
        $ids = array_filter(array_map('intval', (array) $request->input('delreport', [])));
        if ($ids === []) {
            return $this->messagePage('Error', $GLOBALS['lang_functions']['select_at_least_one_record'] ?? 'Select at least one record.', 'Error');
        }

        if ($request->input('setdealt')) {
            $reportIds = Report::query()
                ->where('dealtwith', 0)
                ->whereIn('id', $ids)
                ->pluck('id');
            foreach ($reportIds as $reportId) {
                Report::query()->where('id', $reportId)->update([
                    'dealtwith' => 1,
                    'dealtby' => $curUser['id'],
                ]);
            }
            Cache::delete('staff_new_report_count');
        } elseif ($request->input('delete')) {
            Report::query()->whereIn('id', $ids)->delete();
            Cache::delete('staff_new_report_count');
            Cache::delete('staff_report_count');
        }

        return redirect('reports.php');
    }

    private function reportingCell(Report $row, array $lang): string
    {
        switch ($row->type) {
            case 'torrent':
                $torrent = Torrent::query()->find($row->reportid, ['id', 'name']);
                return $torrent
                    ? '<a href="details.php?id=' . $torrent->id . '">' . htmlspecialchars($torrent->name) . '</a>'
                    : $lang['text_torrent_does_not_exist'];

            case 'user':
                $user = User::query()->find($row->reportid, ['id']);
                return $user
                    ? get_username($user->id)
                    : $lang['text_user_does_not_exist'];

            case 'offer':
                $offer = Offer::query()->find($row->reportid, ['id', 'name']);
                return $offer
                    ? '<a href="offers.php?id=' . $offer->id . '&off_details=1">' . htmlspecialchars($offer->name) . '</a>'
                    : $lang['text_offer_does_not_exist'];

            case 'post':
                $post = DB::table('topics')
                    ->leftJoin('posts', 'posts.topicid', '=', 'topics.id')
                    ->where('posts.id', $row->reportid)
                    ->first(['topics.id as topicid', 'topics.subject as subject', 'posts.userid as postuserid']);
                if (! $post) {
                    return $lang['text_post_does_not_exist'];
                }
                return $lang['text_post_id'] . $row->reportid . $lang['text_of_topic']
                    . '<b><a href="forums.php?action=viewtopic&topicid=' . $post->topicid . '&page=p' . $row->reportid . '#pid' . $row->reportid . '">' . htmlspecialchars($post->subject) . '</a></b>'
                    . $lang['text_by'] . get_username($post->postuserid);

            case 'comment':
                $comment = DB::table('comments')->where('id', $row->reportid)->first(['id', 'user', 'torrent', 'offer']);
                if (! $comment) {
                    return $lang['text_comment_does_not_exist'];
                }
                if ($comment->torrent) {
                    $name = DB::table('torrents')->where('id', $comment->torrent)->value('name');
                    $url = 'details.php?id=' . $comment->torrent . '#cid' . $row->reportid;
                    $of = $lang['text_of_torrent'];
                } elseif ($comment->offer) {
                    $name = DB::table('offers')->where('id', $comment->offer)->value('name');
                    $url = 'offers.php?id=' . $comment->offer . '&off_details=1#cid' . $row->reportid;
                    $of = $lang['text_of_offer'];
                } else {
                    $name = '';
                    $url = '';
                    $of = 'unknown';
                }
                return $lang['text_comment_id'] . $row->reportid . $of . '<b><a href="' . $url . '">' . htmlspecialchars($name) . '</a></b>'
                    . $lang['text_by'] . get_username($comment->user);

            case 'subtitle':
                $sub = DB::table('subs')->where('id', $row->reportid)->first(['id', 'torrent_id', 'title']);
                if (! $sub) {
                    return $lang['text_subtitle_does_not_exist'];
                }
                return '<a href="downloadsubs.php?torrentid=' . $sub->torrent_id . '&subid=' . $sub->id . '">' . htmlspecialchars($sub->title) . '</a>'
                    . $lang['text_for_torrent_id'] . '<a href="details.php?id=' . $sub->torrent_id . '">' . $sub->torrent_id . '</a>';

            default:
                return '';
        }
    }

    private function typeLabel(string $type, array $lang): string
    {
        switch ($type) {
            case 'torrent':
                return $lang['text_torrent'];
            case 'user':
                return $lang['text_user'];
            case 'offer':
                return $lang['text_offer'];
            case 'post':
                return $lang['text_forum_post'];
            case 'comment':
                return $lang['text_comment'];
            case 'subtitle':
                return $lang['text_subtitle'];
            default:
                return $type;
        }
    }

    private function messagePage(string $heading, string $text, string $pageTitle = 'Error', bool $htmlstrip = true)
    {
        $content = $this->capture(function () use ($heading, $text, $htmlstrip) {
            stderr($heading, $text, $htmlstrip, false, false, false);
        });

        return view('reports', compact('content') + ['pageTitle' => $pageTitle]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}