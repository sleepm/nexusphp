<?php

namespace App\Http\Controllers;

use App\Auth\Permission;
use App\Exceptions\NexusException;
use App\Exceptions\TorrentExistedException;
use App\Http\Resources\SearchBoxResource;
use App\Http\Resources\TorrentResource;
use App\Models\Category;
use App\Models\Message;
use App\Models\Offer;
use App\Models\SearchBox;
use App\Models\Setting;
use App\Models\Torrent;
use App\Models\User;
use App\Repositories\HitAndRunRepository;
use App\Repositories\SearchBoxRepository;
use App\Repositories\TagRepository;
use App\Repositories\TorrentRepository;
use App\Repositories\UploadRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UploadController extends Controller
{
    private $repository;

    private $searchBoxRepository;

    public function __construct(UploadRepository $repository, SearchBoxRepository $searchBoxRepository)
    {
        $this->repository = $repository;
        $this->searchBoxRepository = $searchBoxRepository;
    }

    public function sections(Request $request)
    {
        $sections = $this->searchBoxRepository->listSections(SearchBox::listAuthorizedSectionId());
        $resource = SearchBoxResource::collection($sections);
        return $this->success($resource);
    }

    /**
     * Upload form page. Mirrors legacy public/upload.php so the page can be
     * served by the Laravel router instead of the procedural script.
     */
    public function web(Request $request)
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        if (! $currentUser) {
            abort(401);
        }
        if ($currentUser->parked == 'yes') {
            abort(403, 'Your account is parked.');
        }
        $curUser = $currentUser->toArray();

        $lang = get_legacy_lang_file('upload');
        $langEdit = get_legacy_lang_file('edit');
        $langFunctions = get_legacy_lang_file('functions');

        // legacy user-class constants are defined in include/core.php (not loaded
        // in the Laravel bootstrap); upload.php compares against them for pick/manage
        foreach ([
            'UC_PEASANT' => 0, 'UC_USER' => 1, 'UC_POWER_USER' => 2, 'UC_ELITE_USER' => 3,
            'UC_CRAZY_USER' => 4, 'UC_INSANE_USER' => 5, 'UC_VETERAN_USER' => 6,
            'UC_EXTREME_USER' => 7, 'UC_ULTIMATE_USER' => 8, 'UC_NEXUS_MASTER' => 9,
            'UC_VIP' => 10, 'UC_RETIREE' => 11, 'UC_UPLOADER' => 12, 'UC_MODERATOR' => 13,
            'UC_ADMINISTRATOR' => 14, 'UC_SYSOP' => 15, 'UC_STAFFLEADER' => 16,
        ] as $constant => $value) {
            defined($constant) || define($constant, $value);
        }

        // globals the shared legacy helpers expect (mirrors public/upload.php bootstrap)
        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_upload'] = $lang;
        $GLOBALS['lang_functions'] = $langFunctions;
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();
        $GLOBALS['enableoffer'] = get_setting('main.showoffer', 'no');
        $GLOBALS['enablespecial'] = get_setting('main.spsct', 'no');
        $GLOBALS['showextinfo'] = ['imdb' => get_setting('main.showimdbinfo', 'no')];
        $GLOBALS['torrent_dir'] = get_setting('main.torrent_dir', '');
        $GLOBALS['max_torrent_size'] = (int) get_setting('main.max_torrent_size', 0);
        $GLOBALS['altname_main'] = get_setting('main.altname', 'no');
        $GLOBALS['smalldescription_main'] = get_setting('main.smalldescription', 'yes');
        $GLOBALS['enablenfo_main'] = get_setting('main.enablenfo', 'no');
        $GLOBALS['viewnfo_class'] = (int) get_setting('authority.viewnfo', 0);
        $GLOBALS['browsecatmode'] = (int) get_setting('main.browsecat', 0);
        $GLOBALS['specialcatmode'] = (int) get_setting('main.specialcat', 0);

        if ($curUser['uploadpos'] == 'no') {
            abort(403, $lang['std_sorry'] . $lang['std_unauthorized_to_upload']);
        }

        if ($GLOBALS['enableoffer'] == 'yes') {
            $hasAllowedOffer = (int) DB::table('offers')
                ->where('allowed', 'allowed')
                ->where('userid', $curUser['id'])
                ->count();
        } else {
            $hasAllowedOffer = 0;
        }
        $uploadfreely = user_can_upload('torrents');
        $allowtorrents = ($hasAllowedOffer || $uploadfreely);
        $allowspecial = user_can_upload('music');

        if (! $allowtorrents && ! $allowspecial) {
            abort(403, $lang['std_sorry'] . $lang['std_please_offer']);
        }
        $allowtwosec = ($allowtorrents && $allowspecial);

        $brsectiontype = $GLOBALS['browsecatmode'];
        $spsectiontype = $GLOBALS['specialcatmode'];

        $settingMain = get_setting('main');
        $torrentRep = new TorrentRepository();
        $searchBoxRep = new SearchBoxRepository();
        $tagRep = new TagRepository();

        $pageTitle = $lang['head_upload'];
        $content = '';
        $scripts = [];

        $content .= '<form id="compose" enctype="multipart/form-data" action="takeupload.php" method="post" name="upload">';
        $content .= '<p align="center">' . $lang['text_red_star_required'] . "</p>\n";
        $content .= '<table border="1" cellspacing="0" cellpadding="5" width="97%">';
        $content .= '<tr><td class="colhead" colspan="2" align="center">';
        $content .= $lang['text_tracker_url'] . ':&nbsp;&nbsp;&nbsp;&nbsp;<b>' . get_tracker_schema_and_host((int) ($curUser['tracker_url_id'] ?? 0), true) . '</b>';
        if (! is_writable(getFullDirectory($GLOBALS['torrent_dir']))) {
            $content .= "<br /><br /><b>ATTENTION</b>: Torrent directory isn't writable. Please contact the administrator about this problem!";
        }
        if (! $GLOBALS['max_torrent_size']) {
            $content .= "<br /><br /><b>ATTENTION</b>: Max. Torrent Size not set. Please contact the administrator about this problem!";
        }
        $content .= "</td></tr>\n";

        $content .= $this->row($lang['row_torrent_file'] . '<font color="red">*</font>', "<input type=\"file\" class=\"file\" id=\"torrent\" name=\"file\" onchange=\"getname()\" />\n", 1);

        if ($GLOBALS['altname_main'] == 'yes') {
            $content .= $this->row($lang['row_torrent_name'], '<b>' . $lang['text_english_title'] . '</b>&nbsp;<input type="text" style="width: 250px;" name="name" />&nbsp;&nbsp;&nbsp;
<b>' . $lang['text_chinese_title'] . '</b>&nbsp;<input type="text" style="width: 250px" name="cnname"><br /><font class="medium">' . $lang['text_titles_note'] . '</font>', 1);
        } else {
            $autoFillText = $lang['fill_quality'];
            $nameInput = $torrentRep->buildUploadFieldInput('name', '', $lang['text_torrent_name_note'], $autoFillText);
            $content .= $this->row($lang['row_torrent_name'], $nameInput, 1);
        }

        if ($GLOBALS['smalldescription_main'] == 'yes') {
            $content .= $this->row($lang['row_small_description'], '<input type="text" style="width: 99%;" name="small_descr" /><br /><font class="medium">' . $lang['text_small_description_note'] . '</font>', 1);
        }
        $content .= $this->capture(function () {
            get_external_tr();
        });
        if ($settingMain['enable_pt_gen_system'] == 'yes') {
            $ptGen = new \Nexus\PTGen\PTGen();
            $content .= $ptGen->renderUploadPageFormInput('');
        }
        if ($GLOBALS['enablenfo_main'] == 'yes') {
            $content .= $this->row($lang['row_nfo_file'], '<input type="file" class="file" name="nfo" /><br /><font class="medium">' . $lang['text_only_viewed_by'] . get_user_class_name($GLOBALS['viewnfo_class'], false, true, true) . $lang['text_or_above'] . '</font>', 1);
        }
        // price
        if (user_can('torrent-set-price') && get_setting('torrent.paid_torrent_enabled') == 'yes') {
            $maxPrice = get_setting('torrent.max_price');
            $pricePlaceholder = '';
            if ($maxPrice > 0) {
                $pricePlaceholder = nexus_trans('label.torrent.max_price_help', ['max_price' => $maxPrice]);
            }
            $content .= $this->row(nexus_trans('label.torrent.price'), '<input type="number" min="0" name="price" placeholder="' . $pricePlaceholder . '" />&nbsp;&nbsp;' . nexus_trans('label.torrent.price_help', ['tax_factor' => (floatval(get_setting('torrent.tax_factor', 0)) * 100) . '%']), 1);
        }

        $content .= $this->capture(function () use ($lang) {
            print('<tr><td class="rowhead" style="padding: 3px" valign="top">' . $lang['row_description'] . '<font color="red">*</font></td><td class="rowfollow">');
            textbbcode('upload', 'descr', '', false, 130, true);
            print("</td></tr>\n");
        });

        if ($settingMain['enable_technical_info'] == 'yes') {
            $content .= $this->row($langFunctions['text_technical_info'], '<textarea name="technical_info" rows="8" style="width: 99%;"></textarea><br/>' . $langFunctions['text_technical_info_help_text'], 1);
        }

        $s = '';
        $s2 = '';
        if ($allowtorrents) {
            $disablespecial = " onchange=\"disableother('browsecat','specialcat')\"";
            $s = '<select name="type" id="browsecat" data-mode="' . $brsectiontype . '"' . ($allowtwosec ? $disablespecial : '') . ">\n<option value=\"0\">" . $lang['select_choose_one'] . "</option>\n";
            foreach (genrelist($brsectiontype) as $catRow) {
                $s .= '<option value="' . $catRow['id'] . '">' . htmlspecialchars($catRow['name']) . "</option>\n";
            }
            $s .= "</select>\n";
        }
        if ($allowspecial) {
            $disablebrowse = " onchange=\"disableother('specialcat','browsecat')\"";
            $s2 = '<select name="type" id="specialcat" data-mode="' . $spsectiontype . '"' . $disablebrowse . ">\n<option value=\"0\">" . $lang['select_choose_one'] . "</option>\n";
            foreach (genrelist($spsectiontype) as $catRow2) {
                $s2 .= '<option value="' . $catRow2['id'] . '">' . htmlspecialchars($catRow2['name']) . "</option>\n";
            }
            $s2 .= "</select>\n";
        }
        $content .= $this->row($lang['row_type'] . '<font color="red">*</font>', ($allowtwosec ? $lang['text_to_browse_section'] : '') . $s . ($allowtwosec ? $lang['text_to_special_section'] : '') . $s2 . ($allowtwosec ? $lang['text_type_note'] : ''), 1);

        $customField = new \Nexus\Field\Field();
        $hitAndRunRep = new HitAndRunRepository();
        if ($allowtorrents) {
            $selectNormal = $searchBoxRep->renderTaxonomySelect($brsectiontype);
            $content .= $this->row($lang['row_quality'], $selectNormal, 1, 'mode_' . $brsectiontype);
            $content .= $customField->renderOnUploadPage(0, $brsectiontype);
            $content .= $hitAndRunRep->renderOnUploadPage('', $brsectiontype);
            $content .= $this->row($langFunctions['text_tags'], $tagRep->renderCheckbox($brsectiontype), 1, 'mode_' . $brsectiontype);
        }
        if ($allowspecial) {
            $selectNormal = $searchBoxRep->renderTaxonomySelect($spsectiontype);
            $content .= $this->row($lang['row_quality'], $selectNormal, 1, 'mode_' . $spsectiontype);
            $content .= $customField->renderOnUploadPage(0, $spsectiontype);
            $content .= $hitAndRunRep->renderOnUploadPage('', $spsectiontype);
            $content .= $this->row($langFunctions['text_tags'], $tagRep->renderCheckbox($spsectiontype), 1, 'mode_' . $spsectiontype);
        }

        // offer dropdown for offer mod
        $offerRows = DB::table('offers')
            ->select('id', 'name')
            ->where('userid', $curUser['id'])
            ->where('allowed', 'allowed')
            ->orderBy('name')
            ->get();
        if ($offerRows->isNotEmpty()) {
            $offer = '<select name="offer"><option value="0">' . $lang['select_choose_one'] . '</option>';
            foreach ($offerRows as $offerRow) {
                $offer .= '<option value="' . $offerRow->id . '">' . htmlspecialchars($offerRow->name) . '</option>';
            }
            $offer .= '</select>';
            $content .= $this->row($lang['row_your_offer'] . (! $uploadfreely && ! $allowspecial ? '<font color=red>*</font>' : ''), $offer . $lang['text_please_select_offer'], 1);
            $scripts[] = <<<JS
jQuery('select[name="offer"]').on("change", function () {
    let id = this.value
    if (id == 0) {
        return
    }
    let params = {action: "getOffer", params: {id: id}}
    jQuery.post("ajax.php", params, function (response) {
        console.log(response)
        if (response.ret != 0) {
            alert(response.msg)
            return
        }
        jQuery("#name").val(response.data.name)
        clearContent()
        doInsert(response.data.descr, '', false)
        jQuery("#specialcat").prop('disabled', false).val(0)
        jQuery("#browsecat").prop('disabled', false).val(response.data.category)
    }, 'json')
})
JS;
        }

        // pick
        $pickcontent = '';
        if (user_can('torrentsticky')) {
            $options = [];
            foreach (Torrent::listPosStates() as $posKey => $posValue) {
                $options[] = '<option value="' . $posKey . '">' . $posValue['text'] . '</option>';
            }
            $pickcontent .= '<b>' . $langEdit['row_torrent_position'] . ":&nbsp;</b>" . '<select name="pos_state" style="width: 100px;">' . implode('', $options) . '</select>&nbsp;&nbsp;&nbsp;';
            $pickcontent .= datetimepicker_input('pos_state_until', '', nexus_trans('label.deadline') . ':&nbsp;', ['require_files' => true]);
        }
        if (user_can('torrentmanage') && ($curUser['picker'] == 'yes' || get_user_class() >= User::CLASS_SYSOP)) {
            if ($pickcontent) {
                $pickcontent .= '<br />';
            }
            $pickcontent .= '<b>' . $langEdit['row_recommended_movie'] . ":&nbsp;</b>" . '<select name="picktype" style="width: 100px;">';
            foreach (Torrent::listPickInfo(true) as $_pick_type => $_pick_type_text) {
                $pickcontent .= sprintf('<option value="%s">%s</option>', $_pick_type, $_pick_type_text);
            }
            $pickcontent .= '</select>';
        }
        if ($pickcontent) {
            $content .= $this->row($langEdit['row_pick'], $pickcontent, 1);
        }

        if (user_can('beanonymous')) {
            $content .= $this->row($lang['row_show_uploader'], '<input type="checkbox" name="uplver" value="yes" />' . $lang['checkbox_hide_uploader_note'], 1);
        }
        $content .= '<tr><td class="toolbox" align="center" colspan="2"><b>' . $lang['text_read_rules'] . '</b> <input id="qr" type="submit" class="btn" value="' . $lang['submit_upload'] . '" /></td></tr>';
        $content .= "</table>\n</form>\n";

        $scripts[] = <<<JS
jQuery("#compose").on("change", "select[name=type]", function () {
    let _this = jQuery(this);
    let mode = _this.attr("data-mode");
    let value = _this.val();
    console.log(mode)
    jQuery("tr[relation]").hide();
    if (value > 0) {
        jQuery("tr[relation=mode_" + mode +"]").show();
    }
})
jQuery("tr[relation]").hide();
JS;

        $externalScripts = [
            'vendor/jquery-loading/jquery.loading.min.js',
            'js/ptgen.js',
        ];

        // JS/CSS assets registered through \Nexus\Nexus::js/css() (e.g. the datetimepicker)
        // are normally flushed by stdfoot()/stdhead(); under the Blade layout we push them here.
        $headerAssets = implode("\n", \Nexus\Nexus::getAppendHeaders());
        $footerAssets = implode("\n", \Nexus\Nexus::getAppendFooters());

        return view('upload.upload', compact('lang', 'pageTitle', 'content', 'scripts', 'externalScripts', 'headerAssets', 'footerAssets'));
    }

    /**
     * Upload submission. Mirrors legacy public/takeupload.php so the form
     * rendered by UploadController::web (which posts to takeupload.php) keeps
     * working under the Laravel router instead of the procedural script.
     */
    public function webTakeUpload(Request $request)
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        if (! $currentUser) {
            abort(401);
        }
        $curUser = $currentUser->toArray();
        if ($curUser['uploadpos'] == 'no') {
            abort(403, nexus_trans('upload.unauthorized_upload_freely'));
        }

        // globals the shared legacy helpers expect (mirrors public/upload.php bootstrap)
        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_takeupload'] = get_legacy_lang_file('takeupload');
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        foreach (['descr', 'type', 'name'] as $v) {
            if (! $request->has($v)) {
                return $this->uploadFailed(nexus_trans('upload.missing_form_data'));
            }
        }
        if (! $request->hasFile('file')) {
            return $this->uploadFailed(nexus_trans('upload.missing_form_data'));
        }

        $catid = (int) $request->input('type', 0);
        if (! is_valid_id($catid)) {
            return $this->uploadFailed(nexus_trans('upload.category_unselected'));
        }
        $category = Category::query()->find($catid);
        if (! $category) {
            return $this->uploadFailed(nexus_trans('upload.invalid_category'));
        }
        $catmod = (int) $category->mode;

        // legacy form posts the quality/tag/HR fields keyed by section mode
        $this->mergeLegacySectionFields($request, $catmod);

        // legacy derives the torrent name from the .torrent filename when blank
        if (trim((string) $request->input('name', '')) === '') {
            $fileName = (string) $request->file('file')->getClientOriginalName();
            if (preg_match('/^(.+)\.torrent$/si', $fileName, $matches)) {
                $request->merge(['name' => $matches[1]]);
            }
        }

        // uploading for an allowed offer (browse section only, mirrors takeupload.php)
        $offerId = 0;
        $voteUserIds = [];
        if ($catmod == (int) get_setting('main.browsecat', 0)) {
            $offerId = (int) $request->input('offer', 0);
            if ($offerId) {
                $offerValid = (get_setting('main.showoffer', 'no') == 'yes')
                    && Offer::query()
                        ->where('id', $offerId)
                        ->where('userid', $currentUser->id)
                        ->where('allowed', 'allowed')
                        ->exists();
                if (! $offerValid) {
                    return $this->uploadFailed(nexus_trans('upload.uploaded_not_offered'));
                }
                $voteUserIds = DB::table('offervotes')
                    ->where('offerid', $offerId)
                    ->where('userid', '!=', $currentUser->id)
                    ->where('vote', 'yeah')
                    ->pluck('userid')
                    ->all();
            }
        }

        try {
            $newTorrent = $this->repository->upload($request);
        } catch (TorrentExistedException $exception) {
            return redirect(sprintf('details.php?id=%d&existed=1', $exception->getTorrentId()));
        } catch (NexusException $exception) {
            do_log(sprintf("takeupload fail: %s", $exception->getMessage()), 'error');
            return $this->uploadFailed($exception->getMessage());
        }

        $id = $newTorrent->id;

        // custom fields
        $customFieldValues = $request->input("custom_fields.{$catmod}", []);
        if (! empty($customFieldValues)) {
            (new \Nexus\Field\Field())->saveFieldValues($catmod, $id, $customFieldValues);
        }

        // finish the offer: notify voters and clean it up
        if ($offerId) {
            $this->finishOffer($voteUserIds, $offerId, $currentUser, $newTorrent);
        }

        return redirect(get_protocol_prefix() . Setting::getBaseUrl() . '/details.php?id=' . $id . '&uploaded=1');
    }

    /**
     * Render a takeupload failure the way the legacy bark() did.
     */
    private function uploadFailed(string $message)
    {
        return redirect(url('/error?error=' . urlencode($message)));
    }

    /**
     * Translate the legacy form's section-mode-keyed fields into the flat field
     * names the UploadRepository expects.
     */
    private function mergeLegacySectionFields(Request $request, int $catmod): void
    {
        $merge = [];
        foreach (['source', 'medium', 'codec', 'audiocodec', 'standard', 'processing', 'team'] as $field) {
            $merge[$field] = (int) $request->input("{$field}_sel.{$catmod}", 0);
        }
        $tags = $request->input("tags.{$catmod}", []);
        if (is_array($tags)) {
            $merge['tags'] = array_values(array_filter($tags, fn ($v) => $v !== '' && $v !== null));
        }
        $hrArr = (array) $request->input('hr', []);
        if (isset($hrArr[$catmod])) {
            $merge['hr'] = (int) $hrArr[$catmod];
        }
        if ($request->has('picktype') && ! $request->has('pick_type')) {
            $merge['pick_type'] = $request->input('picktype');
        }
        $merge['small_descr'] = (string) $request->input('small_descr', '');
        $merge['url'] = (string) $request->input('url', '');
        $request->merge($merge);
    }

    /**
     * Notify users who voted "yeah" on an offer that its torrent is uploaded,
     * then remove the offer (mirrors legacy takeupload.php).
     */
    private function finishOffer(array $voteUserIds, int $offerId, User $uploader, Torrent $torrent): void
    {
        $baseUrl = get_protocol_prefix() . Setting::getBaseUrl();
        foreach ($voteUserIds as $userId) {
            $locale = get_user_locale($userId);
            $pnMsg = nexus_trans('torrent.msg_offer_you_voted', [], $locale)
                . $torrent->name
                . nexus_trans('torrent.msg_was_uploaded_by', [], $locale)
                . $uploader->username
                . nexus_trans('torrent.msg_you_can_download', [], $locale)
                . sprintf('[url=%s/details.php?id=%d&hit=1]', $baseUrl, $torrent->id)
                . nexus_trans('torrent.msg_here', [], $locale)
                . '[/url]';
            $subject = nexus_trans('torrent.msg_offer', [], $locale)
                . $torrent->name
                . nexus_trans('torrent.msg_was_just_uploaded', [], $locale);
            Message::add([
                'sender' => 0,
                'subject' => $subject,
                'receiver' => $userId,
                'added' => now()->toDateTimeString(),
                'msg' => $pnMsg,
            ]);
        }
        DB::table('offers')->where('id', $offerId)->delete();
        DB::table('offervotes')->where('offerid', $offerId)->delete();
        DB::table('comments')->where('offer', $offerId)->delete();
        User::query()->where('id', $uploader->id)->increment('offer_allowed_count');
    }

    /**
     * Render a single table row via the legacy tr() helper but return it as a
     * string instead of printing it.
     */
    private function row(string $x, string $y, int $noesc = 0, string $relation = ''): string
    {
        return (string) tr($x, $y, $noesc, $relation, true);
    }

    /**
     * Capture legacy helpers that print directly (get_external_tr, textbbcode, ...).
     */
    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}