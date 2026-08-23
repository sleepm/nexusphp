<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Setting;
use App\Models\Sub;
use App\Models\Torrent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SubtitleController extends Controller
{
    /**
     * Subtitle management page. Mirrors legacy public/subtitles.php so the
     * details-page upload form and subtitle list keep working under the
     * Laravel router instead of the procedural script.
     *
     * GET /subtitles.php renders the upload form + paginated subtitle list
     * (optional ?search= / ?letter= / ?lang_id= filters);
     * POST action=upload handles the file upload (skipped when posted with
     * in_detail=in_detail, which only pre-fills the torrent id);
     * GET ?delete=ID shows the confirm form, ?delete=ID&sure=1 deletes.
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

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_subtitles'] = get_legacy_lang_file('subtitles');
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();
        $GLOBALS['bonus_tweak'] = (string) get_setting('tweak.bonus', 'enable');

        $lang = $GLOBALS['lang_subtitles'];

        $maxsubsize = (int) get_setting('main.maxsubsize', 3145728);
        $uploadsubtitleBonus = (float) get_setting('bonus.uploadsubtitle', 5);
        $subsPath = (string) get_setting('main.subspath', 'subs');

        $inDetail = (string) $request->input('in_detail', '');
        $detailTorrentId = (int) $request->input('detail_torrent_id', 0);
        $torrentName = (string) $request->input('torrent_name', '');

        $uploadError = null;

        if ($request->isMethod('POST')
            && $request->input('action') == 'upload'
            && $inDetail !== 'in_detail'
        ) {
            $uploadError = $this->processUpload($request, $curUser, $lang, $maxsubsize, $uploadsubtitleBonus, $subsPath);
        }

        if (user_can('delownsub')) {
            $delete = (int) $request->query('delete', 0);
            if (is_valid_id($delete)) {
                $sub = Sub::query()->where('id', $delete)->first(['id', 'torrent_id', 'ext', 'lang_id', 'title', 'filename', 'uppedby', 'anonymous']);
                if ($sub && (user_can('submanage') || $sub->uppedby == $curUser['id'])) {
                    $sure = (int) $request->query('sure', 0);
                    if ($sure == 1) {
                        $result = $this->deleteSubtitle($sub, $request, $curUser, $lang, $subsPath, $uploadsubtitleBonus);
                        if ($result !== null) {
                            return $this->failDeletePage($sub, $result, $lang);
                        }
                    } else {
                        return $this->deleteConfirmPage($sub, $lang);
                    }
                }
            }
        }

        return $this->showPage($request, $curUser, $lang, $maxsubsize, $inDetail, $detailTorrentId, $torrentName, $uploadError);
    }

    private function processUpload(Request $request, array $curUser, array $lang, int $maxsubsize, float $uploadsubtitleBonus, string $subsPath): ?string
    {
        $file = $request->file('file');

        if (! $file || ! $file->getSize()) {
            return $lang['std_nothing_received'];
        }

        if ($file->getSize() > $maxsubsize && $maxsubsize > 0) {
            return $lang['std_subs_too_big'];
        }

        $acceptExt = ['sub', 'srt', 'zip', 'rar', 'ace', 'txt', 'ssa', 'ass', 'cue'];
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, $acceptExt, true)) {
            return $lang['std_wrong_subs_format'];
        }

        $torrentId = (int) $request->input('torrent_id', 0);
        if (! $torrentId || ! is_numeric($request->input('torrent_id')) || ! preg_match('/^\d+$/', (string) $request->input('torrent_id'))) {
            return $lang['std_invalid_torrent_id'];
        }

        $torrent = Torrent::query()->where('id', $torrentId)->first(['id', 'owner']);
        if (! $torrent) {
            return $lang['std_invalid_torrent_id'];
        }
        if ($torrent->owner != $curUser['id'] && ! user_can('uploadsub')) {
            return $lang['std_no_permission_uploading_others'];
        }

        $title = trim((string) $request->input('title', ''));
        $fileName = $file->getClientOriginalName();
        if ($title == '') {
            $dotPos = strrpos($fileName, '.');
            $title = $dotPos !== false ? substr($fileName, 0, $dotPos) : $fileName;
        }

        $langId = (int) $request->input('sel_lang', 0);
        if ($langId == 0) {
            return $lang['std_must_choose_language'];
        }

        $anonymous = ($request->input('uplver') == 'yes' && user_can('beanonymous')) ? 'yes' : 'no';
        $anon = $anonymous == 'yes' ? 'Anonymous' : $curUser['username'];

        $langName = DB::table('language')->where('sub_lang', 1)->where('id', $langId)->value('lang_name');

        $size = $file->getSize();

        $sub = Sub::query()->create([
            'torrent_id' => $torrentId,
            'lang_id' => $langId,
            'title' => $title,
            'filename' => $fileName,
            'added' => now(),
            'uppedby' => $curUser['id'],
            'anonymous' => $anonymous,
            'size' => $size,
            'ext' => $ext,
        ]);

        $folder = make_folder($subsPath . '/', $torrentId);
        try {
            $file->move($folder, $sub->id . '.' . $ext);
        } catch (\Exception $e) {
            do_log('Failed to move uploaded subtitle file: ' . $e->getMessage(), 'error');
            Sub::query()->where('id', $sub->id)->delete();

            return $lang['std_failed_moving_file'];
        }

        KPS('+', $uploadsubtitleBonus, $curUser['id']);

        write_log($langName . ' Subtitle ' . $sub->id . ' (' . $title . ') was uploaded by ' . $anon);

        return null;
    }

    private function deleteSubtitle(Sub $sub, Request $request, array $curUser, array $lang, string $subsPath, float $uploadsubtitleBonus): ?string
    {
        $reason = (string) $request->input('reason', '');
        $filename = getFullDirectory("$subsPath/$sub->torrent_id/$sub->id.$sub->ext");
        do_log("Going to delete subtitle: $filename ...");

        if (! @unlink($filename)) {
            do_log("Delete subtitle: $filename fail.", 'error');

            return $lang['std_is_invalid'];
        }

        Sub::query()->where('id', $sub->id)->delete();
        KPS('-', $uploadsubtitleBonus, $sub->uppedby);

        if ($curUser['id'] != $sub->uppedby) {
            $locale = get_user_locale($sub->uppedby);
            $msg = $curUser['username'] . nexus_trans('subtitle.msg_deleted_your_sub', [], $locale) . $sub->title . ($reason != '' ? nexus_trans('subtitle.msg_reason_is', [], $locale) . $reason : '');
            $subject = nexus_trans('subtitle.msg_your_sub_deleted', [], $locale);
            Message::add([
                'sender' => 0,
                'receiver' => $sub->uppedby,
                'added' => now(),
                'msg' => $msg,
                'subject' => $subject,
            ]);
        }

        $langName = DB::table('language')->where('sub_lang', 1)->where('id', $sub->lang_id)->value('lang_name');
        $logName = ($sub->anonymous == 'yes' && $sub->uppedby == $curUser['id']) ? 'Anonymous' : $curUser['username'];
        $logName .= $sub->uppedby != $curUser['id'] ? ', Mod Delete' : '';
        $logName .= $reason != '' ? ' (' . $reason . ')' : '';
        write_log($langName . ' Subtitle ' . $sub->id . ' (' . $sub->title . ') was deleted by ' . $logName);

        return null;
    }

    private function failDeletePage(Sub $sub, string $errorSuffix, array $lang)
    {
        $content = $this->capture(function () use ($sub, $errorSuffix, $lang) {
            stdmsg($lang['std_error'], $lang['std_this_file'] . $sub->filename . $errorSuffix);
        });

        return view('subtitles', compact('content') + ['pageTitle' => $lang['head_subtitles']]);
    }

    private function deleteConfirmPage(Sub $sub, array $lang)
    {
        $content = $this->capture(function () use ($sub, $lang) {
            stdmsg($lang['std_delete_subtitle'], $lang['std_delete_subtitle_note'] . '<br /><form method="post" action="subtitles.php?delete=' . $sub->id . '&sure=1">' . $lang['text_reason_is'] . '<input type="text" style="width: 200px" name="reason"><input type="submit" value="' . $lang['submit_confirm'] . '"></form>');
        });

        return view('subtitles', compact('content') + ['pageTitle' => $lang['head_subtitles']]);
    }

    private function showPage(Request $request, array $curUser, array $lang, int $maxsubsize, string $inDetail, int $detailTorrentId, string $torrentName, ?string $uploadError)
    {
        $isPeasantPlus = get_user_class() >= User::CLASS_PEASANT;

        $search = trim((string) $request->query('search', ''));
        $letter = trim((string) $request->query('letter', ''));
        if (strlen($letter) > 1 || ($letter != '' && strpos('abcdefghijklmnopqrstuvwxyz', $letter) === false)) {
            $letter = '';
        }

        $langId = (int) $request->query('lang_id', 0);
        if (! is_valid_id($langId)) {
            $langId = 0;
        }

        $content = $this->capture(function () use ($lang, $maxsubsize, $isPeasantPlus, $search, $letter, $langId, $inDetail, $detailTorrentId, $torrentName, $uploadError) {
            if ($isPeasantPlus) {
                $this->renderUploadFrame($lang, $maxsubsize, $inDetail, $detailTorrentId, $torrentName, $uploadError);
            }

            if ($isPeasantPlus) {
                $this->renderList($lang, $search, $letter, $langId);
            }
        });

        return view('subtitles', compact('content') + ['pageTitle' => $lang['head_subtitles']]);
    }

    private function renderUploadFrame(array $lang, int $maxsubsize, string $inDetail, int $detailTorrentId, string $torrentName, ?string $uploadError)
    {
        begin_main_frame();

        if ($uploadError !== null) {
            stdmsg($lang['std_error'], $uploadError);
        }

        print('<div align="center">');
        if (! $size = $GLOBALS['Cache']->get_value('subtitle_sum_size')) {
            $size = (float) Sub::query()->sum('size');
            $GLOBALS['Cache']->cache_value('subtitle_sum_size', $size, 3600);
        }

        begin_frame($lang['text_upload_subtitles'] . mksize($size) . '', true, 10, '100%', 'center');
        print('</div>');

        print('<p align="left"><b><font size="5">' . $lang['text_rules'] . '</font></b></p>' . "\n");
        foreach (['text_rule_one', 'text_rule_two', 'text_rule_three', 'text_rule_four', 'text_rule_five', 'text_rule_six'] as $rule) {
            print('<p align="left">&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp ' . $lang[$rule] . '</p>' . "\n");
        }

        print($lang['text_red_star_required']);
        if ($inDetail != '') {
            print('<p>' . $lang['text_uploading_subtitles_for_torrent'] . '<b>' . htmlspecialchars($torrentName) . '</b></p>' . "\n");
            print('<br />');
        }

        print('<form enctype="multipart/form-data" method="post" action="?">' . "\n");
        print('<input type="hidden" name="action" value="upload">');
        print('<table class="main" border="1" cellspacing="0" cellpadding="5">' . "\n");

        print('<tr><td class="rowhead">' . $lang['row_file'] . '<font color="red">*</font></td><td class="rowfollow" align="left"><input type="file" name="file">');
        if ($maxsubsize > 0) {
            print('<br />(' . $lang['text_maximum_file_size'] . mksize($maxsubsize) . '.)');
        }
        print('</td></tr>' . "\n");

        if ($inDetail == '') {
            print('<tr><td class="rowhead">' . $lang['row_torrent_id'] . '<font color="red">*</font></td><td class="rowfollow" align="left"><input type="text" name="torrent_id" style="width:300px"><br />' . sprintf($lang['text_torrent_id_note'], getSchemeAndHttpHost()) . '</td></tr>' . "\n");
        } else {
            print('<tr><td class="rowhead">' . $lang['row_torrent_id'] . '<font color="red">*</font></td><td class="rowfollow" align="left"><input type="text" name="torrent_id" value="' . $detailTorrentId . '" style="width:300px"><br />' . $lang['text_torrent_id_note'] . '</td></tr>' . "\n");
        }

        print('<tr><td class="rowhead">' . $lang['row_title'] . '</td><td class="rowfollow" colspan="3" align="left"><input type="text" name="title" style="width:300px"><br />' . $lang['text_title_note'] . '</td></tr>' . "\n");

        print('<tr><td class="rowhead">' . $lang['row_language'] . '<font color="red">*</font></td><td class="rowfollow" align="left"><select name="sel_lang"><option value="0">' . $lang['select_choose_one'] . '</option>' . "\n");
        $langs = langlist('sub_lang');
        foreach ($langs as $row) {
            print('<option value="' . $row['id'] . '">' . htmlspecialchars($row['lang_name']) . '</option>' . "\n");
        }
        print('</select></td></tr>' . "\n");

        if (user_can('beanonymous')) {
            tr($lang['row_show_uploader'], '<input type="checkbox" name="uplver" value="yes">' . $lang['hide_uploader_note'], 1);
        }

        print('<tr><td class="toolbox" colspan="2" align="center"><input type="submit" class="btn" value="' . $lang['submit_upload_file'] . '"> <input type="reset" class="btn" value="' . $lang['submit_reset'] . '"></td></tr>' . "\n");
        print('</table>' . "\n");
        print('</form>' . "\n");
        end_frame();

        end_main_frame();
    }

    private function renderList(array $lang, string $search, string $letter, int $langId)
    {
        print('<form method="get" action="?">' . "\n");
        print('<br /><br />');
        print('<input type="text" style="width:200px" name="search">' . "\n");

        $s = '<select name="lang_id"><option value="0">' . $lang['select_all_languages'] . '</option>' . "\n";
        $langs = langlist('sub_lang');
        foreach ($langs as $row) {
            $s .= '<option value="' . $row['id'] . '">' . htmlspecialchars($row['lang_name']) . '</option>' . "\n";
        }
        $s .= '</select>';
        print($s);

        print('<input type="submit" class="btn" value="' . $lang['submit_search'] . '">' . "\n");
        print('</form>' . "\n");

        for ($i = 97; $i < 123; ++$i) {
            $l = chr($i);
            $L = chr($i - 32);
            if ($l == $letter) {
                print('<b><font class="gray">' . $L . '</font></b>' . "\n");
            } else {
                print('<a href="?letter=' . $l . '"><b>' . $L . '</b></a>' . "\n");
            }
        }

        $query = Sub::query();
        if ($search != '') {
            $query->where('title', 'like', '%' . $search . '%');
        } elseif ($letter != '') {
            $query->where('title', 'like', $letter . '%');
        }
        if ($langId) {
            $query->where('lang_id', $langId);
        }

        $num = (clone $query)->count();
        if (! $num) {
            stdmsg($lang['text_sorry'], $lang['text_nothing_here']);

            return;
        }

        $perpage = 30;
        $q = '';
        if ($search != '') {
            $q = 'search=' . rawurlencode($search);
        } elseif ($letter != '') {
            $q = 'letter=' . $letter;
        }
        if ($langId) {
            $q = ($q ? $q . '&amp;' : '') . 'lang_id=' . $langId;
        }

        list($pagertop, $pagerbottom, $limit) = pager($perpage, $num, 'subtitles.php?' . $q . '&');

        print($pagertop);

        preg_match('/limit (\d+) offset (\d+)/i', $limit, $limitMatches);
        $rows = $query
            ->leftJoin('language', 'subs.lang_id', '=', 'language.id')
            ->select(['subs.*', 'language.flagpic', 'language.lang_name'])
            ->orderByDesc('subs.id')
            ->limit((int) ($limitMatches[1] ?? $perpage))
            ->offset((int) ($limitMatches[2] ?? 0))
            ->get();

        print('<table width="940" border="1" cellspacing="0" cellpadding="5">' . "\n");
        print('<tr><td class="colhead">' . $lang['col_lang'] . '</td><td width="100%" class="colhead" align="center">' . $lang['col_title'] . '</td><td class="colhead" align="center"><img class="time" src="pic/trans.gif" alt="time" title="' . $lang['title_date_added'] . '" /></td>
		<td class="colhead" align="center"><img class="size" src="pic/trans.gif" alt="size" title="' . $lang['title_size'] . '" /></td><td class="colhead" align="center">' . $lang['col_hits'] . '</td><td class="colhead" align="center">' . $lang['col_upped_by'] . '</td><td class="colhead" align="center">' . $lang['col_report'] . '</td></tr>' . "\n");

        $mod = user_can('submanage');
        $pu = user_can('delownsub');
        $curUserId = (int) $GLOBALS['CURUSER']['id'];

        foreach ($rows as $arr) {
            $langCell = '<td class="rowfollow" align="center" valign="middle"><img border="0" src="pic/flag/' . $arr->flagpic . '" alt="' . $arr->lang_name . '" title="' . $arr->lang_name . '"/></td>' . "\n";
            $titleCell = '<td class="rowfollow" align="left"><a href="downloadsubs.php?torrentid=' . $arr->torrent_id . '&subid=' . $arr->id . '"' . '<b>' . htmlspecialchars($arr->title) . '</b></a>' .
                ($mod || ($pu && $arr->uppedby == $curUserId) ? ' <font class="small"><a href="?delete=' . $arr->id . '">' . $lang['text_delete'] . '</a></font>' : '') . '</td>' . "\n";
            $addtime = gettime($arr->added, false, false);
            $addedCell = '<td class="rowfollow" align="center"><nobr>' . $addtime . '</nobr></td>' . "\n";
            $sizeCell = '<td class="rowfollow" align="center">' . mksize_loose($arr->size) . '</td>' . "\n";
            $hitsCell = '<td class="rowfollow" align="center">' . number_format($arr->hits) . '</td>' . "\n";
            $uppedByCell = '<td class="rowfollow" align="center">' . ($arr->anonymous == 'yes' ? $lang['text_anonymous'] . (user_can('viewanonymous') ? '<br />' . get_username($arr->uppedby, false, true, true, false, true) : '') : get_username($arr->uppedby)) . '</td>' . "\n";
            $reportCell = '<td class="rowfollow" align="center"><a href="report.php?subtitle=' . $arr->id . '"><img class="f_report" src="pic/trans.gif" alt="Report" title="' . $lang['title_report_subtitle'] . '" /></a></td>' . "\n";
            print('<tr>' . $langCell . $titleCell . $addedCell . $sizeCell . $hitsCell . $uppedByCell . $reportCell . '</tr>' . "\n");
        }

        print('</table>' . "\n");
        print($pagerbottom);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}