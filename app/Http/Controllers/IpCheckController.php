<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class IpCheckController extends Controller
{
    /**
     * Duplicate IP users. Mirrors legacy public/ipcheck.php: a moderator+ page
     * that lists every enabled user grouped by duplicated IP (users sharing a
     * current users.ip), with registration / last access / traffic / ratio and
     * an active-peer indicator per user.
     *
     * GET /ipcheck.php requires at least the moderator class.
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
        if (get_user_class() < User::CLASS_MODERATOR) {
            abort(403, 'Access denied.');
        }

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $title = 'Duplicate IP users';

        $content = $this->capture(function () use ($title) {
            print('<h1>' . $title . '</h1>');

            $duplicateIps = DB::table('users')
                ->where('enabled', 'yes')
                ->where('ip', '<>', '')
                ->where('ip', '<>', '127.0.0.0')
                ->selectRaw('COUNT(*) AS dupl, ip')
                ->groupBy('ip')
                ->orderByDesc('dupl')
                ->orderBy('ip')
                ->get();

            print('<table width="' . CONTENT_WIDTH . '" border=1 cellspacing=0 cellpadding=5 align=center>');
            print('<tr align=center>'
                . '<td class=colhead width=90>User</td>'
                . '<td class=colhead width=70>Email</td>'
                . '<td class=colhead width=70>Registered</td>'
                . '<td class=colhead width=75>Last access</td>'
                . '<td class=colhead width=70>Downloaded</td>'
                . '<td class=colhead width=70>Uploaded</td>'
                . '<td class=colhead width=45>Ratio</td>'
                . '<td class=colhead width=125>IP</td>'
                . '<td class=colhead width=40>Peer</td></tr>' . "\n");

            $uc = 0;
            foreach ($duplicateIps as $ras) {
                if ($ras->dupl <= 1) {
                    break;
                }
                $users = DB::table('users')
                    ->where('ip', $ras->ip)
                    ->orderBy('id')
                    ->get(['id', 'username', 'email', 'added', 'last_access', 'downloaded', 'uploaded', 'ip', 'warned', 'donor', 'enabled']);
                if ($users->count() <= 1) {
                    continue;
                }
                foreach ($users as $arr) {
                    $uc++;
                    $added = ($arr->added == '0000-00-00 00:00:00' || $arr->added == null) ? '-' : substr($arr->added, 0, 10);
                    $last_access = ($arr->last_access == '0000-00-00 00:00:00' || $arr->last_access == null) ? '-' : substr($arr->last_access, 0, 10);

                    if ($arr->downloaded != 0) {
                        $ratio = number_format($arr->uploaded / $arr->downloaded, 3);
                    } else {
                        $ratio = '---';
                    }
                    $ratio = '<font color="' . get_ratio_color($ratio) . '">' . $ratio . '</font>';
                    $uploaded = mksize($arr->uploaded);
                    $downloaded = mksize($arr->downloaded);

                    $utc = ($uc % 2 == 0) ? '' : ' bgcolor="ECE9D8"';
                    $peerCount = DB::table('peers')
                        ->where('ip', $ras->ip)
                        ->where('userid', $arr->id)
                        ->count();

                    print('<tr' . $utc . '>'
                        . '<td align=left>' . get_username($arr->id) . '</td>'
                        . '<td align=center>' . $arr->email . '</td>'
                        . '<td align=center>' . $added . '</td>'
                        . '<td align=center>' . $last_access . '</td>'
                        . '<td align=center>' . $downloaded . '</td>'
                        . '<td align=center>' . $uploaded . '</td>'
                        . '<td align=center>' . $ratio . '</td>'
                        . '<td align=center><a href="http://www.whois.sc/' . $arr->ip . '" target="_blank">' . $arr->ip . '</a></td>'
                        . '<td align=center>' . ($peerCount ? 'ja' : 'nein') . '</td></tr>' . "\n");
                }
            }
            print('</table>');
        });

        return view('ipcheck', compact('content') + [
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
