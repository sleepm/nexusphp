<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;

class EmailGatewayController extends Controller
{
    public function web(Request $request)
    {
        $id = $request->query('id');
        if (! is_numeric($id) || $id < 1 || floor((float) $id) != (float) $id) {
            abort(400, 'Invalid ID');
        }
        $id = (int) $id;

        $user = User::query()->find($id, ['username', 'class', 'email']);
        if (! $user) {
            return $this->messagePage('Error', 'No such user.');
        }
        $arr = $user->toArray();

        if ((int) $arr['class'] < (int) User::CLASS_MODERATOR) {
            return $this->messagePage('Error', 'The gateway can only be used to e-mail staff members.');
        }

        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();
        $GLOBALS['SITEEMAIL'] = (string) get_setting('main.SITEEMAIL', '');
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');

        if ($request->isMethod('POST')) {
            $to = $arr['email'];

            $from = mb_substr(htmlspecialchars(trim((string) $request->input('from', ''))), 0, 80);
            if ($from === '') {
                $from = 'Anonymous';
            }

            $fromEmail = mb_substr(htmlspecialchars(trim((string) $request->input('from_email', ''))), 0, 80);
            if ($fromEmail === '') {
                $fromEmail = $GLOBALS['SITEEMAIL'];
            }
            $fromEmail = safe_email($fromEmail);
            if (! $fromEmail) {
                return $this->messagePage('Error', 'You must enter an email address!');
            }
            if (! check_email($fromEmail)) {
                return $this->messagePage('Error', 'Invalid email address!');
            }
            $from = "$from <$fromEmail>";

            $subject = mb_substr(htmlspecialchars(trim((string) $request->input('subject', ''))), 0, 80);
            if ($subject === '') {
                $subject = '(No subject)';
            }
            $subject = 'Fw: ' . $subject;

            $message = htmlspecialchars(trim((string) $request->input('message', '')));
            if ($message === '') {
                return $this->messagePage('Error', 'No message text!');
            }

            $message = "Message submitted from " . getip() . " at " . date("Y-m-d H:i:s") . ".\n" .
                "Note: By replying to this e-mail you will reveal your e-mail address.\n" .
                "---------------------------------------------------------------------\n\n" .
                $message . "\n\n" .
                "---------------------------------------------------------------------\n" . $GLOBALS['SITENAME'] . " E-Mail Gateway\n";

            $success = sent_mail($to, $from, $fromEmail, $subject, $message, 'E-Mail Gateway', false);

            if ($success) {
                return $this->messagePage('Success', 'E-mail successfully queued for delivery.');
            }
            return $this->messagePage('Error', 'The mail could not be sent. Please try again later.');
        }

        $username = $arr['username'];

        $content = $this->capture(function () use ($id, $username) {
            ?>
            <p>
            <table border=0 class=main cellspacing=0 cellpadding=0><tr>
            <td class=embedded style='padding-left: 10px'><font size=3><b>Send e-mail to <?php echo $username; ?></b></font></td>
            </tr></table>
            </p>
            <table border=1 cellspacing=0 cellpadding=5>
            <form method=post action=email-gateway.php?id=<?php echo $id; ?>>
            <tr><td class=rowhead>Your name</td><td><input type=text name=from size=80></td></tr>
            <tr><td class=rowhead>Your e-mail</td><td><input type=text name=from_email size=80></td></tr>
            <tr><td class=rowhead>Subject</td><td><input type=text name=subject size=80></td></tr>
            <tr><td class=rowhead>Message</td><td><textarea name=message cols=80 rows=20></textarea></td></tr>
            <tr><td colspan=2 align=center><input type=submit value="Send" class=btn></td></tr>
            </form>
            </table>
            <p>
            <font class=small><b>Note:</b> Your IP-address will be logged and visible to the recipient to prevent abuse.<br />
            Make sure to supply a valid e-mail address if you expect a reply.</font>
            </p>
            <?php
        });

        return view('email-gateway', compact('content') + [
            'pageTitle' => 'E-mail gateway',
        ]);
    }

    private function messagePage(string $heading, string $message)
    {
        return view('error.notification', [
            'pageTitle' => $heading,
            'heading' => htmlspecialchars($heading),
            'message' => htmlspecialchars($message),
        ]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}