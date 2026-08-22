<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UncoController extends Controller
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

        $status = $request->query('status');
        if ($status && ! is_valid_id($status)) {
            abort(400, 'Invalid ID.');
        }

        $title = 'Unconfirmed Users';

        $users = User::query()
            ->where('status', User::STATUS_PENDING)
            ->orderBy('username')
            ->get();

        $content = $this->capture(function () use ($title, $users, $status) {
            if ($users->isEmpty()) {
                if ($status) {
                    stderr('Updated!', 'The user account has been updated.', true, false, false, false);
                } else {
                    stderr('Ups!', 'Nothing Found...', true, false, false, false);
                }

                return;
            }

            print('<h1>' . $title . '</h1>');
            print('<br><table width="100%" border=1 cellspacing=0 cellpadding=5>');
            if ($status) {
                print('<tr><td class=rowhead colspan=5><font color=red size=1>The User account has been updated!</font></td></tr>');
            }
            print('<tr>'
                . '<td class=rowhead><center>Name</center></td>'
                . '<td class=rowhead><center>eMail</center></td>'
                . '<td class=rowhead><center>Added</center></td>'
                . '<td class=rowhead><center>Set Status</center></td>'
                . '<td class=rowhead><center>Confirm</center></td>'
                . '</tr>');

            foreach ($users as $row) {
                $id = $row->id;
                print('<tr><form method="post" action="modtask.php">');
                print('<input type="hidden" name="action" value="confirmuser">');
                print('<input type="hidden" name="userid" value="' . $id . '">');
                print('<td align="center"><a href="userdetails.php?id=' . $id . '">' . htmlspecialchars($row->username) . '</a></td>');
                print('<td align="center">&nbsp;&nbsp;&nbsp;&nbsp;' . htmlspecialchars($row->email) . '</td>');
                print('<td align="center">&nbsp;&nbsp;&nbsp;&nbsp;' . $row->getRawOriginal('added') . '</td>');
                print('<td align="center"><select name="confirm"><option value="pending">pending</option><option value="confirmed">confirmed</option></select></td>');
                print('<td align="center"><input type="submit" value="-Go-" style="height: 20px; width: 40px"></td>');
                print('</form></tr>');
            }
            print('</table>');
        });

        return view('unco', compact('content') + [
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