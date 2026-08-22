<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\StaffMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LinksController extends Controller
{
    /**
     * Link management entry point. Mirrors legacy public/linksmanage.php under
     * the Laravel router:
     *
     * - GET /linksmanage.php?action=apply renders the link-exchange application
     *   form for users with the applylink permission.
     * - POST /linksmanage.php with action=newapply validates and files the
     *   application as a staff message.
     * - GET /linksmanage.php (no action) is the admin entry point and redirects
     *   to the Filament System\LinksResource.
     */
    public function web(Request $request)
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        if (! $currentUser) {
            abort(401);
        }
        $curUser = $currentUser->toArray();

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_linksmanage'] = get_legacy_lang_file('linksmanage');
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();
        $GLOBALS['SLOGAN'] = get_setting('main.SLOGAN', '');

        $lang = $GLOBALS['lang_linksmanage'];

        if ($request->isMethod('POST')) {
            return $this->submitApply($request, $curUser, $lang);
        }

        if ($request->query('action') === 'apply') {
            if (! user_can('applylink')) {
                abort(403);
            }

            return $this->showApplyForm($curUser, $lang);
        }

        if (user_can('linkmanage')) {
            return redirect()->route('filament.admin.resources.system.links.index');
        }

        abort(403);
    }

    private function showApplyForm(array $curUser, array $lang)
    {
        $siteName = Setting::getSiteName();

        $content = $this->capture(function () use ($lang, $siteName) {
            begin_main_frame();
            begin_frame($lang['text_apply_for_links'], true, 10, '100%', 'center');
            print('<p align=left><b><font size=5>' . $lang['text_rules'] . '</font></b></p>' . "\n");
            print('<p align=left>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; ' . sprintf($lang['text_rule_one'], getSchemeAndHttpHost(), $GLOBALS['SLOGAN'], $siteName) . '</p>' . "\n");
            print('<p align=left>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; ' . sprintf($lang['text_rule_two'], $siteName) . '</p>' . "\n");
            print('<p align=left>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; ' . $lang['text_rule_three'] . '</p>' . "\n");
            print('<p align=left>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; ' . $lang['text_rule_four'] . '</p>' . "\n");
            print('<p align=left>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; ' . sprintf($lang['text_rule_five'], $siteName) . '</p>' . "\n");
            print('<p align=left>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; ' . sprintf($lang['text_rule_six'], $siteName) . '</p>' . "\n");
            print('<p>' . $lang['text_red_star_required'] . '</p>');
            print('<form method=post action="linksmanage.php">' . "\n");
            print('<table class=main border=1 cellspacing=0 cellpadding=5>' . "\n");
            print('<tr><td class=rowhead>' . $lang['text_site_name'] . '<font color=red>*</font></td><td class=rowfollow align=left><input type=text name=linkname style="width: 200px">&nbsp;&nbsp;<font class=small>' . $lang['text_sitename_note'] . '</font></td></tr>' . "\n");
            print('<tr><td class=rowhead>' . $lang['text_url'] . '<font color=red>*</font></td><td class=rowfollow align=left><input type=text name=url style="width: 200px">&nbsp;&nbsp;<font class=small>' . $lang['text_url_note'] . '</font></td></tr>' . "\n");
            print('<tr><td class=rowhead>' . $lang['text_title'] . '</td><td class=rowfollow align=left><input type=text name=title style="width: 200px">&nbsp;&nbsp;<font class=small>' . $lang['text_title_note'] . '</font></td></tr>' . "\n");
            print('<tr><td class=rowhead>' . $lang['text_administrator'] . '<font color=red>*</font></td><td class=rowfollow align=left><input type=text name=admin style="width: 200px">&nbsp;&nbsp;<font class=small>' . $lang['text_administrator_note'] . '</font></td></tr>' . "\n");
            print('<tr><td class=rowhead>' . $lang['text_email'] . '<font color=red>*</font></td><td class=rowfollow align=left><input type=text name=email style="width: 200px">&nbsp;&nbsp;<font class=small>' . $lang['text_email_note'] . '</font></td></tr>' . "\n");
            print('<tr><td class=rowhead>' . $lang['text_reason'] . '<font color=red>*</font></td><td class=rowfollow align=left><textarea name=reason style="width: 400px" rows=10></textarea></td></tr>' . "\n");
            print('<tr><td colspan=2 align=center><input type="hidden" name="action" value="newapply"><input type=submit value="' . $lang['submit_okay'] . '" class=btn><input type=reset class=btn value="' . $lang['submit_reset'] . '"></td></tr>' . "\n");
            print('</table>' . "\n");
            print('</form>' . "\n");
            end_frame();
            end_main_frame();
        });

        return view('links/apply', compact('content') + [
            'pageTitle' => $lang['head_apply_for_links'],
        ]);
    }

    private function submitApply(Request $request, array $curUser, array $lang)
    {
        if (! user_can('applylink')) {
            abort(403);
        }

        $sitename = unesc((string) $request->input('linkname'));
        $url = unesc((string) $request->input('url'));
        $title = unesc((string) $request->input('title'));
        $admin = unesc((string) $request->input('admin'));
        $email = safe_email(htmlspecialchars(trim((string) $request->input('email'))));
        $reason = unesc((string) $request->input('reason'));

        if (! $sitename) {
            return $this->error($lang['std_error'], $lang['std_no_sitename']);
        }
        if (! $url) {
            return $this->error($lang['std_error'], $lang['std_no_url']);
        }
        if (! $admin) {
            return $this->error($lang['std_error'], $lang['std_no_admin']);
        }
        if (! $email) {
            return $this->error($lang['std_error'], $lang['std_no_email']);
        }
        if (! check_email($email)) {
            return $this->error($lang['std_error'], $lang['std_invalid_email']);
        }
        if (! $reason) {
            return $this->error($lang['std_error'], $lang['std_no_reason']);
        }
        if (strlen($reason) < 20) {
            return $this->error($lang['std_error'], $lang['std_reason_too_short']);
        }

        $message = '[b]Sitename[/b]: ' . $sitename . "\n[b]URL[/b]: " . $url . "\n[b]Title[/b]: " . $title . "\n[b]Administrator: [/b]" . $admin . "\n[b]EMail[/b]: " . $email . "\n[b]Reason[/b]: \n" . $reason . "\n";
        $subject = $sitename . ' applys for links';

        StaffMessage::add((int) $curUser['id'], $subject, $message);

        return $this->error($lang['std_success'], $lang['std_success_note']);
    }

    private function error(string $heading, string $text)
    {
        $content = $this->capture(function () use ($heading, $text) {
            stderr($heading, $text, true, false, false, false);
        });

        return view('links/apply', compact('content') + [
            'pageTitle' => $heading,
        ]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}
