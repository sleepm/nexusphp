<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UploadersController extends Controller
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
        if (get_user_class() < User::CLASS_UPLOADER) {
            abort(403, 'Permission denied.');
        }

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $lang = get_legacy_lang_file('uploaders');

        $year = (int) $request->query('year', 0);
        if (! $year || $year < 2000) {
            $year = (int) date('Y');
        }
        $month = (int) $request->query('month', 0);
        if (! $month || $month < 1 || $month > 12) {
            $month = (int) date('m');
        }
        $order = (string) $request->query('order', '');
        if (! in_array($order, ['username', 'torrent_size', 'torrent_count'])) {
            $order = 'username';
        }
        $orderClause = $order === 'username' ? 'username ASC' : $order . ' DESC';

        $datefounded = get_setting('tweak.datefounded', '2007');
        $year2 = substr((string) $datefounded, 0, 4);
        $yearfounded = $year2 ? (int) $year2 : 2007;
        $yearnow = (int) date('Y');

        $timestart = strtotime($year . '-' . $month . '-01 00:00:00');
        $sqlstarttime = date('Y-m-d H:i:s', $timestart);
        $timeend = strtotime('+1 month', $timestart);
        $sqlendtime = date('Y-m-d H:i:s', $timeend);

        $title = $lang['text_uploaders'] . ' - ' . date('Y-m', $timestart);

        $content = $this->capture(function () use ($lang, $year, $month, $order, $yearfounded, $yearnow, $timestart, $sqlstarttime, $sqlendtime, $orderClause, $title) {
            $yearselection = '<select name="year">';
            for ($i = $yearfounded; $i <= $yearnow; $i++) {
                $selected = $i == $year ? ' selected="selected"' : '';
                $yearselection .= '<option value="' . $i . '"' . $selected . '>' . $i . '</option>';
            }
            $yearselection .= '</select>';

            $monthselection = '<select name="month">';
            for ($i = 1; $i <= 12; $i++) {
                $selected = $i == $month ? ' selected="selected"' : '';
                $monthselection .= '<option value="' . $i . '"' . $selected . '>' . $i . '</option>';
            }
            $monthselection .= '</select>';

            print('<h1 align="center">' . $lang['text_uploaders'] . ' - ' . date('Y-m', $timestart) . '</h1>');

            print('<div style="width: 940px">');
            print('<div>');
            print('<form method="get" action="?">');
            print('<span>' . $lang['text_select_month'] . $yearselection . '&nbsp;&nbsp;' . $monthselection . '&nbsp;&nbsp;<input type="submit" value="' . $lang['submit_go'] . '" /></span>');
            print('</form>');
            print('</div>');

            $num = DB::table('users')
                ->where('class', '>=', User::CLASS_UPLOADER)
                ->count();

            if (! $num) {
                print('<p align="center">' . $lang['text_no_uploaders_yet'] . '</p>');
            } else {
                print('<div style="margin-top: 8px">');

                print('<table border="1" cellspacing="0" cellpadding="5" align="center" width="97%">');
                print('<tr>');
                print('<td class="colhead">' . $lang['col_username'] . '</td>');
                print('<td class="colhead">' . $lang['col_torrents_size'] . '</td>');
                print('<td class="colhead">' . $lang['col_torrents_num'] . '</td>');
                print('<td class="colhead">' . $lang['col_last_upload_time'] . '</td>');
                print('<td class="colhead">' . $lang['col_last_upload'] . '</td>');
                print('</tr>');

                $rows = DB::table('torrents')
                    ->join('users', 'torrents.owner', '=', 'users.id')
                    ->where('users.class', '>=', User::CLASS_UPLOADER)
                    ->where('torrents.added', '>=', $sqlstarttime)
                    ->where('torrents.added', '<', $sqlendtime)
                    ->select('users.id AS userid', 'users.username AS username')
                    ->selectRaw('COUNT(torrents.id) AS torrent_count')
                    ->selectRaw('COALESCE(SUM(torrents.size), 0) AS torrent_size')
                    ->groupBy('users.id')
                    ->orderByRaw($orderClause)
                    ->get();

                $hasupuserid = [];
                foreach ($rows as $row) {
                    $lastTorrent = DB::table('torrents')
                        ->where('owner', $row->userid)
                        ->orderByDesc('id')
                        ->first(['id', 'name', 'added']);

                    print('<tr>');
                    print('<td class="colfollow">' . get_username($row->userid, false, true, true, false, false, true) . '</td>');
                    print('<td class="colfollow">' . ($row->torrent_size ? mksize($row->torrent_size) : '0') . '</td>');
                    print('<td class="colfollow">' . $row->torrent_count . '</td>');
                    print('<td class="colfollow">' . ($lastTorrent && $lastTorrent->added ? gettime($lastTorrent->added) : $lang['text_not_available']) . '</td>');
                    print('<td class="colfollow">' . ($lastTorrent && $lastTorrent->name ? '<a href="details.php?id=' . $lastTorrent->id . '">' . htmlspecialchars($lastTorrent->name) . '</a>' : $lang['text_not_available']) . '</td>');
                    print('</tr>');
                    $hasupuserid[] = $row->userid;
                }

                $noUploadUsers = DB::table('users')
                    ->where('class', '>=', User::CLASS_UPLOADER)
                    ->when(! empty($hasupuserid), function ($query) use ($hasupuserid) {
                        return $query->whereNotIn('id', $hasupuserid);
                    })
                    ->orderBy('username')
                    ->get(['id AS userid', 'username']);

                foreach ($noUploadUsers as $row) {
                    $lastTorrent = DB::table('torrents')
                        ->where('owner', $row->userid)
                        ->orderByDesc('id')
                        ->first(['id', 'name', 'added']);

                    print('<tr>');
                    print('<td class="colfollow">' . get_username($row->userid, false, true, true, false, false, true) . '</td>');
                    print('<td class="colfollow">0</td>');
                    print('<td class="colfollow">0</td>');
                    print('<td class="colfollow">' . ($lastTorrent && $lastTorrent->added ? gettime($lastTorrent->added) : $lang['text_not_available']) . '</td>');
                    print('<td class="colfollow">' . ($lastTorrent && $lastTorrent->name ? '<a href="details.php?id=' . $lastTorrent->id . '">' . htmlspecialchars($lastTorrent->name) . '</a>' : $lang['text_not_available']) . '</td>');
                    print('</tr>');
                }

                print('</table>');
                print('</div>');

                print('<div style="margin-top: 8px; margin-bottom: 8px;">');
                print('<span id="order" onclick="dropmenu(this);"><span style="cursor: pointer;" class="big"><b>' . $lang['text_order_by'] . '</b></span>');
                print('<span id="orderlist" class="dropmenu" style="display: none"><ul>');
                print('<li><a href="?year=' . $year . '&amp;month=' . $month . '&amp;order=username">' . $lang['text_username'] . '</a></li>');
                print('<li><a href="?year=' . $year . '&amp;month=' . $month . '&amp;order=torrent_size">' . $lang['text_torrent_size'] . '</a></li>');
                print('<li><a href="?year=' . $year . '&amp;month=' . $month . '&amp;order=torrent_count">' . $lang['text_torrent_num'] . '</a></li>');
                print('</ul></span></span>');
                print('</div>');
            }

            print('</div>');
        });

        return view('uploaders', compact('content') + [
            'pageTitle' => $title,
        ]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}