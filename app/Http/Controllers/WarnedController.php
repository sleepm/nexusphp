<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WarnedController extends Controller
{
    public function web(Request $request)
    {
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

        $title = 'Warned Users';
        $warnedCount = User::query()->where('warned', 'yes')->count();

        $content = $this->capture(function () use ($title, $warnedCount) {
            printf('<h1>Warned Users: (%s)</h1>', number_format($warnedCount));

            $users = User::query()
                ->where('warned', 'yes')
                ->where('enabled', 'yes')
                ->orderByRaw('(uploaded/downloaded)')
                ->get();

            print('<table border=1 width=675 cellspacing=0 cellpadding=2>');
            print('<form action="nowarn.php" method="post">');
            print('<tr align=center>'
                . '<td class=colhead width=90>User Name</td>'
                . '<td class=colhead width=70>Registered</td>'
                . '<td class=colhead width=75>Last access</td>'
                . '<td class=colhead width=75>User Class</td>'
                . '<td class=colhead width=70>Downloaded</td>'
                . '<td class=colhead width=70>UpLoaded</td>'
                . '<td class=colhead width=45>Ratio</td>'
                . '<td class=colhead width=125>End<br>Of Warning</td>'
                . '<td class=colhead width=65>Remove<br>Warning</td>'
                . '<td class=colhead width=65>Disable<br>Account</td></tr>' . "\n");

            foreach ($users as $arr) {
                $rawAdded = $arr->getRawOriginal('added');
                $added = ($rawAdded === '0000-00-00 00:00:00' || $rawAdded === null) ? '-' : substr($rawAdded, 0, 10);

                $rawLastAccess = $arr->getRawOriginal('last_access');
                $last_access = ($rawLastAccess === '0000-00-00 00:00:00' || $rawLastAccess === null) ? '-' : substr($rawLastAccess, 0, 10);

                if ($arr->downloaded != 0) {
                    $ratio = number_format($arr->uploaded / $arr->downloaded, 3);
                } else {
                    $ratio = '---';
                }
                $ratio = '<font color="' . get_ratio_color($ratio) . '">' . $ratio . '</font>';
                $uploaded = mksize($arr->uploaded);
                $downloaded = mksize($arr->downloaded);
                $class = get_user_class_name($arr->class, false, true, true);

                $rawWarnedUntil = $arr->getRawOriginal('warneduntil');
                $warneduntil = ($rawWarnedUntil === '0000-00-00 00:00:00' || $rawWarnedUntil === null) ? '-' : $rawWarnedUntil;

                print('<tr>'
                    . '<td align=left>' . get_username($arr->id) . '</td>'
                    . '<td align=center>' . $added . '</td>'
                    . '<td align=center>' . $last_access . '</td>'
                    . '<td align=center>' . $class . '</td>'
                    . '<td align=center>' . $downloaded . '</td>'
                    . '<td align=center>' . $uploaded . '</td>'
                    . '<td align=center>' . $ratio . '</td>'
                    . '<td align=center>' . $warneduntil . '</td>'
                    . '<td bgcolor="#008000" align=center><input type="checkbox" name="usernw[]" value="' . $arr->id . '"></td>'
                    . '<td bgcolor="#FF0000" align=center><input type="checkbox" name="desact[]" value="' . $arr->id . '"></td></tr>' . "\n");
            }

            if (get_user_class() >= User::CLASS_ADMINISTRATOR) {
                print('<tr><td colspan=10 align=right><input type="submit" name="submit" value="Apply Changes"></td></tr>' . "\n");
            }
            print('<input type="hidden" name="nowarned" value="nowarned"></form>');
            print('</table>');
        });

        return view('warned', compact('content') + [
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