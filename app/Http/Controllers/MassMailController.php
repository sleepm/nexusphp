<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MassMailController extends Controller
{
    /**
     * Mass e-mail gateway. Mirrors legacy public/massmail.php: SysOps pick a
     * user class (with an optional comparison operator) and a subject/body,
     * and a message is e-mailed to every user in that class.
     *
     * GET /massmail.php renders the form; POST /massmail.php performs the send.
     */
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
        if (get_user_class() < User::CLASS_SYSOP) {
            abort(403, 'Permission denied.');
        }

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();
        $GLOBALS['SITEEMAIL'] = (string) get_setting('main.SITEEMAIL', '');

        $class = (int) $request->input('class', 0);
        if ($class && ! is_valid_id($class)) {
            abort(400, 'Invalid class');
        }

        if ($request->isMethod('POST')) {
            $or = (string) $request->input('or', '');
            if (! in_array($or, ['<', '>', '=', '<=', '>='], true)) {
                abort(400, 'Invalid symbol!');
            }

            $users = User::query()
                ->whereRaw('class ' . $or . ' ?', [$class])
                ->get(['id', 'username', 'email']);

            $subject = mb_substr(htmlspecialchars(trim((string) $request->input('subject', ''))), 0, 80);
            if ($subject == '') {
                $subject = '(no subject)';
            }
            $subject = 'Fw: ' . $subject;

            $message1 = htmlspecialchars(trim((string) $request->input('message', '')));
            if ($message1 == '') {
                abort(400, 'Empty message!');
            }

            $siteName = $GLOBALS['SITENAME'];
            $siteEmail = $GLOBALS['SITEEMAIL'];
            $date = date('Y-m-d H:i:s');
            $success = true;
            foreach ($users as $arr) {
                $message = "Message received from " . $siteName . ' on ' . $date . ".\n" .
                    "---------------------------------------------------------------------\n\n" .
                    $message1 . "\n\n" .
                    "---------------------------------------------------------------------\n" . $siteName . "\n";

                if (! sent_mail($arr['email'], $siteName, $siteEmail, $subject, $message, 'Mass Mail', false)) {
                    $success = false;
                }
            }

            if ($success) {
                return $this->noticePage('Success', 'Messages sent.');
            }
            return $this->noticePage('Error', 'Try again.');
        }

        $content = $this->capture(function () use ($curUser) {
            print('<p><table border=0 class=main cellspacing=0 cellpadding=0><tr>');
            print('<td class=embedded style=\'padding-left: 10px\'><font size=3><b>Send mass e-mail to all members</b></font></td>');
            print('</tr></table></p>');
            print('<table border=1 cellspacing=0 cellpadding=5>');
            print('<form method=post action=massmail.php>');

            if (get_user_class() == User::CLASS_MODERATOR && $curUser['class'] > User::CLASS_POWER_USER) {
                printf('<input type=hidden name=class value=' . $curUser['class'] . '>');
            } else {
                print('<tr><td class=rowhead>Classe</td><td colspan=2 align=left>'
                    . '<select name=or><option value=\'<\'><<option value=\'>\'>> <option value=\'=\'>=<option value=\'<=\'><=<option value=\'>=\'>>=</select><select name=class>' . "\n");
                if (get_user_class() == User::CLASS_MODERATOR) {
                    $maxclass = User::CLASS_POWER_USER;
                } else {
                    $maxclass = get_user_class() - 1;
                }
                for ($i = 0; $i <= $maxclass; ++$i) {
                    print('<option value=' . $i . ($curUser['class'] == $i ? ' selected' : '') . '>' . get_user_class_name($i, false, true, true) . "\n");
                }
                print('</select></td></tr>' . "\n");
            }

            print('<tr><td class=rowhead>Subject</td><td><input type=text name=subject size=80></td></tr>');
            print('<tr><td class=rowhead>Body</td><td><textarea name=message cols=80 rows=20></textarea></td></tr>');
            print('<tr><td colspan=2 align=center><input type=submit value="Send" class=btn></td></tr>');
            print('</form>');
            print('</table>');
        });

        return view('massmail', compact('content') + [
            'pageTitle' => 'Mass E-mail Gateway',
        ]);
    }

    private function noticePage(string $heading, string $text)
    {
        $content = $this->capture(function () use ($heading, $text) {
            stdmsg($heading, $text, false);
        });
        return view('massmail', compact('content') + [
            'pageTitle' => 'Mass E-mail Gateway',
        ]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}