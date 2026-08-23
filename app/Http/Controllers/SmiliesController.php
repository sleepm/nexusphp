<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SmiliesController extends Controller
{
    /**
     * Smilies reference page. Mirrors legacy public/smilies.php: lists every
     * [emN] tag with its image in a two-column table. Requires login
     * (legacy loggedinorreturn()).
     */
    public function web()
    {
        $currentUser = Auth::guard('nexus')->user();
        if (! $currentUser) {
            abort(401);
        }
        $GLOBALS['CURUSER'] = $currentUser->toArray();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $langFunctions = get_legacy_lang_file('functions');
        $GLOBALS['lang_functions'] = $langFunctions;

        $content = $this->capture(function () use ($langFunctions) {
            begin_main_frame();
            begin_frame($langFunctions['text_smilies'], true);
            begin_table(false, 5);
            print '<tr><td class="colhead">' . $langFunctions['col_type_something']
                . '</td><td class="colhead">' . $langFunctions['col_to_make_a'] . "</td></tr>\n";
            for ($i = 1; $i < 192; $i++) {
                print "<tr><td>[em$i]</td><td><img src=\"pic/smilies/$i.gif\" alt=\"[em$i]\" /></td></tr>\n";
            }
            end_table();
            end_frame();
            end_main_frame();
        });

        return view('smilies', compact('content') + [
            'pageTitle' => $langFunctions['text_smilies'],
        ]);
    }

    /**
     * More-smilies popup window. Mirrors legacy public/moresmilies.php: a
     * clickable grid of [emN] icons that inserts the tag into the opener's
     * textarea via the SmileIT() javascript. Requires login (legacy
     * loggedinorreturn() + parked()).
     */
    public function more(Request $request)
    {
        $currentUser = Auth::guard('nexus')->user();
        if (! $currentUser) {
            abort(401);
        }
        if ($currentUser->parked == 'yes') {
            abort(403, 'Your account is parked.');
        }
        $GLOBALS['CURUSER'] = $currentUser->toArray();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $lang = get_legacy_lang_file('moresmilies');
        $GLOBALS['lang_moresmilies'] = $lang;

        $form = htmlspecialchars((string) $request->query('form', ''));
        $text = htmlspecialchars((string) $request->query('text', ''));

        $content = $this->capture(function () use ($form, $text) {
            $count = 0;
            for ($i = 1; $i < 192; $i++) {
                if ($count % 3 == 0) {
                    print "\n<tr>";
                }
                print "\n\t<td class=\"lista\" align=\"center\"><a href=\"javascript: SmileIT('[em$i]','$form','$text')\"><img src=\"pic/smilies/$i.gif\" alt=\"\" ></a></td>";
                $count++;
                if ($count % 3 == 0) {
                    print "\n</tr>";
                }
            }
        });

        return view('moresmilies', compact('content', 'lang') + [
            'pageTitle' => $lang['head_more_smilies'],
        ]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();

        return (string) ob_get_clean();
    }
}
