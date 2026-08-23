<?php

namespace App\Http\Controllers;

use App\Models\Language;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class AboutNexusController extends Controller
{
    /**
     * About-Nexus page. Mirrors legacy public/aboutnexus.php: renders version
     * info, a description of NexusPHP, translation status, stylesheet credits,
     * and contact info. Open to guests.
     */
    public function web()
    {
        $lang = get_legacy_lang_file('aboutnexus');
        $GLOBALS['lang_aboutnexus'] = $lang;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $siteName = Setting::getSiteName();
        $projectName = PROJECTNAME;
        $versionNumber = VERSION_NUMBER;
        $releaseDate = RELEASE_DATE;
        $nexusPhpUrl = NEXUSPHPURL;

        $languages = Language::query()
            ->orderBy('trans_state')
            ->get(['flagpic', 'lang_name', 'trans_state']);

        $stylesheets = DB::table('stylesheets')
            ->orderBy('id')
            ->get(['name', 'designer', 'comment']);

        $content = $this->capture(function () use ($lang, $siteName, $projectName, $versionNumber, $releaseDate, $nexusPhpUrl, $languages, $stylesheets) {
            print('<h1>' . $projectName . '</h1>');
            begin_main_frame();
            begin_frame('<span id="version">' . $lang['text_version'] . '</span>');
            echo sprintf($lang['text_version_note'], $siteName, $projectName);
            print('<br /><br /><table class="main" border="1" cellspacing="0" cellpadding="5" align="center">');
            tr($lang['text_main_version'], $projectName, 1);
            tr($lang['text_sub_version'], $versionNumber, 1);
            tr($lang['text_release_date'], $releaseDate, 1);
            print('</table>');
            print('<br /><br />');
            end_frame();
            begin_frame('<span id="nexus">' . $lang['text_nexus'] . $projectName . '</span>');
            echo sprintf($projectName . $lang['text_nexus_note'], $projectName);
            print('<br /><br />');
            end_frame();
            begin_frame('<span id="authorization">' . $lang['text_authorization'] . '</span>');
            echo sprintf($lang['text_authorization_note'], $projectName);
            print('<br /><br />');
            end_frame();
            $ppl = '';
            foreach ($languages as $langRow) {
                $ppl .= '<tr><td class="rowfollow"><img width="24" height="15" src="pic/flag/' . htmlspecialchars($langRow->flagpic) . '" alt="' . htmlspecialchars($langRow->lang_name) . '" title="' . htmlspecialchars($langRow->lang_name) . '" style="padding-bottom:1px;" /></td>'
                    . '<td class="rowfollow">' . htmlspecialchars($langRow->lang_name) . '</td>'
                    . '<td class="rowfollow">' . htmlspecialchars($langRow->trans_state) . '</td></tr>' . "\n";
            }
            begin_frame('<span id="translation">' . $lang['text_translation'] . '</span>');
            print($projectName . $lang['text_translation_note']);
            print('<br /><br /><table class="main" border="1" cellspacing="0" cellpadding="5" align="center"><tr><td class="colhead">' . $lang['text_flag'] . '</td><td class="colhead">' . $lang['text_language'] . '</td><td class="colhead">' . $lang['text_state'] . '</td></tr>');
            print($ppl);
            print('</table>');
            print('<br /><br />');
            end_frame();
            $ppl = '';
            foreach ($stylesheets as $ss) {
                $ppl .= '<tr><td class="rowfollow">' . htmlspecialchars($ss->name) . '</td>'
                    . '<td class="rowfollow">' . htmlspecialchars($ss->designer) . '</td>'
                    . '<td class="rowfollow">' . htmlspecialchars($ss->comment) . '</td></tr>' . "\n";
            }
            begin_frame('<span id="stylesheet">' . $lang['text_stylesheet'] . '</span>');
            echo sprintf($lang['text_stylesheet_note'], $projectName, $siteName);
            print('<br /><br /><table class="main" border="1" cellspacing="0" cellpadding="5" align="center"><tr><td class="colhead">' . $lang['text_name'] . '</td><td class="colhead">' . $lang['text_designer'] . '</td><td class="colhead">' . $lang['text_comment'] . '</td></tr>');
            print($ppl);
            print('</table>');
            print('<br /><br />');
            end_frame();
            begin_frame('<span id="contact">' . $lang['text_contact'] . $projectName . '</span>');
            print($lang['text_contact_note']);
            print('<br /><br /><table class="main" border="1" cellspacing="0" cellpadding="5" align="center">');
            tr($lang['text_web_site'], '<a href="' . $nexusPhpUrl . '" target="_blank">' . $nexusPhpUrl . '</a>', 1);
            print('</table>');
            print('<br /><br />');
            end_frame();
            end_main_frame();
        });

        return view('aboutnexus', compact('content') + [
            'pageTitle' => $projectName,
        ]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();

        return (string) ob_get_clean();
    }
}