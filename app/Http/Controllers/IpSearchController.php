<?php

namespace App\Http\Controllers;

use App\Enums\Permission\PermissionEnum;
use App\Models\IpLog;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class IpSearchController extends Controller
{
    /**
     * Search in IP history. Mirrors legacy public/ipsearch.php: an admin search
     * page that finds every user whose current IP (users.ip) or historical
     * iplog entry matches the given IP / subnet mask, paginated 20 per page.
     *
     * GET /ipsearch.php?ip=&mask=&order=&page= requires the userprofile
     * (VIEW_USER_CONFIDENTIAL_INFO) permission.
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

        $langIpsearch = get_legacy_lang_file('ipsearch');
        $ip = htmlspecialchars(trim((string) $request->query('ip', '')), ENT_QUOTES);
        $mask = trim((string) $request->query('mask', ''));

        if ($ip && ! filter_var($ip, FILTER_VALIDATE_IP)) {
            abort(400, $langIpsearch['std_invalid_ip']);
        }

        $exact = ($mask === '' || $mask === '255.255.255.255');
        if (! $exact) {
            $regex = '/^(((1?\d{1,2})|(2[0-4]\d)|(25[0-5]))(\.\b|$)){4}$/';
            if (substr($mask, 0, 1) === '/') {
                $n = substr($mask, 1);
                if (! is_numeric($n) || $n < 0 || $n > 32) {
                    abort(400, $langIpsearch['std_invalid_subnet_mask']);
                }
                $mask = long2ip(pow(2, 32) - pow(2, 32 - (int) $n));
            } elseif (! preg_match($regex, $mask)) {
                abort(400, $langIpsearch['std_invalid_subnet_mask']);
            }
        }

        $content = $this->capture(function () use ($request, $langIpsearch, $ip, $mask, $exact) {
            print('<h1 align="center">' . $langIpsearch['text_search_ip_history'] . "</h1>\n");
            print('<form method="get" action="ipsearch.php">');
            print('<table align=center border=1 cellspacing=0 width=115 cellpadding=5>' . "\n");
            print('<tr><td class=rowhead>' . $langIpsearch['row_ip'] . '<font color=red>*</font></td>'
                . '<td><input type="text" name="ip" size="40" value="' . htmlspecialchars($ip) . '" /></td></tr>' . "\n");
            print('<tr><td class=rowhead><nobr>' . $langIpsearch['row_subnet_mask'] . '</nobr></td>'
                . '<td><input type="text" name="mask" size="40" value="' . htmlspecialchars($mask) . '" /></td></tr>' . "\n");
            print('<tr><td align="right" colspan="2"><input type="submit" value="' . $langIpsearch['submit_search'] . '"/></td></tr>');
            print('</table></form>' . "\n");

            if (! $ip) {
                return;
            }

            if ($exact) {
                $where1 = 'users.ip = ?';
                $bindings1 = [$ip];
                $where2 = 'iplog.ip = ?';
                $bindings2 = [$ip];
            } else {
                $where1 = 'INET_ATON(users.ip) & INET_ATON(?) = INET_ATON(?) & INET_ATON(?)';
                $bindings1 = [$mask, $ip, $mask];
                $where2 = 'INET_ATON(iplog.ip) & INET_ATON(?) = INET_ATON(?) & INET_ATON(?)';
                $bindings2 = [$mask, $ip, $mask];
            }

            $baseSelects = 'users.id, users.username, users.ip AS ip, users.ip AS last_ip, users.last_access, '
                . 'users.email, users.invited_by, users.added, users.class, users.uploaded, users.downloaded, '
                . 'users.donor, users.enabled, users.warned';

            $q1 = DB::table('users')
                ->selectRaw("$baseSelects, users.last_access AS access")
                ->whereRaw($where1, $bindings1);
            $q2 = DB::table('iplog')
                ->selectRaw("$baseSelects, MAX(iplog.access) AS access")
                ->rightJoin('users', 'users.id', '=', 'iplog.userid')
                ->groupBy('users.id')
                ->whereRaw($where2, $bindings2);
            $union = $q1->union($q2);

            $count = (clone $union)->count();

            if ($count == 0) {
                print('<p align="center">' . $langIpsearch['text_no_users_found'] . "</p>\n");

                return;
            }

            $order = (string) $request->query('order', '');
            $orderByRaw = [
                'added' => 'users.added DESC',
                'username' => 'UPPER(users.username) ASC',
                'email' => 'users.email ASC',
                'last_ip' => 'users.ip ASC',
                'last_access' => 'users.ip ASC',
            ];
            $orderby = $orderByRaw[$order] ?? 'access DESC';

            $perpage = 20;
            $href = 'ipsearch.php?ip=' . urlencode($ip) . '&mask=' . urlencode($mask) . '&order=' . urlencode($order) . '&';
            list($pagertop, $pagerbottom, $limit) = pager($perpage, $count, $href);
            preg_match('/limit (\d+) offset (\d+)/', $limit, $limitMatches);

            $rows = (clone $union)
                ->orderByRaw($orderby)
                ->limit((int) ($limitMatches[1] ?? $perpage))
                ->offset((int) ($limitMatches[2] ?? 0))
                ->get();

            $seen = [];
            $resultRows = [];
            foreach ($rows as $row) {
                if (isset($seen[$row->id])) {
                    continue;
                }
                $seen[$row->id] = true;
                $resultRows[] = $row;
            }

            $ids = array_column($resultRows, 'id');
            $ipCounts = [];
            if ($ids !== []) {
                $ipCounts = IpLog::query()
                    ->whereIn('userid', $ids)
                    ->selectRaw('userid, COUNT(DISTINCT ip) AS cnt')
                    ->groupBy('userid')
                    ->pluck('cnt', 'userid')
                    ->all();
            }

            print('<h1 align="center">' . $count . $langIpsearch['text_users_used_the_ip'] . $ip . '</h1>');
            echo $pagertop;

            print('<table width="' . CONTENT_WIDTH . '" border=1 cellspacing=0 cellpadding=5 align=center>' . "\n");
            print('<tr>'
                . '<td class=colhead align=center><a class=colhead href="?ip=' . urlencode($ip) . '&mask=' . urlencode($mask) . '&order=username">' . $langIpsearch['col_username'] . '</a></td>'
                . '<td class=colhead align=center><a class=colhead href="?ip=' . urlencode($ip) . '&mask=' . urlencode($mask) . '&order=last_ip">' . $langIpsearch['col_last_ip'] . '</a></td>'
                . '<td class=colhead align=center><a class=colhead href="?ip=' . urlencode($ip) . '&mask=' . urlencode($mask) . '&order=last_access">' . $langIpsearch['col_last_access'] . '</a></td>'
                . '<td class=colhead align=center>' . $langIpsearch['col_ip_num'] . '</td>'
                . '<td class=colhead align=center><a class=colhead href="?ip=' . urlencode($ip) . '&mask=' . urlencode($mask) . '">' . $langIpsearch['col_last_access_on'] . '</a></td>'
                . '<td class=colhead align=center><a class=colhead href="?ip=' . urlencode($ip) . '&mask=' . urlencode($mask) . '&order=added">' . $langIpsearch['col_added'] . '</a></td>'
                . '<td class=colhead align=center>' . $langIpsearch['col_invited_by'] . '</td></tr>' . "\n");

            foreach ($resultRows as $user) {
                if ($user->added == '0000-00-00 00:00:00' || $user->added == null) {
                    $added = $langIpsearch['text_not_available'];
                } else {
                    $added = gettime($user->added);
                }
                if ($user->last_access == '0000-00-00 00:00:00' || $user->last_access == null) {
                    $lastaccess = $langIpsearch['text_not_available'];
                } else {
                    $lastaccess = gettime($user->last_access);
                }

                $ipstr = $user->last_ip ?: $langIpsearch['text_not_available'];
                $iphistory = $ipCounts[$user->id] ?? 0;

                if ($user->invited_by > 0) {
                    $invited_by = get_username($user->invited_by);
                } else {
                    $invited_by = $langIpsearch['text_not_available'];
                }

                print('<tr><td align="center">' . get_username($user->id) . '</td>'
                    . '<td align="center">' . $ipstr . '</td>'
                    . '<td align="center">' . $lastaccess . '</td>'
                    . '<td align="center"><a href="iphistory.php?id=' . $user->id . '">' . $iphistory . '</a></td>'
                    . '<td align="center">' . gettime($user->access) . '</td>'
                    . '<td align="center">' . $added . '</td>'
                    . '<td align="center">' . $invited_by . "</td></tr>\n");
            }
            print('</table>');

            echo $pagerbottom;
        });

        return view('ipsearch', compact('content') + [
            'pageTitle' => $langIpsearch['head_search_ip_history'],
        ]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}
