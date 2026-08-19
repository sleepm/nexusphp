<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StaffPanelController extends Controller
{
    /**
     * Staff control panel. Mirrors legacy public/staffpanel.php: a table of
     * panel links/descriptions split by SysOp / Administrator / Moderator
     * sections (tables sysoppanel / adminpanel / modpanel).
     *
     * GET /staffpanel.php requires at least the moderator class.
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
        if (get_user_class() < User::CLASS_MODERATOR) {
            abort(403, 'Access denied!!!');
        }

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $content = $this->capture(function () {
            print('<h1 align=center>Administration</h1>');
            begin_main_frame();

            if (get_user_class() >= User::CLASS_SYSOP) {
                print('<h1 align=center>..:: For SysOp Only  ::..</h1>');
                print('<br /><br />');
                $this->renderPanel('sysoppanel');
            }
            if (get_user_class() >= User::CLASS_ADMINISTRATOR) {
                print('<h1 align=center>..:: For Administrator Only :..</h1>');
                print('<br /><br />');
                $this->renderPanel('adminpanel');
            }
            print('<h1 align=center>..:: For Moderator Only  ::..</h1>');
            print('<br /><br />');
            $this->renderPanel('modpanel');

            end_main_frame();
        });

        return view('staffpanel', compact('content') + [
            'pageTitle' => 'Administration',
        ]);
    }

    /** Print one panel table (sysoppanel / adminpanel / modpanel). */
    private function renderPanel(string $table): void
    {
        print('<table width=80% border=1 cellspacing=0 cellpadding=5 align=center>');
        print('<td class=colhead align=left>Option Name</td><td class=colhead align=left>Info</td>');
        $rows = DB::table($table)->orderBy('id')->get();
        foreach ($rows as $row) {
            print('<tr><td class=rowfollow align=left><strong><a href="' . htmlspecialchars($row->url) . '">' . htmlspecialchars($row->name) . '</a></strong></td>'
                . '<td class=rowfollow align=left>' . htmlspecialchars($row->info) . '</td></tr>');
        }
        print('</table>');
        print('<br /><br />');
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}