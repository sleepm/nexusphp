<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\StaffMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ContactStaffController extends Controller
{
    /**
     * Contact-staff compose form. Mirrors legacy public/contactstaff.php so the
     * FAQ / staff / forum links keep working under the Laravel router.
     *
     * GET /contactstaff.php renders the new-PM compose editor; the form posts
     * to takecontact.php (handled by webTakeContact()).
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
        $lang = get_legacy_lang_file('contactstaff');

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_contactstaff'] = $lang;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $content = $this->capture(function () use ($lang) {
            begin_main_frame();
            print('<form id=compose method=post name="compose" action=takecontact.php>');
            begin_compose($lang['text_message_to_staff'] ?? 'Send message to Staff', 'new');
            end_compose();
            print('</form>');
            end_main_frame();
        });

        return view('contactstaff', compact('content') + [
            'pageTitle' => $lang['head_contact_staff'] ?? 'Contact Staff',
        ]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }

    /**
     * Contact-staff submission. Mirrors legacy public/takecontact.php: validates
     * subject/body, enforces the one-message-per-minute flood limit for
     * non-moderators, writes a staff message and clears the staff-message caches.
     *
     * GET  /takecontact.php → 400 (POST-only stub)
     * POST /takecontact.php → actual submission
     */
    public function webTakeContact(Request $request)
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
        $lang = get_legacy_lang_file('takecontact');

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_takecontact'] = $lang;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        if (! $request->isMethod('POST')) {
            abort(400, $lang['std_error'] . ': ' . $lang['std_method']);
        }

        $msg = trim((string) $request->input('body', ''));
        $subject = trim((string) $request->input('subject', ''));

        if (! $msg) {
            abort(400, $lang['std_please_enter_something']);
        }

        if (! $subject) {
            abort(400, $lang['std_please_define_subject']);
        }

        // Anti Flood: one staff message per minute for non-moderators.
        if ((int) $curUser['class'] < (int) User::CLASS_MODERATOR) {
            $lastStaffMsg = $curUser['last_staffmsg'] ?? null;
            if ($lastStaffMsg && strtotime((string) $lastStaffMsg) > (TIMENOW - 60)) {
                $secs = 60 - (TIMENOW - strtotime((string) $lastStaffMsg));
                abort(400, $lang['std_message_flooding'] . $secs
                    . $lang['std_second'] . ($secs == 1 ? '' : $lang['std_s'])
                    . $lang['std_before_sending_pm']);
            }
        }

        StaffMessage::add((int) $curUser['id'], $subject, $msg);

        User::query()->where('id', $curUser['id'])->update(['last_staffmsg' => now()]);

        clear_staff_message_cache();

        if ($returnto = $request->input('returnto')) {
            return redirect((string) $returnto);
        }

        $content = $this->capture(function () use ($lang) {
            stdmsg($lang['std_succeeded'], $lang['std_message_succesfully_sent']);
        });

        return view('contactstaff', compact('content') + [
            'pageTitle' => $lang['std_succeeded'],
        ]);
    }
}