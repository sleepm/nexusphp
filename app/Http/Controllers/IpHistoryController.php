<?php

namespace App\Http\Controllers;

use App\Enums\Permission\PermissionEnum;
use App\Models\IpLog;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class IpHistoryController extends Controller
{
    /**
     * IP history log for a user. Mirrors legacy public/iphistory.php: lists the
     * user's current IP (users.ip) plus every distinct iplog entry, paginated
     * 20 per page, each with a reverse-DNS hostname and a link to ipsearch.php
     * showing how many users share the IP (marked "Dupe" when > 1).
     *
     * GET /iphistory.php?id= requires the userprofile (VIEW_USER_CONFIDENTIAL_INFO)
     * permission.
     */
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

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        if (! user_can(PermissionEnum::VIEW_USER_CONFIDENTIAL_INFO->value)) {
            abort(403, 'Access denied.');
        }

        $langIphistory = get_legacy_lang_file('iphistory');

        $userid = (int) $request->query('id', 0);
        $user = DB::table('users')->where('id', $userid)->first(['id', 'username', 'ip', 'last_access']);
        if (! $user) {
            abort(400, $langIphistory['text_user_not_found']);
        }

        $content = $this->capture(function () use ($request, $langIphistory, $user, $userid) {
            $rows = collect();
            if (! empty($user->ip)) {
                $rows->push((object) ['ip' => $user->ip, 'access' => $user->last_access]);
            }
            $iplogRows = IpLog::query()
                ->where('userid', $userid)
                ->select(['ip', 'access'])
                ->distinct()
                ->get();
            foreach ($iplogRows as $log) {
                $rows->push((object) ['ip' => $log->ip, 'access' => $log->access]);
            }
            $rows = $rows
                ->unique(fn ($row) => $row->ip . '|' . $row->access)
                ->sortByDesc('access')
                ->values();
            $countrows = $rows->count();

            $perpage = 20;
            $order = (string) $request->query('order', '');
            list($pagertop, $pagerbottom, $limit) = pager($perpage, $countrows, "iphistory.php?id=$userid&order=$order&");
            preg_match('/limit (\d+) offset (\d+)/', $limit, $limitMatches);
            $pageRows = $rows
                ->slice((int) ($limitMatches[2] ?? 0), (int) ($limitMatches[1] ?? $perpage))
                ->values();

            $ips = $pageRows->pluck('ip')->filter()->values()->all();
            $ipCounts = [];
            if ($ips !== []) {
                $userIdsByIp = DB::table('users')->whereIn('ip', $ips)->select(['id', 'ip'])->get()->groupBy('ip');
                $logUserIdsByIp = DB::table('iplog')->whereIn('ip', $ips)->where('userid', '>', 0)
                    ->select(['userid', 'ip'])->distinct()->get()->groupBy('ip');
                foreach ($ips as $ip) {
                    $ids = collect($userIdsByIp->get($ip, collect()))
                        ->pluck('id')
                        ->merge(collect($logUserIdsByIp->get($ip, collect()))->pluck('userid'))
                        ->unique();
                    $ipCounts[$ip] = $ids->count();
                }
            }

            print('<h1 align="center">' . $langIphistory['text_historical_ip_by'] . get_username($userid) . '</h1>');
            if ($countrows > $perpage) {
                echo $pagertop;
            }

            print('<table width=500 border=1 cellspacing=0 cellpadding=5 align=center>' . "\n");
            print('<tr>'
                . '<td class=colhead>' . $langIphistory['col_last_access'] . '</td>'
                . '<td class=colhead>' . $langIphistory['col_ip'] . '</td>'
                . '<td class=colhead>' . $langIphistory['col_hostname'] . '</td>'
                . "</tr>\n");

            foreach ($pageRows as $arr) {
                $addr = $langIphistory['text_not_available'];
                $ipshow = '';
                if ($arr->ip) {
                    $ip = $arr->ip;
                    $dom = @gethostbyaddr($ip);
                    if ($dom != $ip && @gethostbyname($dom) == $ip) {
                        $addr = $dom;
                    }

                    $ipcount = $ipCounts[$ip] ?? 0;
                    if ($ipcount > 1) {
                        $ipshow = '<a href="ipsearch.php?ip=' . $arr->ip . '">' . $arr->ip . '</a> <b>(<font class=\'striking\'>' . $langIphistory['text_duplicate'] . '</font>)</b>';
                    } else {
                        $ipshow = '<a href="ipsearch.php?ip=' . $arr->ip . '">' . $arr->ip . '</a>';
                    }
                }
                print('<tr><td>' . gettime($arr->access) . '</td>');
                print('<td>' . $ipshow . '</td>');
                print('<td>' . $addr . "</td></tr>\n");
            }
            print('</table>');

            echo $pagerbottom;
        });

        return view('iphistory', compact('content') + [
            'pageTitle' => $langIphistory['head_ip_history_log_for'] . $user->username,
        ]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}
