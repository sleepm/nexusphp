<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use App\Repositories\ToolRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Mail test page — replaces legacy public/mailtest.php.
 *
 * GET /mailtest.php renders a small form to enter an email address; POSTing it
 * sends a test SMTP mail through ToolRepository::sendMail() and reports the
 * result. Requires the SYSOP class.
 */
class MailTestController extends Controller
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
        if (get_user_class() < User::CLASS_SYSOP) {
            abort(403, 'Permission denied.');
        }

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $langMailtest = get_legacy_lang_file('mailtest');
        $langFunctions = get_legacy_lang_file('functions');
        $siteName = Setting::getSiteName();

        if ($request->isMethod('POST') && $request->input('action') == 'sendmail') {
            $email = safe_email(trim((string) $request->input('email', '')));
            if (! check_email($email)) {
                $content = $this->capture(function () use ($langMailtest) {
                    stderr(
                        $langMailtest['std_error'],
                        $langMailtest['std_invalid_email_address'],
                        true,
                        false,
                        false,
                        false
                    );
                });

                return view('mailtest', compact('content') + [
                    'pageTitle' => $langMailtest['head_mail_test'],
                ]);
            }

            $title = $siteName.$langMailtest['text_smtp_testing_mail'];
            $body = $langMailtest['mail_test_mail_content'];

            try {
                app(ToolRepository::class)->sendMail($email, $title, $body, true);
                $content = $this->capture(function () use ($langMailtest) {
                    stderr(
                        $langMailtest['std_success'],
                        $langMailtest['std_success_note'],
                        true,
                        false,
                        false,
                        false
                    );
                });
            } catch (\Throwable $e) {
                do_log($e->getMessage().$e->getTraceAsString(), 'error');
                $content = $this->capture(function () use ($langMailtest, $langFunctions, $e) {
                    stderr(
                        $langMailtest['std_error'],
                        $langFunctions['text_unable_to_send_mail']
                            .sprintf('<br/><br/><code>%s</code>', $e->getMessage()),
                        true,
                        false,
                        false,
                        false
                    );
                });
            }

            return view('mailtest', compact('content') + [
                'pageTitle' => $langMailtest['head_mail_test'],
            ]);
        }

        $content = $this->capture(function () use ($langMailtest) {
            print('<h1 align="center">'.$langMailtest['text_mail_test'].'</h1>');
            print('<table border="1" cellspacing="0" cellpadding="5">');
            print("<form method='post' action='mailtest.php'>");
            print("<input type='hidden' name='action' value='sendmail'>");
            tr(
                $langMailtest['row_enter_email'],
                "<input type='text' name='email' size='35'><br />".$langMailtest['text_enter_email_note'],
                1
            );
            print("<tr><td colspan=\"2\" align=\"center\"><input type='submit' name='sendmail' value='".$langMailtest['submit_send_it']."'></td></tr>");
            print('</form></table>');
        });

        return view('mailtest', compact('content') + [
            'pageTitle' => $langMailtest['head_mail_test'],
        ]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}
