<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DownloadNoticeController extends Controller
{
    /**
     * Pre-download notice gate. Mirrors legacy public/downloadnotice.php so the
     * security layers redirecting from download.php keep working under the
     * Laravel router instead of the procedural script.
     *
     * GET /downloadnotice.php?torrentid=&type=firsttime|client|ratio renders the
     * matching notice page; POST id + type + optional hidenotice suppresses the
     * notice for the user and redirects back to download.php with letdown=1.
     */
    public function web(Request $request)
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        if (! $currentUser) {
            abort(401);
        }
        $curUser = $currentUser->toArray();

        // globals the shared legacy helpers expect (mirrors public/downloadnotice.php bootstrap)
        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_downloadnotice'] = get_legacy_lang_file('downloadnotice');
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $lang = $GLOBALS['lang_downloadnotice'];

        if ($request->isMethod('POST')) {
            return $this->submitDownloadNotice($request, $curUser);
        }

        return $this->showDownloadNotice($request, $curUser, $lang);
    }

    private function submitDownloadNotice(Request $request, array $curUser)
    {
        $torrentId = (int) $request->input('id', 0);
        $type = (string) $request->input('type', '');
        $hidenotice = (bool) $request->input('hidenotice', 0);
        if (! $torrentId || ! in_array($type, ['firsttime', 'client', 'ratio'])) {
            return response('error', 400);
        }

        if ($type == 'firsttime') {
            if ($hidenotice) {
                DB::table('users')->where('id', $curUser['id'])->update(['showdlnotice' => 0]);
            }
        } elseif ($type == 'client') {
            if ($hidenotice) {
                DB::table('users')->where('id', $curUser['id'])->update(['showclienterror' => 'no']);
            }
        }

        return redirect('/download.php?id=' . $torrentId . '&letdown=1');
    }

    private function showDownloadNotice(Request $request, array $curUser, array $lang)
    {
        $torrentId = (int) $request->query('torrentid', 0);
        $type = (string) $request->query('type', '');

        $note = '';
        $showRatioNotice = false;
        $showClientNotice = false;
        $forceCheck = false;

        switch ($type) {
            case 'client':
                $title = $lang['text_client_banned_notice'];
                $note = $lang['text_client_banned_note'];
                $noticenexttime = $lang['text_notice_not_show_again'];
                $showRatioNotice = false;
                $showClientNotice = true;
                $forceCheck = false;
                break;

            case 'ratio':
                $title = $lang['text_low_ratio_notice'];
                $leechWarnUntil = strtotime($curUser['leechwarnuntil'] ?? '');
                if (TIMENOW < $leechWarnUntil) {
                    $kickTimeout = gettime($curUser['leechwarnuntil'], false, false, true);
                    $note = $lang['text_low_ratio_note_one'] . $kickTimeout . $lang['text_low_ratio_note_two'];
                }
                $noticenexttime = $lang['text_notice_always_show'];
                $showRatioNotice = true;
                $showClientNotice = false;
                $forceCheck = true;
                break;

            case 'firsttime':
            default:
                $type = 'firsttime';
                $title = $lang['text_first_time_download_notice'];
                $note = $lang['text_first_time_download_note'];
                $noticenexttime = $lang['text_notice_not_show_again'];
                $showRatioNotice = true;
                $showClientNotice = true;
                $forceCheck = false;
                break;
        }

        if ($showRatioNotice && $showClientNotice) {
            $tdattr = 'width="50%"';
        } else {
            $tdattr = 'colspan="2" width="100%"';
        }

        $content = $this->capture(function () use ($lang, $title, $note, $noticenexttime, $tdattr, $showRatioNotice, $showClientNotice, $torrentId, $type, $forceCheck) {
            echo '<h2>' . $title . '</h2>' . "\n";
            echo '<table width="100%"><tr>' . "\n"
                . '<td colspan="2" class="text" align="left"><p>' . $note . '</p></td></tr>' . "\n"
                . '<tr>' . "\n";
            if ($showRatioNotice) {
                echo '<td class="text" align="left" valign="top" ' . $tdattr . '>' . "\n"
                    . '<h3>' . $lang['text_this_is_private_tracker'] . '</h3>' . "\n"
                    . '<p>' . $lang['text_private_tracker_note_one'] . '<i>(' . $lang['text_learn_more'] . '<a class="faqlink" href="' . NEXUSWIKIURL . '/Private Tracker" target="_blank">' . $lang['text_nexuswiki'] . '</a>)</i></p>' . "\n"
                    . '<p>' . $lang['text_private_tracker_note_two'] . '<i>(' . $lang['text_see_ratio'] . '<a class="faqlink" href="faq.php#id23" target="_blank">' . $lang['text_faq'] . '</a>)</i></p>' . "\n"
                    . '<p>' . $lang['text_private_tracker_note_three'] . '</p>' . "\n"
                    . '<img src="pic/ratio.png" alt="ratio" />' . "\n"
                    . '<p>' . $lang['text_private_tracker_note_four'] . '</p>' . "\n"
                    . '</td>' . "\n";
            }
            if ($showClientNotice) {
                echo '<td class="text" align="left" valign="top" ' . $tdattr . '>' . "\n"
                    . '<h3>' . $lang['text_use_allowed_clients'] . '</h3>' . "\n"
                    . '<p>' . $lang['text_allowed_clients_note_one'] . '</p>' . "\n"
                    . '<p>' . $lang['text_allowed_clients_note_two'] . "<a class='faqlink' href='faq.php#id29' target='_blank'>" . $lang['text_faq'] . '</a>' . $lang['text_allowed_clients_note_three'] . '</p>' . "\n"
                    . '<table width="100%">' . "\n"
                    . '<tr>' . "\n"
                    . '<td class="embedded" style="text-align: center; padding: 5px;" width="50%">' . "\n"
                    . '<a href="https://www.qbittorrent.org/download" target="_blank" title="' . $lang['title_download'] . 'qBittorrent"><img src="pic/qbittorrent.png" alt="qBittorrent"  width="128" height="128" /></a>' . "\n"
                    . '</td>' . "\n"
                    . '<td class="embedded" style="text-align: center; padding: 5px;" width="50%">' . "\n"
                    . '<a href="https://transmissionbt.com/download/" target="_blank" title="' . $lang['title_download'] . 'Transmission"><img src="pic/transmission.png" alt="Transmission"  width="128" height="128" /></a>' . "\n"
                    . '</td>' . "\n"
                    . '</tr>' . "\n"
                    . '<tr>' . "\n"
                    . '<td class="embedded" style="text-align: center; padding: 5px;">' . "\n"
                    . '<div class="big"><a href="https://www.qbittorrent.org/download" target="_blank" title="' . $lang['title_download'] . 'qBittorrent"><b>qBittorrent</b></a></div>' . "\n"
                    . '<div>' . $lang['text_for'] . 'Windows, Linux, Mac OS</div>' . "\n"
                    . '</td>' . "\n"
                    . '<td class="embedded" style="text-align: center; padding: 5px;">' . "\n"
                    . '<div class="big"><a href="https://transmissionbt.com/download/" target="_blank" title="' . $lang['title_download'] . 'Transmission"><b>Transmission</b></a></div>' . "\n"
                    . '<div>' . $lang['text_for'] . 'Windows, Linux, Mac OS</div>' . "\n"
                    . '</td>' . "\n"
                    . '</tr>' . "\n"
                    . '</table>' . "\n"
                    . '</td>' . "\n";
            }
            echo '</tr>' . "\n";
            if ($torrentId) {
                echo '<tr>' . "\n"
                    . '<td class="text" colspan="2">' . "\n"
                    . '<form action="?" method="post"><p>' . $lang['text_for_more_information_read'] . '<a class="faqlink" href="rules.php" target="_blank">' . $lang['text_rules'] . '</a>' . $lang['text_and'] . '<a class="faqlink" href="faq.php" target="_blank">' . $lang['text_faq'] . '</a><br />' . "\n"
                    . '<input type="hidden" name="id" value="' . $torrentId . '" />' . "\n"
                    . '<input type="hidden" name="type" value="' . htmlspecialchars($type) . '" />' . "\n"
                    . '<input type="checkbox" name="hidenotice" id="hidenotice" value="1"' . ($forceCheck ? ' disabled="disabled"' : ' checked="checked"') . ' /><label for="hidenotice">' . $noticenexttime . '</label>' . "\n";
                if ($forceCheck) {
                    echo '<br /><input type="checkbox" name="letmedown" id="letmedown" value="' . htmlspecialchars($type) . '" onclick="if (this.checked) {document.getElementById(\'continuedownload\').disabled = false;}else{document.getElementById(\'continuedownload\').disabled = true;}" /><label for="letmedown"><span class="big">' . $lang['text_let_me_download'] . '</span></label>' . "\n";
                }
                echo '</p>' . "\n"
                    . '<div><input type="submit" name="submit" id="continuedownload" style="font-size: 20pt; height: 40px;" value="' . $lang['submit_download_the_torrent'] . '"' . ($forceCheck ? ' disabled="disabled"' : '') . ' /></div>' . "\n"
                    . '</form>' . "\n"
                    . '</td>' . "\n"
                    . '</tr>' . "\n";
            }
            echo '</table>' . "\n";
        });

        return view('downloadnotice', compact('content') + [
            'pageTitle' => $lang['head_download_notice'],
        ]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}