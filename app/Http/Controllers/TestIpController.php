<?php

namespace App\Http\Controllers;

use App\Models\Ban;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TestIpController extends Controller
{
    /**
     * Test an IP address against the IP ban table. Mirrors legacy
     * public/testip.php: a moderator+ page with a small form; submitting an IP
     * looks it up in the bans table (first <= long(ip) <= last) and reports
     * whether it is banned, listing the matching ban ranges.
     *
     * GET/POST /testip.php requires at least the moderator class.
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
            abort(403, 'Permission denied.');
        }

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $ip = trim((string) $request->input('ip', ''));
        $resultHtml = '';
        if ($ip) {
            $nip = ip2long($ip);
            if ($nip === false || $nip == -1) {
                abort(400, 'Bad IP.');
            }
            $bans = Ban::query()
                ->where('first', '<=', $nip)
                ->where('last', '>=', $nip)
                ->orderBy('first')
                ->get();

            if ($bans->isEmpty()) {
                $resultHtml = '<p>The IP address <b>' . htmlspecialchars($ip) . '</b> is not banned.</p>';
            } else {
                $resultHtml = '<p>The IP address <b>' . htmlspecialchars($ip) . '</b> is banned:</p>';
                $resultHtml .= '<table class=main border=0 cellspacing=0 cellpadding=5>'
                    . '<tr><td class=colhead>First</td><td class=colhead>Last</td><td class=colhead>Comment</td></tr>';
                foreach ($bans as $arr) {
                    $resultHtml .= '<tr><td>' . long2ip($arr->first) . '</td>'
                        . '<td>' . long2ip($arr->last) . '</td>'
                        . '<td>' . htmlspecialchars($arr->comment) . '</td></tr>';
                }
                $resultHtml .= '</table>';
            }
        }

        $content = $this->capture(function () use ($ip, $resultHtml) {
            print('<h1>Test IP address</h1>');
            if ($resultHtml !== '') {
                print($resultHtml);
            }
            print('<form method=post action=testip.php>');
            print('<table border=1 cellspacing=0 cellpadding=5>');
            print('<tr><td class=rowhead>IP address</td><td><input type=text name=ip value="' . htmlspecialchars($ip) . '"></td></tr>');
            print("<tr><td colspan=2 align=center><input type=submit class=btn value='OK'></td></tr>");
            print('</form>');
            print('</table>');
        });

        return view('testip', compact('content') + [
            'pageTitle' => 'Test IP address',
        ]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}
