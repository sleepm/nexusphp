<?php

namespace App\Http\Controllers;

use App\Models\Offer;
use App\Models\Report;
use App\Models\Setting;
use App\Models\Torrent;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
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

        $lang = get_legacy_lang_file('report');

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_report'] = $lang;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();

        $uid = (int) $curUser['id'];

        // POST: submit a report
        if ($request->isMethod('POST')) {
            $reason = trim((string) $request->input('reason', ''));
            if ($reason === '') {
                return $this->messagePage($lang['std_error'], $lang['std_missing_reason'], $lang['std_error']);
            }

            $typeMap = [
                'takereportofferid' => 'offer',
                'takerequestid' => 'request',
                'takeuser' => 'user',
                'taketorrent' => 'torrent',
                'takeforumpost' => 'post',
                'takecommentid' => 'comment',
                'takesubtitleid' => 'subtitle',
            ];

            $reportid = 0;
            $type = '';
            foreach ($typeMap as $field => $t) {
                $val = $request->input($field, 0);
                if ($val) {
                    $reportid = (int) $val;
                    $type = $t;
                    break;
                }
            }

            if (! $reportid || ! $type) {
                return $this->messagePage($lang['std_error'], $lang['std_invalid_action'], $lang['std_error']);
            }

            return $this->takereport($uid, $reportid, $type, $reason, $lang);
        }

        // GET: show confirmation form
        $params = [
            'reportofferid' => (int) $request->query('reportofferid', 0),
            'reportrequestid' => (int) $request->query('reportrequestid', 0),
            'user' => (int) $request->query('user', 0),
            'commentid' => (int) $request->query('commentid', 0),
            'torrent' => (int) $request->query('torrent', 0),
            'forumpost' => (int) $request->query('forumpost', 0),
            'subtitle' => (int) $request->query('subtitle', 0),
        ];

        // Determine which type is being reported
        $type = '';
        $id = 0;
        foreach ($params as $key => $val) {
            if ($val > 0) {
                $type = $key;
                $id = $val;
                break;
            }
        }

        if (! $type || ! $id) {
            return $this->messagePage($lang['std_error'], $lang['std_invalid_action'], $lang['std_error']);
        }

        return $this->buildConfirm($type, $id, $uid, $lang);
    }

    private function takereport(int $uid, int $reportid, string $type, string $reason, array $lang)
    {
        $exists = Report::query()
            ->where('addedby', $uid)
            ->where('reportid', $reportid)
            ->where('type', $type)
            ->exists();

        if ($exists) {
            return $this->messagePage($lang['std_error'], $lang['std_already_reported_this'], $lang['std_error']);
        }

        Report::query()->create([
            'addedby' => $uid,
            'reportid' => $reportid,
            'type' => $type,
            'reason' => trim($reason),
            'added' => now(),
        ]);

        Cache::delete('staff_report_count');
        Cache::delete('staff_new_report_count');

        return $this->messagePage($lang['std_message'], $lang['std_successfully_reported'], $lang['std_message']);
    }

    private function buildConfirm(string $type, int $id, int $uid, array $lang)
    {
        switch ($type) {
            case 'user':
                if ($id === $uid) {
                    return $this->messagePage($lang['std_sorry'], $lang['std_cannot_report_oneself'], $lang['std_error']);
                }
                $user = User::query()->find($id);
                if (! $user) {
                    return $this->messagePage($lang['std_error'], $lang['std_invalid_user_id'], $lang['std_error']);
                }
                if ((int) $user->class >= User::CLASS_MODERATOR) {
                    return $this->messagePage($lang['std_sorry'], $lang['std_cannot_report'] . get_user_class_name($user->class, false, true, true), $lang['std_error']);
                }
                return $this->confirmView($lang, $lang['text_are_you_sure_user'] . get_username(htmlspecialchars((string) $id)) . $lang['text_to_staff'] . '<br />' . $lang['text_not_for_leechers'] . '<br />' . $lang['text_reason_note'], 'takeuser', $id);

            case 'torrent':
                $torrent = Torrent::query()->find($id, ['id', 'name']);
                if (! $torrent) {
                    return $this->messagePage($lang['std_error'], $lang['std_invalid_torrent_id'], $lang['std_error']);
                }
                return $this->confirmView($lang, $lang['text_are_you_sure_torrent'] . '<a href="details.php?id=' . $id . '"><b>' . htmlspecialchars($torrent->name) . '</b></a>' . $lang['text_to_staff'] . '<br />' . $lang['text_reason_note'], 'taketorrent', $id);

            case 'forumpost':
                $post = DB::table('topics')
                    ->leftJoin('posts', 'posts.topicid', '=', 'topics.id')
                    ->where('posts.id', $id)
                    ->first(['topics.id as topicid', 'topics.subject as subject', 'posts.userid as postuserid']);
                if (! $post) {
                    return $this->messagePage($lang['std_error'], $lang['std_invalid_post_id'], $lang['std_error']);
                }
                return $this->confirmView($lang, $lang['text_are_you_sure_post'] . $id . $lang['text_of_topic'] . '<a href="forums.php?action=viewtopic&topicid=' . $post->topicid . '&page=p' . $id . '#' . $id . '"><b>' . htmlspecialchars($post->subject) . '</b></a>' . $lang['text_by'] . get_username($post->postuserid) . $lang['text_to_staff'] . '<br />' . $lang['text_reason_note'], 'takeforumpost', $id);

            case 'commentid':
                $comment = \App\Models\Comment::query()->find($id);
                if (! $comment) {
                    return $this->messagePage($lang['std_error'], $lang['std_invalid_comment_id'], $lang['std_error']);
                }
                if ($comment->torrent) {
                    $name = Torrent::query()->where('id', $comment->torrent)->value('name');
                    $url = 'details.php?id=' . $comment->torrent . '#' . $id;
                    $of = $lang['text_of_torrent'];
                } elseif ($comment->offer) {
                    $name = Offer::query()->where('id', $comment->offer)->value('name');
                    $url = 'offers.php?id=' . $comment->offer . '&off_details=1#' . $id;
                    $of = $lang['text_of_offer'];
                } else {
                    return $this->messagePage($lang['std_error'], $lang['std_orphaned_comment'], $lang['std_error']);
                }
                return $this->confirmView($lang, $lang['text_are_you_sure_comment'] . $id . $of . '<a href="' . $url . '"><b>' . htmlspecialchars($name) . '</b></a>' . $lang['text_by'] . get_username($comment->user) . $lang['text_to_staff'] . '<br />' . $lang['text_reason_note'], 'takecommentid', $id);

            case 'reportofferid':
                $offer = Offer::query()->find($id, ['id', 'name']);
                if (! $offer) {
                    return $this->messagePage($lang['std_error'], $lang['std_invalid_offer_id'], $lang['std_error']);
                }
                return $this->confirmView($lang, $lang['text_are_you_sure_offer'] . '<a href="offers.php?id=' . $id . '&off_details=1"><b>' . htmlspecialchars($offer->name) . '</b></a>' . $lang['text_to_staff'] . '<br />' . $lang['text_reason_note'], 'takereportofferid', $id);

            case 'reportrequestid':
                $requestModel = \App\Models\Request::query()->find($id, ['id', 'request']);
                if (! $requestModel) {
                    return $this->messagePage($lang['std_error'], $lang['std_invalid_request_id'], $lang['std_error']);
                }
                return $this->confirmView($lang, $lang['text_are_you_sure_request'] . '<a href="viewrequests.php?id=' . $id . '&req_details=1"><b>' . htmlspecialchars($requestModel->request) . '</b></a>' . $lang['text_to_staff'] . '<br />' . $lang['text_reason_note'], 'takerequestid', $id);

            case 'subtitle':
                $sub = DB::table('subs')->where('id', $id)->first(['id', 'torrent_id', 'title']);
                if (! $sub) {
                    return $this->messagePage($lang['std_error'], $lang['std_invalid_subtitle_id'], $lang['std_error']);
                }
                return $this->confirmView($lang, $lang['text_are_you_sure_subtitle'] . '<a href="downloadsubs.php?torrentid=' . $sub->torrent_id . '&subid=' . $sub->id . '"><b>' . htmlspecialchars($sub->title) . '</b></a>' . $lang['text_to_staff'] . '<br />' . $lang['text_reason_note'], 'takesubtitleid', $id);

            default:
                return $this->messagePage($lang['std_error'], $lang['std_invalid_action'], $lang['std_error']);
        }
    }

    private function confirmView(array $lang, string $text, string $field, int $value): View
    {
        return view('report', [
            'pageTitle' => $lang['std_are_you_sure'],
            'lang' => $lang,
            'heading' => $lang['std_are_you_sure'],
            'text' => $text,
            'field' => $field,
            'value' => $value,
        ]);
    }

    private function messagePage(string $heading, string $text, string $pageTitle = 'Error', bool $htmlstrip = true)
    {
        $content = $this->capture(function () use ($heading, $text, $htmlstrip) {
            stderr($heading, $text, $htmlstrip, false, false, false);
        });

        return view('report', compact('content') + ['pageTitle' => $pageTitle]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}