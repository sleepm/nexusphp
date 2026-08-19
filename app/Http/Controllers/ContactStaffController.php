<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ContactStaffController extends Controller
{
    /**
     * Contact-staff compose form. Mirrors legacy public/contactstaff.php so the
     * FAQ / staff / forum links keep working under the Laravel router.
     *
     * GET /contactstaff.php renders the new-PM compose editor; the form posts
     * to takecontact.php (still a legacy script).
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
}