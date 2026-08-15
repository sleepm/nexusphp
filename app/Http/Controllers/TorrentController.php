<?php

namespace App\Http\Controllers;

use App\Http\Resources\RewardResource;
use App\Http\Resources\TorrentOperationLogResource;
use App\Http\Resources\TorrentResource;
use App\Models\Claim;
use App\Models\Setting;
use App\Models\Torrent;
use App\Models\TorrentBuyLog;
use App\Models\TorrentDenyReason;
use App\Models\TorrentOperationLog;
use App\Models\TorrentTag;
use App\Models\User;
use App\Repositories\SearchBoxRepository;
use App\Repositories\TagRepository;
use App\Repositories\TorrentRepository;
use App\Repositories\UploadRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TorrentController extends Controller
{
    private $repository;

    public function __construct(TorrentRepository $repository)
    {
        $this->repository = $repository;
    }

    public function index(Request $request, string $section = null)
    {
        do_log("controller torrent index entry");
        $result = $this->repository->getList($request, Auth::user(), $section);
        do_log("controller torrent index getList");
        $resource = TorrentResource::collection($result);
        do_log("controller torrent index prepare resource");
        return $this->success($resource);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function store(Request $request)
    {
        $uploadRep = new UploadRepository();
        $newTorrent = $uploadRep->upload($request);
        $resource = new JsonResource(["id" => $newTorrent->id]);
        return $this->success($resource);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return array
     */
    public function show($id)
    {
        do_log("controller torrent show entry");
        /**
         * @var User
         */
        $user = Auth::user();
        $torrent = $this->repository->getDetail($id, $user);
        do_log("controller torrent show getDetail");
        $resource = new TorrentResource($torrent);
        $additional = [];
        if ($this->hasExtraField('bonus_reward_values')) {
            $additional['bonus_reward_values'] = Setting::getBonusRewardOptions();
        }
        $extraSettingsNames = ['torrent.claim_torrent_user_counts_up_limit'];
        $this->appendExtraSettings($additional, $extraSettingsNames);
        $resource->additional($additional);
        do_log("controller torrent show prepare resource");
        return $this->success($resource);
    }

    /**
     * Torrent details page. Mirrors legacy public/details.php so the page can
     * be served by the Laravel router instead of the procedural script.
     */
    public function web(Request $request)
    {
        /** @var User $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        $curUser = $currentUser->toArray();

        $id = (int) $request->input('id');
        int_check($id, true);
        if (!$id) {
            return response('', 200);
        }

        $lang = get_legacy_lang_file('details');
        $langFunctions = get_legacy_lang_file('functions');

        // globals the shared legacy helpers expect (mirrors public/details.php bootstrap)
        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = $langFunctions;
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();
        $GLOBALS['waitsystem'] = get_setting('main.waitsystem', 'no');
        $GLOBALS['showextinfo'] = ['imdb' => get_setting('main.showimdbinfo', 'no')];
        $GLOBALS['torrentmanage_class'] = get_setting('authority.torrentmanage', '');
        $GLOBALS['smalldescription_main'] = get_setting('main.smalldescription', 'yes');
        $GLOBALS['enabletooltip_tweak'] = get_setting('tweak.enabletooltip', 'no');
        $GLOBALS['staffmem_class'] = get_setting('authority.staffmem', '');
        $GLOBALS['expirehalfleech_torrent'] = get_setting('torrent.expirehalfleech');
        $GLOBALS['expirefree_torrent'] = get_setting('torrent.expirefree');
        $GLOBALS['expiretwoup_torrent'] = get_setting('torrent.expiretwoup');
        $GLOBALS['expiretwoupfree_torrent'] = get_setting('torrent.expiretwoupfree');
        $GLOBALS['expiretwouphalfleech_torrent'] = get_setting('torrent.expiretwouphalfleech');
        $GLOBALS['expirethirtypercentleech_torrent'] = get_setting('torrent.expirethirtypercentleech');
        $GLOBALS['commanage_class'] = (int) get_setting('authority.commanage', 0);
        $GLOBALS['specialcatmode'] = (int) get_setting('main.specialcat', 0);
        if (empty($GLOBALS['Advertisement'])) {
            require_once ROOT_PATH . 'classes/class_advertisement.php';
            $GLOBALS['Advertisement'] = new \ADVERTISEMENT($currentUser->id);
        }
        /** @var \class_cache_redis $Cache */
        $Cache = $GLOBALS['Cache'];

        $customField = new \Nexus\Field\Field();
        $settingMain = get_setting('main');
        $torrentnameprefix = get_setting('main.torrentnameprefix', '[Nexus]');

        $row = $this->buildDetailsRow($id);
        if (!$row) {
            abort(404, $lang['std_no_torrent_id']);
        }
        if (user_can('torrentmanage') || $currentUser->id == $row['owner']) {
            $owned = 1;
        } else {
            $owned = 0;
        }

        if (
            ($row['banned'] == 'yes' && !user_can('seebanned') && $row['owner'] != $currentUser->id)
            || (!can_access_torrent($row, $currentUser->id) && $row['owner'] != $currentUser->id)
        ) {
            abort(403, $langFunctions['std_permission_denied']);
        }

        $row = apply_filter('torrent_detail', $row);
        $owner = User::query()->find($row['owner']);
        if (!$owner) {
            $owner = User::defaultUser();
        }
        $torrentRep = new TorrentRepository();
        $searchBoxRep = new SearchBoxRepository();

        $torrentUpdate = [];
        if (!empty($request->query('hit'))) {
            $torrentUpdate[] = 'views = views + 1';
        }

        $imdbId = parse_imdb_id($row['url'] ?? '');
        $movie = null;
        $imdb = null;
        if ($imdbId && $GLOBALS['showextinfo']['imdb'] == 'yes') {
            $imdb = new \Nexus\Imdb\Imdb();
            $movie = $imdb->getMovie($imdbId);
        }

        $content = '';
        $scripts = [];
        $headerRefresh = null;

        if (!$request->query('cmtpage')) {
            $pageTitle = $lang['head_details_for_torrent'] . '"' . $row['name'] . '"';
            if (!empty($request->query('uploaded'))) {
                $content .= '<h1 align="center">' . $lang['text_successfully_uploaded'] . '</h1>';
                $content .= '<p>' . $lang['text_redownload_torrent_note'] . '</p>';
                $headerRefresh = '1; url=download.php?id=' . $id;
            } elseif (!empty($request->query('edited'))) {
                $content .= '<h1 align="center">' . $lang['text_successfully_edited'] . '</h1>';
                if (!empty($request->query('returnto'))) {
                    $content .= '<p><b>' . $lang['text_go_back'] . '<a href="' . htmlspecialchars($request->query('returnto')) . '">' . $lang['text_whence_you_came'] . '</a></b></p>';
                }
            } elseif (!empty($request->query('existed'))) {
                $content .= '<h1 align="center" style="color: red">' . $lang['torrent_existed'] . '</h1>';
                if (!empty($request->query('returnto'))) {
                    $content .= '<p><b>' . $lang['text_go_back'] . '<a href="' . htmlspecialchars($request->query('returnto')) . '">' . $lang['text_whence_you_came'] . '</a></b></p>';
                }
            }

            $bannedTorrent = ($row['banned'] == 'yes' ? ' <b>(<font class="striking">' . $langFunctions['text_banned'] . '</font>)</b>' : '');
            $spTorrent = get_torrent_promotion_append($row['sp_state'], 'word', false, '', 0, '', $row['__ignore_global_sp_state'] ?? false);
            $spTorrentSub = get_torrent_promotion_append_sub($row['sp_state'], '', true, $row['added'], $row['promotion_time_type'], $row['promotion_until'], $row['__ignore_global_sp_state'] ?? false);
            $hrImg = get_hr_img($row, $row['search_box_id']);
            $approvalStatusIcon = $torrentRep->renderApprovalStatus($row['approval_status']);
            $paidIcon = $torrentRep->getPaidIcon($row, 20);
            $s = htmlspecialchars($row['name']) . $bannedTorrent . $paidIcon . ($spTorrent ? '&nbsp;&nbsp;&nbsp;' . $spTorrent : '') . $spTorrentSub . $hrImg . $approvalStatusIcon;
            $content .= '<h1 align="center" id="top">' . $s . '</h1>' . "\n";

            if ($row['approval_status'] == Torrent::APPROVAL_STATUS_DENY) {
                $torrentOperationLog = TorrentOperationLog::query()
                    ->where('torrent_id', $row['id'])
                    ->where('action_type', TorrentOperationLog::ACTION_TYPE_APPROVAL_DENY)
                    ->orderBy('id', 'desc')
                    ->first();
                if ($torrentOperationLog) {
                    $dangerIcon = '<svg t="1655242121471" class="icon" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="46590" width="16" height="16"><path d="M963.555556 856.888889a55.978667 55.978667 0 0 1-55.978667 56.007111c-0.284444 0-0.540444-0.085333-0.824889-0.085333l-0.056889 0.085333H110.734222l-0.654222-1.137778A55.409778 55.409778 0 0 1 56.888889 856.462222c0-9.756444 2.730667-18.773333 7.139555-26.737778l-3.726222-6.599111L453.461333 156.302222A59.335111 59.335111 0 0 1 510.236444 113.777778c26.936889 0 49.436444 18.005333 56.803556 42.552889l389.973333 661.447111-3.669333 6.997333c6.4 9.102222 10.211556 20.138667 10.211556 32.113778z m-497.777778-541.326222l16.014222 312.888889h56.888889l16.014222-312.888889h-88.917333z m44.458666 398.222222a56.888889 56.888889 0 1 0-0.028444 113.749333 56.888889 56.888889 0 0 0 0.028444-113.749333z" p-id="46591" fill="#d81e06" data-spm-anchor-id="a313x.7781069.0.i61" class="selected"></path></svg>';
                    $content .= sprintf(
                        '<div style="display: flex; justify-content: center;margin-bottom: 10px"><div style="display: flex;background-color: black; color: white;font-weight: bold; padding: 10px 100px">%s&nbsp;%s</div></div>',
                        $dangerIcon, nexus_trans('torrent.approval.deny_comment_show', ['reason' => $torrentOperationLog->comment])
                    );
                }
            }

            $content .= '<table width="97%" cellspacing="0" cellpadding="5">' . "\n";

            $url = 'edit.php?id=' . $row['id'];
            if (!empty($request->query('returnto'))) {
                $url .= '&returnto=' . rawurlencode($request->query('returnto'));
            }
            $editlink = 'a title="' . $lang['title_edit_torrent'] . '" href="' . $url . '"';

            // upped by
            if ($row['anonymous'] == 'yes') {
                if (!user_can('viewanonymous') && $row['owner'] != $currentUser->id) {
                    $uprow = '<i>' . $lang['text_anonymous'] . '</i>';
                } else {
                    $uprow = '<i>' . $lang['text_anonymous'] . '</i> (' . get_username($row['owner'], false, true, true, false, false, true) . ')';
                }
            } else {
                $uprow = (isset($row['owner']) ? get_username($row['owner'], false, true, true, false, false, true) : '<i>' . ($lang['text_unknown'] ?? 'Unknown') . '</i>');
            }
            if ($currentUser->id == $row['owner']) {
                $curUser['downloadpos'] = 'yes';
            }
            $GLOBALS['CURUSER'] = $curUser;

            if ($curUser['downloadpos'] != 'no') {
                if ($curUser['timetype'] != 'timealive') {
                    $uploadtime = $lang['text_at'] . $row['added'];
                } else {
                    $uploadtime = $lang['text_blank'] . gettime($row['added'], true, false);
                }
                $content .= $this->row(
                    $lang['row_download'],
                    '<a class="index" href="download.php?id=' . $id . '">' . htmlspecialchars($torrentnameprefix . '.' . $row['save_as']) . '.torrent</a>'
                    . '&nbsp;&nbsp;<a id="bookmark0" href="javascript: bookmark(' . $row['id'] . ',0);">' . get_torrent_bookmark_state($curUser['id'], $row['id'], false) . '</a>'
                    . '&nbsp;&nbsp;&nbsp;' . $lang['row_upped_by'] . '&nbsp;' . $uprow . $uploadtime,
                    1
                );
            } else {
                $content .= $this->row($lang['row_download'], $lang['text_downloading_not_allowed']);
            }

            if ($GLOBALS['smalldescription_main'] == 'yes') {
                $content .= $this->row($lang['row_small_description'], htmlspecialchars(trim((string) $row['small_descr'])), true);
            }

            $torrentTags = TorrentTag::query()->where('torrent_id', $row['id'])->get();
            if ($torrentTags->isNotEmpty()) {
                $tagRep = new TagRepository();
                $content .= $this->row($lang['row_tags'], $tagRep->renderSpan($row['search_box_id'], $torrentTags->pluck('tag_id')->toArray()), true);
            }

            $sizeInfo = '<b>' . $lang['text_size'] . '</b>' . mksize($row['size']);
            $typeInfo = '&nbsp;&nbsp;&nbsp;<b>' . $lang['row_type'] . ':</b>&nbsp;' . $row['cat_name'];
            $taxonomyInfo = $searchBoxRep->listTaxonomyInfo($row['search_box_id'], $row);
            $taxonomyRendered = '';
            foreach ($taxonomyInfo as $item) {
                $taxonomyRendered .= sprintf('&nbsp;&nbsp;&nbsp;<b>%s: </b>%s', $item['label'], $item['value']);
            }
            $content .= $this->row($lang['row_basic_info'], $sizeInfo . $typeInfo . $taxonomyRendered, 1);

            // actions
            $actions = [];
            if ($curUser['downloadpos'] != 'no') {
                $hasBuy = TorrentBuyLog::query()->where('uid', $curUser['id'])->where('torrent_id', $id)->exists();
                if ($row['price'] > 0) {
                    $downloadBtn = $hasBuy ? $lang['text_download_bought_torrent'] : sprintf($lang['text_download_paid_torrent'], number_format($row['price']));
                } else {
                    $downloadBtn = $lang['text_download_torrent'];
                }
                $actions[] = '<a title="' . $lang['title_download_torrent'] . '" href="download.php?id=' . $id . '"><img class="dt_download" src="pic/trans.gif" alt="download" />&nbsp;<b><font class="small">' . $downloadBtn . '</font></b></a>';
            }
            if ($owned == 1) {
                $actions[] = '<' . $editlink . '><img class="dt_edit" src="pic/trans.gif" alt="edit" />&nbsp;<b><font class="small">' . (user_can('torrentmanage') ? $lang['text_edit_and_delete_torrent'] : $lang['text_edit_torrent']) . '</font></b></a>';
            }
            if (user_can('askreseed') && $row['seeders'] == 0) {
                $actions[] = '<a title="' . $lang['title_ask_for_reseed'] . '" href="takereseed.php?reseedid=' . $id . '"><img class="dt_reseed" src="pic/trans.gif" alt="reseed">&nbsp;<b><font class="small">' . $lang['text_ask_for_reseed'] . '</font></b></a>';
            }
            if (user_can('torrent-approval') && (get_setting('torrent.approval_status_icon_enabled') == 'yes' || get_setting('torrent.approval_status_none_visible') == 'no')) {
                $approvalIcon = '<svg t="1655224943277" class="icon" viewBox="0 0 1397 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="45530" width="16" height="16"><path d="M1396.363636 121.018182c0 0-223.418182 74.472727-484.072727 372.363636-242.036364 269.963636-297.890909 381.672727-390.981818 530.618182C512 1014.690909 372.363636 744.727273 0 549.236364l195.490909-186.181818c0 0 176.872727 121.018182 297.890909 344.436364 0 0 307.2-474.763636 902.981818-707.490909L1396.363636 121.018182 1396.363636 121.018182zM1396.363636 121.018182" p-id="45531" fill="#e78d0f"></path></svg>';
                $actions[] = sprintf(
                    '<a href="javascript:;"><b><font id="approval" class="small approval" data-torrent_id="%s">%s&nbsp;%s</font></b></a>',
                    $row['id'], $approvalIcon, $lang['action_approval']
                );
                $approvalTitle = nexus_trans('torrent.approval.modal_title');
                $scripts[] = <<<JS
jQuery('#approval').on("click", function () {
    let torrentId = jQuery(this).attr('data-torrent_id')
    layer.open({
        type: 2,
        title: '$approvalTitle',
        area: ['60%', '600px'],
        content: '/web/torrent-approval-page?torrent_id=' + torrentId,
    })
})
JS;
            }
            $actions = apply_filter('torrent_detail_actions', $actions, $row);
            $actions[] = '<a title="' . $lang['title_report_torrent'] . '" href="report.php?torrent=' . $id . '"><img class="dt_report" src="pic/trans.gif" alt="report" />&nbsp;<b><font class="small">' . $lang['text_report_torrent'] . '</font></b></a>';
            $content .= $this->row($lang['row_action'], implode('&nbsp;|&nbsp;', $actions), 1);

            // claim block
            $claimTorrentTTL = Claim::getConfigTorrentTTL();
            if (Claim::getConfigIsEnabled() && Carbon::parse($row['added'])->addDays($claimTorrentTTL)->lte(Carbon::now())) {
                $baseClaimQuery = Claim::query()->where('torrent_id', $id);
                $claimCounts = (clone $baseClaimQuery)->count();
                $isClaimed = (clone $baseClaimQuery)->where('uid', $curUser['id'])->exists();
                if ($isClaimed) {
                    $inputValue = $lang['claim_already'];
                    $disabled = ' disabled';
                } else {
                    $inputValue = $lang['claim_now'];
                    $disabled = '';
                    $claimConfirm = $lang['claim_confirm'];
                    $scripts[] = <<<JS
jQuery('#add-claim').on('click', function () {
    if (!window.confirm('$claimConfirm')) {
        return
    }
    let params = {action: "addClaim", params: {"torrent_id": jQuery(this).attr('data-torrent_id')}}
    jQuery.post("ajax.php", params, function (response) {
        console.log(response)
        if (response.ret != 0) {
            alert(response.msg)
        } else {
            window.location.reload()
        }
    }, 'json')
})
JS;
                }
                $maxUserCounts = get_setting('torrent.claim_torrent_user_counts_up_limit', Claim::USER_UP_LIMIT);
                $y = sprintf('<input type="button" value="%s" id="add-claim" data-torrent_id="%s"%s>', $inputValue, $id, $disabled);
                $y .= sprintf('&nbsp;' . $lang['claim_info'], $claimCounts, bcsub($maxUserCounts, $claimCounts));
                $y .= sprintf('&nbsp;<b><a href="claim.php?torrent_id=%s">' . $lang['claim_detail'] . '</a></b>', $id);
                $content .= $this->row($lang['claim_label'], $y, 1);
            }

            $content .= $this->row(
                $lang['torrent_dl_url'],
                sprintf('<a title="%s" href="%s">%s</a>', $lang['torrent_dl_url_notice'], $torrentRep->getDownloadUrl($id, $currentUser), $lang['torrent_dl_url_text']),
                1
            );

            // subtitles block
            $subTorrentIdArr = [$row['id']];
            $otherCopiesIdArr = [];
            if ($imdbId) {
                $otherCopiesIdArr = Torrent::query()->where('url', $imdbId)->where('id', '!=', $row['id'])->pluck('id')->toArray();
            }
            $subsRows = DB::table('subs')
                ->leftJoin('language', 'subs.lang_id', '=', 'language.id')
                ->select('subs.*', 'language.flagpic', 'language.lang_name')
                ->whereIn('torrent_id', $subTorrentIdArr)
                ->orderBy('subs.lang_id')
                ->get();
            $content .= '<tr><td class="rowhead" valign="top">' . $lang['row_subtitles'] . '</td>';
            $content .= '<td class="rowfollow" align="left" valign="top">';
            $content .= '<table border="0" cellspacing="0">';
            if ($subsRows->isNotEmpty()) {
                foreach ($subsRows as $a) {
                    $subLang = '<tr><td class="embedded"><img border="0" src="pic/flag/' . $a->flagpic . '" alt="' . $a->lang_name . '" title="' . $a->lang_name . '" style="padding-bottom: 4px" /></td>';
                    $subLang .= '<td class="embedded">&nbsp;&nbsp;<a href="downloadsubs.php?torrentid=' . $a->torrent_id . '&subid=' . $a->id . '"><u>' . htmlspecialchars($a->title) . '</u></a>' . (user_can('submanage') || (user_can('delownsub') && $a->uppedby == $curUser['id']) ? ' <font class="small"><a href="subtitles.php?delete=' . $a->id . '">[' . $lang['text_delete'] . '</a>]</font>' : '') . '</td><td class="embedded">&nbsp;&nbsp;' . ($a->anonymous == 'yes' ? $lang['text_anonymous'] . (user_can('viewanonymous') ? get_username($a->uppedby, false, true, true, false, true) : '') : get_username($a->uppedby)) . '</td></tr>';
                    $content .= $subLang;
                }
            } else {
                $content .= '<tr><td class="embedded">' . $lang['text_no_subtitles'] . '</td></tr>';
            }
            $content .= '</table>';
            $content .= '<table border="0" cellspacing="0"><tr>';
            if ($curUser['id'] == $row['owner'] || user_can('uploadsub')) {
                $content .= '<td class="embedded"><form method="post" action="subtitles.php"><input type="hidden" name="torrent_name" value="' . $row['name'] . '" /><input type="hidden" name="detail_torrent_id" value="' . $row['id'] . '" /><input type="hidden" name="in_detail" value="in_detail" /><input type="submit" value="' . $lang['submit_upload_subtitles'] . '" /></form></td>';
            }
            $moviename = '';
            if ($imdbId && $GLOBALS['showextinfo']['imdb'] == 'yes') {
                $thenumbers = $imdbId;
                if (!$moviename = $Cache->get_value('imdb_id_' . $thenumbers . '_movie_name')) {
                    switch ($imdb->getCacheStatus($imdbId)) {
                        case '1':
                            $moviename = $movie->title();
                            $Cache->cache_value('imdb_id_' . $thenumbers . '_movie_name', $moviename, 1296000);
                            break;
                        default:
                            break;
                    }
                }
            }
            $content .= '<td class="embedded"><form method="get" action="https://assrt.net/sub/" target="_blank"><input type="text" name="searchword" id="keyword" style="width: 250px" value="' . $moviename . '" /><input type="submit" value="' . $lang['submit_search_at_shooter'] . '" /></form></td><td class="embedded"><form method="get" action="https://www.opensubtitles.org/en/search2/" target="_blank"><input type="hidden" id="moviename" name="MovieName" /><input type="hidden" name="action" value="search" /><input type="hidden" name="SubLanguageID" value="all" /><input onclick="document.getElementById(\'moviename\').value=document.getElementById(\'keyword\').value;" type="submit" value="' . $lang['submit_search_at_opensubtitles'] . '" /></form></td>' . "\n";
            $content .= '</tr></table>';
            $content .= '</td></tr>' . "\n";

            do_action('torrent_detail_before_desc', $row['id'], $curUser['id']);
            $content .= $customField->renderOnTorrentDetailsPage($id, $row['search_box_id']);

            // technical info
            if ($settingMain['enable_technical_info'] == 'yes') {
                $technicalData = nexus_escape($row['technical_info'] ?? '');
                $isBdInfo = false;
                if (!empty($technicalData)) {
                    $firstLine = strtok($technicalData, "\n");
                    if (strpos($firstLine, 'DISC INFO') !== false
                        || strpos($firstLine, 'Disc Title') !== false
                        || strpos($firstLine, 'Disc Label') !== false
                    ) {
                        $isBdInfo = true;
                    }
                }
                $technicalInfo = $isBdInfo
                    ? new \Nexus\Torrent\BdInfoExtra($technicalData)
                    : new \Nexus\Torrent\TechnicalInformation($technicalData);
                $technicalInfoResult = $technicalInfo->renderOnDetailsPage();
                if (!empty($technicalInfoResult)) {
                    $content .= $this->row($langFunctions['text_technical_info'], $technicalInfoResult, 1);
                }
            }

            if ($curUser['showdescription'] != 'no' && !empty($row['descr'])) {
                $torrentdetailad = $GLOBALS['Advertisement']->get_ad('torrentdetail');
                $desc = format_comment($row['descr']);
                $desc = apply_filter('torrent_detail_description', $desc, $row['id'], $curUser['id']);
                $content .= $this->row(
                    '<a href="javascript: klappe_news(\'descr\')"><span class="nowrap"><img class="minus" src="pic/trans.gif" alt="Show/Hide" id="picdescr" title="' . ($lang['title_show_or_hide'] ?? '') . '" /> ' . $lang['row_description'] . '</span></a>',
                    '<div id="kdescr">' . ($GLOBALS['Advertisement']->enable_ad() && $torrentdetailad ? '<div align="left" style="margin-bottom: 10px" id="">' . $torrentdetailad[0] . '</div>' : '') . $desc . '</div>',
                    1
                );
            }

            if (user_can('viewnfo') && $curUser['shownfo'] != 'no' && $row['nfosz'] > 0) {
                if (!$nfo = $Cache->get_value('nfo_block_torrent_id_' . $id)) {
                    $nfo = code_new($row['nfo'], get_setting('torrent.nfo_view_style_default'));
                    $Cache->cache_value('nfo_block_torrent_id_' . $id, $nfo, 604800);
                }
                $content .= $this->row(
                    '<a href="javascript: klappe_news(\'nfo\')"><img class="plus" src="pic/trans.gif" alt="Show/Hide" id="picnfo" title="' . $lang['title_show_or_hide'] . '" /> ' . $lang['text_nfo'] . '</a><br /><a href="viewnfo.php?id=' . $row['id'] . '" class="sublink">' . $lang['text_view_nfo'] . '</a>',
                    '<div id="knfo" style="display: none;"><pre style="font-size:10pt; font-family: \'Courier New\', monospace;white-space: break-spaces">' . $nfo . '</pre></div>' . "\n",
                    1
                );
            }

            if ($imdbId && $GLOBALS['showextinfo']['imdb'] == 'yes' && $curUser['showimdb'] != 'no') {
                $content .= $this->capture(function () use ($Cache, $imdb, $movie, $row, $imdbId, $lang, $id) {
                    $thenumbers = $imdbId;
                    $Cache->new_page('imdb_id_' . $thenumbers . '_large', 3600 * 24, true);
                    if (!$Cache->get_page()) {
                        switch ($imdb->getCacheStatus($imdbId)) {
                            case '0':
                                if ($row['cache_stamp'] == 0 || ($row['cache_stamp'] != 0 && (time() - $row['cache_stamp']) > 120)) {
                                    tr($lang['text_imdb'] . $lang['row_info'], $lang['text_imdb'] . $lang['text_not_ready'] . '<a href="retriver.php?id=' . $id . '&amp;type=1&amp;siteid=1">' . $lang['text_here_to_retrieve'] . $lang['text_imdb'], 1);
                                } else {
                                    tr($lang['text_imdb'] . $lang['row_info'], '<img src="pic/progressbar.gif" alt="" />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' . $lang['text_someone_has_requested'] . min(max(time() - $row['cache_stamp'], 0), 120) . $lang['text_please_be_patient'], 1);
                                }
                                break;
                            case '1':
                                reset_cachetimestamp($row['id']);
                                if (($photoUrl = $movie->photo()) != false) {
                                    $smallth = '<img src="' . $photoUrl . '" width="105" onclick="Preview(this);" alt="poster" />';
                                } else {
                                    $smallth = '<img src="pic/imdb_pic/nophoto.gif" alt="no poster" />';
                                }
                                $autodata = $imdb->renderDetailsPageDescription($row['id'], $imdbId);
                                $cacheTime = $imdb->getCachedAt($imdbId);
                                $Cache->add_whole_row();
                                print('<tr>');
                                print('<td class="rowhead"><a href="javascript: klappe_ext(\'imdb\')"><span class="nowrap"><img class="minus" src="pic/trans.gif" alt="Show/Hide" id="picimdb" title="' . $lang['title_show_or_hide'] . '" /> ' . $lang['text_imdb'] . $lang['row_info'] . '</span></a><div id="posterimdb">' . $smallth . '</div></td>');
                                $Cache->end_whole_row();
                                $Cache->add_row();
                                $Cache->add_part();
                                print('<td class="rowfollow" align="left"><div id="kimdb">' . $autodata);
                                $Cache->end_part();
                                $Cache->add_part();
                                print($lang['text_information_updated_at'] . date('Y-m-d H:i:s', $cacheTime) . $lang['text_might_be_outdated'] . '<a href="' . htmlspecialchars('retriver.php?id=' . $id . '&type=2&siteid=1') . '">' . $lang['text_here_to_update']);
                                $Cache->end_part();
                                $Cache->end_row();
                                $Cache->add_whole_row();
                                print('</div></td></tr>');
                                $Cache->end_whole_row();
                                $Cache->cache_page();
                                echo $Cache->next_row();
                                $Cache->next_row();
                                echo $Cache->next_part();
                                if (user_can('updateextinfo')) {
                                    echo $Cache->next_part();
                                }
                                echo $Cache->next_row();
                                break;
                            case '2':
                                tr($lang['text_imdb'] . $lang['row_info'], $lang['text_network_error'], 1);
                                break;
                            case '3':
                                break;
                        }
                    } else {
                        echo $Cache->next_row();
                        $Cache->next_row();
                        echo $Cache->next_part();
                        if (user_can('updateextinfo')) {
                            echo $Cache->next_part();
                        }
                        echo $Cache->next_row();
                    }
                });
            }

            if (!empty($otherCopiesIdArr)) {
                $copiesRows = DB::table('torrents')
                    ->select([
                        'torrents.id', 'torrents.name', 'torrents.sp_state', 'torrents.size', 'torrents.added',
                        'torrents.seeders', 'torrents.leechers', 'torrents.hr',
                        'categories.id AS catid', 'categories.name AS catname', 'categories.image AS catimage',
                        'sources.name AS source_name', 'media.name AS medium_name', 'codecs.name AS codec_name',
                        'standards.name AS standard_name', 'processings.name AS processing_name',
                        'teams.name AS team_name', 'audiocodecs.name AS audiocodec_name',
                        'categories.mode as search_box_id',
                    ])
                    ->leftJoin('categories', 'torrents.category', '=', 'categories.id')
                    ->leftJoin('sources', 'torrents.source', '=', 'sources.id')
                    ->leftJoin('media', 'torrents.medium', '=', 'media.id')
                    ->leftJoin('codecs', 'torrents.codec', '=', 'codecs.id')
                    ->leftJoin('standards', 'torrents.standard', '=', 'standards.id')
                    ->leftJoin('teams', 'torrents.team', '=', 'teams.id')
                    ->leftJoin('audiocodecs', 'torrents.audiocodec', '=', 'audiocodecs.id')
                    ->leftJoin('processings', 'torrents.processing', '=', 'processings.id')
                    ->whereIn('torrents.id', $otherCopiesIdArr)
                    ->orderByDesc('torrents.id')
                    ->get();
                $copiesCount = $copiesRows->count();
                if ($copiesCount > 0) {
                    $s = '<table border="1" cellspacing="0" cellpadding="5">' . "\n";
                    $s .= '<tr><td class="colhead" style="padding: 0px; text-align:center;">' . $lang['col_type'] . '</td><td class="colhead" align="left">' . $lang['col_name'] . '</td><td class="colhead" align="center">' . $lang['col_quality'] . '</td><td class="colhead" align="center"><img class="size" src="pic/trans.gif" alt="size" title="' . $lang['title_size'] . '" /></td><td class="colhead" align="center"><img class="time" src="pic/trans.gif" alt="time added" title="' . $lang['title_time_added'] . '" /></td><td class="colhead" align="center"><img class="seeders" src="pic/trans.gif" alt="seeders" title="' . $lang['title_seeders'] . '" /></td><td class="colhead" align="center"><img class="leechers" src="pic/trans.gif" alt="leechers" title="' . $lang['title_leechers'] . '" /></td></tr>' . "\n";
                    foreach ($copiesRows as $copyRow) {
                        $copyRow = (array) $copyRow;
                        $dispname = htmlspecialchars(trim($copyRow['name']));
                        if (strlen($dispname) > 80) {
                            $dispname = substr($dispname, 0, 80) . '..';
                        }
                        $copyTaxonomyInfo = $searchBoxRep->listTaxonomyInfo($copyRow['search_box_id'], $copyRow);
                        $taxonomyValues = array_column($copyTaxonomyInfo, 'value');
                        $sphighlight = get_torrent_bg_color($copyRow['sp_state']);
                        $spInfo = get_torrent_promotion_append($copyRow['sp_state'], '', false, '', 0, '', $copyRow['__ignore_global_sp_state'] ?? false);
                        $copyHrImg = get_hr_img($copyRow, $copyRow['search_box_id']);
                        $s .= '<tr' . $sphighlight . '><td class="rowfollow nowrap" valign="middle" style="padding: 0px">' . return_category_image($copyRow['catid'], 'torrents.php?allsec=1&amp;') . '</td><td class="rowfollow" align="left"><a href="' . htmlspecialchars(get_protocol_prefix() . $GLOBALS['BASEURL'] . '/details.php?id=' . $copyRow['id'] . '&hit=1') . '">' . $dispname . '</a>' . $spInfo . $copyHrImg . '</td>'
                            . '<td class="rowfollow" align="left">' . implode(', ', $taxonomyValues) . '</td>'
                            . '<td class="rowfollow" align="center">' . mksize($copyRow['size']) . '</td>'
                            . '<td class="rowfollow nowrap" align="center">' . str_replace('&nbsp;', '<br />', gettime($copyRow['added'], false)) . '</td>'
                            . '<td class="rowfollow" align="center">' . $copyRow['seeders'] . '</td>'
                            . '<td class="rowfollow" align="center">' . $copyRow['leechers'] . '</td>'
                            . "</tr>\n";
                    }
                    $s .= '</table>' . "\n";
                    $content .= $this->row(
                        '<a href="javascript: klappe_news(\'othercopy\')"><span class="nowrap"><img class="' . ($copiesCount > 5 ? 'plus' : 'minus') . '" src="pic/trans.gif" alt="Show/Hide" id="picothercopy" title="' . $lang['title_show_or_hide'] . '" /> ' . $lang['row_other_copies'] . '</span></a>',
                        '<b>' . $copiesCount . $lang['text_other_copies'] . ' </b><br /><div id="kothercopy" style="' . ($copiesCount > 5 ? 'display: none;' : 'display: block;') . '">' . $s . '</div>',
                        1
                    );
                }
            }

            // torrent info
            $filesInfo = '';
            if ($row['type'] == 'multi') {
                $filesInfo = '<b>' . $lang['text_num_files'] . '</b>' . $row['numfiles'] . $lang['text_files'] . '<br />';
                $filesInfo .= '<span id="showfl"><a href="javascript: viewfilelist(' . $id . ')">' . $lang['text_see_full_list'] . '</a></span><span id="hidefl" style="display: none;"><a href="javascript: hidefilelist()">' . $lang['text_hide_list'] . '</a></span>';
            }
            $infoTds = [];
            if (!empty($filesInfo)) {
                $infoTds[] = '<td class="no_border_wide">' . $filesInfo . '</td>';
            }
            $infoTds[] = '<td class="no_border_wide"><b>' . $lang['row_info_hash'] . ':</b>&nbsp;' . preg_replace_callback('/./s', function ($matches) {
                return sprintf('%02x', ord($matches[0]));
            }, hash_pad($row['info_hash'])) . '</td>';
            if (user_can('torrentstructure')) {
                $infoTds[] = '<td class="no_border_wide"><b>' . $lang['text_torrent_structure'] . '</b><a href="torrent_info.php?id=' . $id . '">' . $lang['text_torrent_info_note'] . '</a></td>';
            }
            $content .= $this->row($lang['row_torrent_info'], '<table><tr>' . implode('', $infoTds) . '</tr></table><span id="filelist"></span>', 1);
            $content .= $this->row(
                $lang['row_hot_meter'],
                '<table><tr><td class="no_border_wide"><b>' . $lang['text_views'] . '</b>' . $row['views'] . '</td><td class="no_border_wide"><b>' . $lang['text_hits'] . '</b>' . $row['hits'] . '</td><td class="no_border_wide"><b>' . $lang['text_snatched'] . '</b><a href="viewsnatches.php?id=' . $id . '"><b>' . $row['times_completed'] . $lang['text_view_snatches'] . '</td><td class="no_border_wide"><b>' . $lang['row_last_seeder'] . '</b>' . gettime($row['last_action']) . '</td></tr></table>',
                1
            );

            $bwrow = DB::table('users')
                ->leftJoin('uploadspeed', 'users.upload', '=', 'uploadspeed.id')
                ->leftJoin('downloadspeed', 'users.download', '=', 'downloadspeed.id')
                ->leftJoin('isp', 'users.isp', '=', 'isp.id')
                ->select('uploadspeed.name AS upname', 'downloadspeed.name AS downname', 'isp.name AS ispname')
                ->where('users.id', $row['owner'])
                ->first();
            if ($bwrow && $bwrow->upname && $bwrow->downname) {
                $content .= $this->row(
                    $lang['row_uploader_bandwidth'],
                    '<img class="speed_down" src="pic/trans.gif" alt="Downstream Rate" /> ' . $bwrow->downname . '&nbsp;&nbsp;&nbsp;&nbsp;<img class="speed_up" src="pic/trans.gif" alt="Upstream Rate" /> ' . $bwrow->upname . '&nbsp;&nbsp;&nbsp;&nbsp;' . $bwrow->ispname,
                    1
                );
            }

            $content .= $this->row(
                '<span id="seeders"></span><span id="leechers"></span>' . $lang['row_peers'] . '<br /><span id="showpeer"><a href="javascript: viewpeerlist(' . $row['id'] . ');" class="sublink">' . $lang['text_see_full_list'] . '</a></span><span id="hidepeer" style="display: none;"><a href="javascript: hidepeerlist();" class="sublink">' . $lang['text_hide_list'] . '</a></span>',
                '<div id="peercount"><b>' . $row['seeders'] . $lang['text_seeders'] . add_s($row['seeders']) . '</b> | <b>' . $row['leechers'] . $lang['text_leechers'] . add_s($row['leechers']) . '</b></div><div id="peerlist"></div>',
                1
            );
            if ($request->query('dllist') == '1') {
                $scripts[] = 'viewpeerlist(' . $row['id'] . ')';
            }

            // magic value award block
            $bonusArray = Setting::getBonusRewardOptions();
            $content .= '<style type="text/css">
					ul.magic
					{
						cursor:pointer;
						list-style-type:none;
						padding-left:0px;
					}
					ul.magic li
					{
						margin:0px;text-align:center;float:left;width:40px;margin-right:15px; height:21px;background:url("styles/huise.png") no-repeat;
						padding-left:5px;padding-right:5px;
						line-height:20px;
					}
					ul.magic li:hover
					{
						background:url("styles/boli.png") no-repeat
					}
				</style>';
            $magicValueButton = '';
            $bonusHas = null;
            $arrTemp = null;
            if ($curUser['id'] <> $row['owner']) {
                $arrTemp = $bonusArray;
                $bonusHas = (int) $curUser['seedbonus'];
                if ($bonusHas < (int) $arrTemp[0]) {
                    $errorBonusMessage = $lang['magic_have_no_enough_bonus_value'];
                    $magicValueButton = '<input class="btn" type="button" value="' . $errorBonusMessage . '" disabled="disabled" />';
                } else {
                    foreach ($arrTemp as $key => $eachTemp) {
                        $eachTemp = (int) $eachTemp;
                        if ($eachTemp > 0 && $eachTemp <= $bonusHas) {
                            $eachTempFont = '<font style="font-size:8pt;padding-right:5px;">' . ('+' . $eachTemp) . '</font>';
                            $magicValueButton .= '<li onclick="saveMagicValue(' . $id . ',$eachTemp);">' . $eachTempFont . '</li>';
                        }
                    }
                }
            }

            $spanDescription = $lang['span_description_have_given'];
            $span = '<input class="btn" type="button" id="magic_add" style="display:none" value="' . $spanDescription . '" disabled="disabled" />&nbsp;';
            $whetherHaveGiveValue = 0;
            $giveValue = [];
            $noGive = '';
            $addValue = '';
            $sumValue = 0;
            $countUserNumber = DB::table('magic')->where('torrentid', $id)->distinct()->count('userid');
            $giveValueSql = DB::table('magic')->where('torrentid', $id)->orderByDesc('id')->get();
            $giveValueAll = $giveValueSql->count();
            foreach ($giveValueSql as $rowT) {
                $giveValueUserid = $rowT->userid;
                $sumValue += (int) $rowT->value;
                if ($giveValueUserid == $curUser['id']) {
                    $whetherHaveGiveValue = 1;
                    $addValue = $rowT->value;
                }
                $giveValue[] = get_username($giveValueUserid) . ' ';
            }
            if (!$giveValueAll) {
                $noGive = $lang['text_no_magic_added'];
            }

            if (isset($bonusHas) && isset($arrTemp) && $bonusHas < (int) $arrTemp[0]) {
                // keep the "not enough bonus" disabled button
            } elseif ($whetherHaveGiveValue == 0) {
                $magicValueButton = '<ul id="listNumber" class="magic">' . $magicValueButton . '</ul>';
            } else {
                $addValue = str_replace('Number', $addValue, $lang['magic_value_number']);
                $magicValueButton = '<input class="btn" type="button" value="' . $addValue . '" disabled="disabled" />';
            }

            $showList = null;
            $showAll = null;
            $showListNewNumber = 6;
            $otherUserStr = null;
            $otherUserSpan = null;
            $showListDescription = null;
            if (count($giveValue) > 0) {
                $countUserSpan = '<span id="count_user_spa">' . $countUserNumber . '</span>';
                $magicNewestRecord = '<span id="magic_newest_record">' . $lang['magic_newest_record'] . '</span>';
                $showListDescription = '(' . $magicNewestRecord . $lang['magic_sum_user_give_number'] . ')';
                $showListDescription = str_replace('Number', $countUserSpan, $showListDescription);
                $output = array_slice($giveValue, 0, $showListNewNumber);
                foreach ($output as $eachOutput) {
                    $showList .= $eachOutput . '  ';
                }
                if (count($giveValue) > $showListNewNumber) {
                    $showList .= '<span id="ellipsis">&nbsp;......&nbsp;</span>';
                    $showAllDescription = '[' . $lang['magic_show_all_description'] . ']';
                    $showAll = '<a herf="#" style="cursor:pointer" onclick="displayOtherUserList()">' . $showAllDescription . '</a>' . '<br/>';
                    $otherUserList = array_slice($giveValue, $showListNewNumber, count($giveValue));
                    foreach ($otherUserList as $each) {
                        $otherUserStr .= $each . '  ';
                    }
                    $otherUserSpan = '<span id="other_user_list" style="display:none">' . $otherUserStr . '</span>';
                }
            }
            $currentUserMagic = '<span id="current_user_magic" style="display:none">' . get_username($curUser['id']) . '</span>&nbsp;';
            $haveGotBonus = $lang['magic_haveGotBonus'] . '&nbsp';
            $spanSumAll = '<span id="spanSumAll">' . $sumValue . '</span>';
            $haveGotBonus = str_replace('Number', $spanSumAll, $haveGotBonus);
            $firstLine = '<div style="height:25px">' . $magicValueButton . $span . $haveGotBonus . $showAll . '</div>';
            $otherLine = '<div>' . $currentUserMagic . $showList . $otherUserSpan . $showListDescription . '</div>';
            $content .= $this->row($lang['magic_value_award'], $firstLine . $otherLine, 1);

            // thanked-by block
            $torrentid = $id;
            $thanksBy = '';
            $noThanks = '';
            $thanksSaid = 0;
            $thanksSql = DB::table('thanks')->where('torrentid', $torrentid)->orderByDesc('id')->limit(20)->get();
            $thanksCount = DB::table('thanks')->where('torrentid', $torrentid)->count();
            $thanksAll = $thanksSql->count();
            if ($thanksAll) {
                foreach ($thanksSql as $rowT) {
                    if ($rowT->userid == $curUser['id']) {
                        $thanksSaid = 1;
                    } else {
                        $thanksBy .= get_username($rowT->userid) . ' ';
                    }
                }
            } else {
                $noThanks = $lang['text_no_thanks_added'];
            }
            if (!$thanksSaid) {
                $thanksSaid = DB::table('thanks')->where('torrentid', $torrentid)->where('userid', $curUser['id'])->count();
            }
            if ($thanksSaid == 0) {
                $buttonValue = ' value="' . $lang['submit_say_thanks'] . '"';
            } else {
                $buttonValue = ' value="' . $lang['submit_you_said_thanks'] . '" disabled="disabled"';
                $thanksBy = get_username($curUser['id']) . ' ' . $thanksBy;
            }
            $thanksButton = '<input class="btn" type="button" id="saythanks" onclick="saythanks(' . $torrentid . ');" ' . $buttonValue . ' />';
            $content .= $this->row(
                $lang['row_thanks_by'],
                '<span id="thanksadded" style="display: none;"><input class="btn" type="button" value="' . $lang['text_thanks_added'] . '" disabled="disabled" /></span>'
                . '<span id="curuser" style="display: none;">' . get_username($curUser['id']) . ' </span><span id="thanksbutton">' . $thanksButton . '</span>'
                . '&nbsp;&nbsp;<span id="nothanks">' . $noThanks . '</span><span id="addcuruser"></span>' . $thanksBy
                . ($thanksAll < $thanksCount ? $lang['text_and_more'] . $thanksCount . $lang['text_users_in_total'] : ''),
                1
            );

            $content .= '</table>' . "\n";
        } else {
            $pageTitle = $lang['head_comments_for_torrent'] . '"' . $row['name'] . '"';
            $content .= '<h1 id="top">' . $lang['text_comments_for'] . '<a href="details.php?id=' . $id . '">' . htmlspecialchars($row['name']) . '</a></h1>' . "\n";
        }

        if (in_array('views = views + 1', $torrentUpdate)) {
            DB::table('torrents')->where('id', $id)->increment('views');
        }

        // comment section
        if ($curUser['showcomment'] != 'no') {
            $count = DB::table('comments')->where('torrent', $id)->count();
            if ($count) {
                $content .= '<br /><br />';
                $content .= '<h1 align="center" id="startcomments">' . $lang['h1_user_comments'] . '</h1>' . "\n";
                list($pagertop, $pagerbottom, $limit) = pager(10, $count, 'details.php?id=' . $id . '&cmtpage=1&', ['lastpagedefault' => 1], 'page');
                preg_match('/limit\s+(\d+)\s+offset\s+(\d+)/', $limit, $limitMatch);
                $commentRows = DB::table('comments')
                    ->select('id', 'text', 'user', 'added', 'editedby', 'editdate')
                    ->where('torrent', $id)
                    ->orderBy('id')
                    ->limit((int) ($limitMatch[1] ?? 10))
                    ->offset((int) ($limitMatch[2] ?? 0))
                    ->get()
                    ->map(fn ($rowT) => (array) $rowT)
                    ->all();
                $content .= $pagertop;
                $content .= $this->capture(function () use ($commentRows, $id) {
                    commenttable($commentRows, 'torrent', $id);
                });
                $content .= $pagerbottom;
            }
        }
        $content .= '<br /><br />';
        $content .= '<table style="border:1px solid #000000;"><tr><td class="text" align="center"><b>' . $lang['text_quick_comment'] . '</b><br /><br /><form id="compose" name="comment" method="post" action="' . htmlspecialchars('comment.php?action=add&type=torrent') . '" onsubmit="return postvalid(this);"><input type="hidden" name="pid" value="' . $id . '" /><br />';
        $content .= $this->capture(function () use ($lang) {
            quickreply('comment', 'body', $lang['submit_add_comment']);
        });
        $content .= '</form></td></tr></table>';
        $content .= '<p align="center"><a class="index" href="' . htmlspecialchars('comment.php?action=add&pid=' . $id . '&type=torrent') . '">' . $lang['text_add_a_comment'] . '</a></p>' . "\n";

        $response = response(view('torrent.details', compact('lang', 'pageTitle', 'content', 'scripts')));
        if ($headerRefresh !== null) {
            $response->headers->set('Refresh', $headerRefresh);
        }

        return $response;
    }

    /**
     * Torrent list page. Mirrors legacy public/torrents.php.
     *
     * @param  string  $section  'torrents' (browse) or 'special'
     */
    public function browse(Request $request, string $section = 'torrents')
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

        // In the real web stack PHP populates $_GET/$_REQUEST from the query
        // string; under the test HTTP kernel they are not auto-filled. Mirror it
        // so the legacy query building below behaves identically in both worlds.
$_GET = $request->query();
$_REQUEST = $request->all();

        // legacy user-class constants are defined in include/core.php (not loaded
        // in the Laravel bootstrap); legacy helpers like torrenttable() rely on them
        foreach ([
            'UC_PEASANT' => 0, 'UC_USER' => 1, 'UC_POWER_USER' => 2, 'UC_ELITE_USER' => 3,
            'UC_CRAZY_USER' => 4, 'UC_INSANE_USER' => 5, 'UC_VETERAN_USER' => 6,
            'UC_EXTREME_USER' => 7, 'UC_ULTIMATE_USER' => 8, 'UC_NEXUS_MASTER' => 9,
            'UC_VIP' => 10, 'UC_RETIREE' => 11, 'UC_UPLOADER' => 12, 'UC_MODERATOR' => 13,
            'UC_ADMINISTRATOR' => 14, 'UC_SYSOP' => 15, 'UC_STAFFLEADER' => 16,
        ] as $constant => $value) {
            defined($constant) || define($constant, $value);
        }

        // globals the shared legacy helpers expect (mirrors public/details.php bootstrap)
        $langFunctions = get_legacy_lang_file('functions');
        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = $langFunctions;
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();
        $GLOBALS['waitsystem'] = get_setting('main.waitsystem', 'no');
        $GLOBALS['showextinfo'] = ['imdb' => get_setting('main.showimdbinfo', 'no')];
        $GLOBALS['torrentmanage_class'] = get_setting('authority.torrentmanage', '');
        $GLOBALS['smalldescription_main'] = get_setting('main.smalldescription', 'yes');
        $GLOBALS['enabletooltip_tweak'] = get_setting('tweak.enabletooltip', 'no');
        $GLOBALS['staffmem_class'] = get_setting('authority.staffmem', '');
        $GLOBALS['expirehalfleech_torrent'] = get_setting('torrent.expirehalfleech');
        $GLOBALS['expirefree_torrent'] = get_setting('torrent.expirefree');
        $GLOBALS['expiretwoup_torrent'] = get_setting('torrent.expiretwoup');
        $GLOBALS['expiretwoupfree_torrent'] = get_setting('torrent.expiretwoupfree');
        $GLOBALS['expiretwouphalfleech_torrent'] = get_setting('torrent.expiretwouphalfleech');
        $GLOBALS['expirethirtypercentleech_torrent'] = get_setting('torrent.expirethirtypercentleech');
        $GLOBALS['commanage_class'] = (int) get_setting('authority.commanage', 0);
        $GLOBALS['specialcatmode'] = (int) get_setting('main.specialcat', 0);
        $GLOBALS['browsecatmode'] = (int) get_setting('main.browsecat', 0);
        $GLOBALS['enablespecial'] = get_setting('main.spsct', 'no');
        $GLOBALS['torrentsperpage_main'] = (int) get_setting('main.torrentsperpage', 50);
        $GLOBALS['showmovies'] = [
            'hot' => get_setting('main.hotmovie', 'yes'),
            'classic' => get_setting('main.classicmovie', 'yes'),
        ];
        if (empty($GLOBALS['Advertisement'])) {
            require_once ROOT_PATH . 'classes/class_advertisement.php';
            $GLOBALS['Advertisement'] = new \ADVERTISEMENT($currentUser->id);
        }
        /** @var \class_cache_redis $Cache */
        $Cache = $GLOBALS['Cache'];
        $Advertisement = $GLOBALS['Advertisement'];

        $lang_torrents = get_legacy_lang_file('torrents');
        $lang_special = get_legacy_lang_file('special');

        // check searchbox
        switch ($section) {
            case 'special':
                if ($GLOBALS['enablespecial'] != 'yes') {
                    abort(404);
                }
                if (! user_can('view_special_torrent')) {
                    abort(403, $lang_special['std_sorry'] . $lang_special['std_permission_denied_only'] . get_user_class_name(get_setting('authority.view_special_torrent'), false, true, true) . sprintf($lang_special['std_or_above_can_view'], \App\Models\Setting::getSiteName()));
                }
                $sectiontype = $GLOBALS['specialcatmode'];
                break;
            case 'torrents':
            default:
                $sectiontype = $GLOBALS['browsecatmode'];
        }

        // tags
        $tagRep = new \App\Repositories\TagRepository();
        $allTags = $tagRep->listAll($sectiontype);
        $filterInputWidth = 62;
        $searchParams = $_GET;
        $searchParams['mode'] = $sectiontype;

        $showsubcat = get_searchbox_value($sectiontype, 'showsubcat');
        $showsource = get_searchbox_value($sectiontype, 'showsource');
        $showmedium = get_searchbox_value($sectiontype, 'showmedium');
        $showcodec = get_searchbox_value($sectiontype, 'showcodec');
        $showstandard = get_searchbox_value($sectiontype, 'showstandard');
        $showprocessing = get_searchbox_value($sectiontype, 'showprocessing');
        $showteam = get_searchbox_value($sectiontype, 'showteam');
        $showaudiocodec = get_searchbox_value($sectiontype, 'showaudiocodec');
        $catsperrow = get_searchbox_value($sectiontype, 'catsperrow');
        $catpadding = get_searchbox_value($sectiontype, 'catpadding');

        $cats = genrelist($sectiontype);
        if ($showsubcat) {
            if ($showsource) {
                $sources = searchbox_item_list('sources', $sectiontype);
            }
            if ($showmedium) {
                $media = searchbox_item_list('media', $sectiontype);
            }
            if ($showcodec) {
                $codecs = searchbox_item_list('codecs', $sectiontype);
            }
            if ($showstandard) {
                $standards = searchbox_item_list('standards', $sectiontype);
            }
            if ($showprocessing) {
                $processings = searchbox_item_list('processings', $sectiontype);
            }
            if ($showteam) {
                $teams = searchbox_item_list('teams', $sectiontype);
            }
            if ($showaudiocodec) {
                $audiocodecs = searchbox_item_list('audiocodecs', $sectiontype);
            }
        }

        $searchstr_ori = htmlspecialchars(trim($_GET['search'] ?? ''));
        $searchstr = mysql_real_escape_string(trim($_GET['search'] ?? ''));
        if (empty($searchstr)) {
            unset($searchstr);
        }

        $meilisearchEnabled = get_setting('meilisearch.enabled') == 'yes';
        $shouldUseMeili = $meilisearchEnabled && ! empty($searchstr);
        do_log("[SHOULD_USE_MEILI]: $shouldUseMeili");

        // sorting
        $column = '';
        $ascdesc = '';
        $linkascdesc = '';
        $orderby = 'ORDER BY pos_state DESC, torrents.id DESC';
        $pagerlink = '';
        if (isset($_GET['sort']) && $_GET['sort'] && isset($_GET['type']) && $_GET['type']) {
            switch ($_GET['sort']) {
                case '1': $column = 'name'; break;
                case '2': $column = 'numfiles'; break;
                case '3': $column = 'comments'; break;
                case '4': $column = 'added'; break;
                case '5': $column = 'size'; break;
                case '6': $column = 'times_completed'; break;
                case '7': $column = 'seeders'; break;
                case '8': $column = 'leechers'; break;
                case '9': $column = 'owner'; break;
                default: $column = 'id';
            }

            switch ($_GET['type']) {
                case 'asc': $ascdesc = 'ASC'; $linkascdesc = 'asc'; break;
                case 'desc': $ascdesc = 'DESC'; $linkascdesc = 'desc'; break;
                default: $ascdesc = 'DESC'; $linkascdesc = 'desc';
            }

            if ($column == 'owner') {
                $orderby = "ORDER BY pos_state DESC, torrents.anonymous, users.username " . $ascdesc;
            } else {
                $orderby = "ORDER BY pos_state DESC, torrents." . $column . " " . $ascdesc;
            }

            $pagerlink = "sort=" . intval($_GET['sort']) . "&type=" . $linkascdesc . "&";
        }

        $allCategoryId = \App\Models\SearchBox::listCategoryId($sectiontype);
        $addparam = '';
        $wherea = [];
        $wherecatina = [];
        $wheresourceina = [];
        $wheremediumina = [];
        $wherecodecina = [];
        $wherestandardina = [];
        $whereprocessingina = [];
        $whereteamina = [];
        $whereaudiocodecina = [];
        $whereothera = [];

        // whether to show torrents from all sections
        $allsec = intval($_GET['allsec'] ?? 0);
        if ($allsec == 1) {
            $addparam .= 'allsec=1&';
        }

        // bookmarked
        $inclbookmarked = 0;
        if ($_GET) {
            $inclbookmarked = intval($_GET['inclbookmarked'] ?? 0);
        } elseif ($curUser['notifs']) {
            if (strpos($curUser['notifs'], '[inclbookmarked=0]') !== false) {
                $inclbookmarked = 0;
            } elseif (strpos($curUser['notifs'], '[inclbookmarked=1]') !== false) {
                $inclbookmarked = 1;
            } elseif (strpos($curUser['notifs'], '[inclbookmarked=2]') !== false) {
                $inclbookmarked = 2;
            }
        }

        if (! in_array($inclbookmarked, [0, 1, 2])) {
            $inclbookmarked = 0;
            write_log('User ' . $curUser['username'] . ',' . $curUser['ip'] . ' is hacking inclbookmarked field in' . ($_SERVER['SCRIPT_NAME'] ?? ''), 'mod');
        }
        if ($inclbookmarked == 0) {
            $addparam .= 'inclbookmarked=0&';
        } elseif ($inclbookmarked == 1) {
            $addparam .= 'inclbookmarked=1&';
            if (isset($curUser)) {
                $wherea[] = 'torrents.id IN (SELECT torrentid FROM bookmarks WHERE userid=' . $curUser['id'] . ')';
            }
        } elseif ($inclbookmarked == 2) {
            $addparam .= 'inclbookmarked=2&';
            if (isset($curUser)) {
                $wherea[] = 'torrents.id NOT IN (SELECT torrentid FROM bookmarks WHERE userid=' . $curUser['id'] . ')';
            }
        }

        // include dead
        if (isset($_GET['incldead'])) {
            $include_dead = intval($_GET['incldead'] ?? 0);
        } elseif ($curUser['notifs']) {
            if (strpos($curUser['notifs'], '[incldead=0]') !== false) {
                $include_dead = 0;
            } elseif (strpos($curUser['notifs'], '[incldead=1]') !== false) {
                $include_dead = 1;
            } elseif (strpos($curUser['notifs'], '[incldead=2]') !== false) {
                $include_dead = 2;
            } else {
                $include_dead = 1;
            }
        } else {
            $include_dead = 1;
        }

        if (! in_array($include_dead, [0, 1, 2])) {
            $include_dead = 0;
            write_log('User ' . $curUser['username'] . ',' . $curUser['ip'] . ' is hacking incldead field in' . ($_SERVER['SCRIPT_NAME'] ?? ''), 'mod');
        }
        if ($include_dead == 0) {
            $addparam .= 'incldead=0&';
        } elseif ($include_dead == 1) {
            $addparam .= 'incldead=1&';
            $whereothera[] = "visible = 'yes'";
        } elseif ($include_dead == 2) {
            $addparam .= 'incldead=2&';
            $whereothera[] = "visible = 'no'";
        }

        if (! isset($curUser) || ! user_can('seebanned')) {
            $whereothera[] = "banned = 'no'";
            $searchParams['banned'] = 'no';
        }

        // special torrent state
        $special_state = 0;
        if ($_GET) {
            $special_state = intval($_GET['spstate'] ?? 0);
        } elseif ($curUser['notifs']) {
            foreach ([0, 1, 2, 3, 4, 5, 6, 7] as $spState) {
                if (strpos($curUser['notifs'], "[spstate={$spState}]") !== false) {
                    $special_state = $spState;
                    break;
                }
            }
        }

        if (! in_array($special_state, [0, 1, 2, 3, 4, 5, 6, 7])) {
            $special_state = 0;
            write_log('User ' . $curUser['username'] . ',' . $curUser['ip'] . ' is hacking spstate field in ' . ($_SERVER['SCRIPT_NAME'] ?? ''), 'mod');
        }
        if ($special_state == 0) {
            $addparam .= 'spstate=0&';
        } elseif ($special_state == 1) {
            $addparam .= 'spstate=1&';
            $wherea[] = 'sp_state = 1';
            if (get_global_sp_state() == 1) {
                $wherea[] = 'sp_state = 1';
            }
        } elseif ($special_state == 2) {
            $addparam .= 'spstate=2&';
            if (get_global_sp_state() == 1) {
                $wherea[] = 'sp_state = 2';
            } elseif (get_global_sp_state() == 2) {
                ;
            }
        } elseif ($special_state == 3) {
            $addparam .= 'spstate=3&';
            if (get_global_sp_state() == 1) {
                $wherea[] = 'sp_state = 3';
            } elseif (get_global_sp_state() == 3) {
                ;
            }
        } elseif ($special_state == 4) {
            $addparam .= 'spstate=4&';
            if (get_global_sp_state() == 1) {
                $wherea[] = 'sp_state = 4';
            } elseif (get_global_sp_state() == 4) {
                ;
            }
        } elseif ($special_state == 5) {
            $addparam .= 'spstate=5&';
            if (get_global_sp_state() == 1) {
                $wherea[] = 'sp_state = 5';
            } elseif (get_global_sp_state() == 5) {
                ;
            }
        } elseif ($special_state == 6) {
            $addparam .= 'spstate=6&';
            if (get_global_sp_state() == 1) {
                $wherea[] = 'sp_state = 6';
            } elseif (get_global_sp_state() == 6) {
                ;
            }
        } elseif ($special_state == 7) {
            $addparam .= 'spstate=7&';
            if (get_global_sp_state() == 1) {
                $wherea[] = 'sp_state = 7';
            } elseif (get_global_sp_state() == 7) {
                ;
            }
        }

        $category_get = intval($_GET['cat'] ?? 0);
        $source_get = $medium_get = $codec_get = $standard_get = $processing_get = $team_get = $audiocodec_get = 0;
        if ($showsubcat) {
            if ($showsource) {
                $source_get = intval($_GET['source'] ?? 0);
            }
            if ($showmedium) {
                $medium_get = intval($_GET['medium'] ?? 0);
            }
            if ($showcodec) {
                $codec_get = intval($_GET['codec'] ?? 0);
            }
            if ($showstandard) {
                $standard_get = intval($_GET['standard'] ?? 0);
            }
            if ($showprocessing) {
                $processing_get = intval($_GET['processing'] ?? 0);
            }
            if ($showteam) {
                $team_get = intval($_GET['team'] ?? 0);
            }
            if ($showaudiocodec) {
                $audiocodec_get = intval($_GET['audiocodec'] ?? 0);
            }
        }

        $all = intval($_GET['all'] ?? 0);

        if (! $all) {
            if (! $_GET && $curUser['notifs']) {
                $all = true;
                foreach ($cats as $cat) {
                    $all &= $cat['id'];
                    $mystring = $curUser['notifs'];
                    $findme = '[cat' . $cat['id'] . ']';
                    if (strpos($mystring, $findme) !== false) {
                        $wherecatina[] = $cat['id'];
                        $addparam .= "cat{$cat['id']}=1&";
                    }
                }
                if ($showsubcat) {
                    if ($showsource) {
                        foreach ($sources as $source) {
                            $all &= $source['id'];
                            if (strpos($curUser['notifs'], '[sou' . $source['id'] . ']') !== false) {
                                $wheresourceina[] = $source['id'];
                                $addparam .= "source{$source['id']}=1&";
                            }
                        }
                    }
                    if ($showmedium) {
                        foreach ($media as $medium) {
                            $all &= $medium['id'];
                            if (strpos($curUser['notifs'], '[med' . $medium['id'] . ']') !== false) {
                                $wheremediumina[] = $medium['id'];
                                $addparam .= "medium{$medium['id']}=1&";
                            }
                        }
                    }
                    if ($showcodec) {
                        foreach ($codecs as $codec) {
                            $all &= $codec['id'];
                            if (strpos($curUser['notifs'], '[cod' . $codec['id'] . ']') !== false) {
                                $wherecodecina[] = $codec['id'];
                                $addparam .= "codec{$codec['id']}=1&";
                            }
                        }
                    }
                    if ($showstandard) {
                        foreach ($standards as $standard) {
                            $all &= $standard['id'];
                            if (strpos($curUser['notifs'], '[sta' . $standard['id'] . ']') !== false) {
                                $wherestandardina[] = $standard['id'];
                                $addparam .= "standard{$standard['id']}=1&";
                            }
                        }
                    }
                    if ($showprocessing) {
                        foreach ($processings as $processing) {
                            $all &= $processing['id'];
                            if (strpos($curUser['notifs'], '[pro' . $processing['id'] . ']') !== false) {
                                $whereprocessingina[] = $processing['id'];
                                $addparam .= "processing{$processing['id']}=1&";
                            }
                        }
                    }
                    if ($showteam) {
                        foreach ($teams as $team) {
                            $all &= $team['id'];
                            if (strpos($curUser['notifs'], '[tea' . $team['id'] . ']') !== false) {
                                $whereteamina[] = $team['id'];
                                $addparam .= "team{$team['id']}=1&";
                            }
                        }
                    }
                    if ($showaudiocodec) {
                        foreach ($audiocodecs as $audiocodec) {
                            $all &= $audiocodec['id'];
                            if (strpos($curUser['notifs'], '[aud' . $audiocodec['id'] . ']') !== false) {
                                $whereaudiocodecina[] = $audiocodec['id'];
                                $addparam .= "audiocodec{$audiocodec['id']}=1&";
                            }
                        }
                    }
                }
            } elseif ($category_get) {
                int_check($category_get, true, true, true);
                $wherecatina[] = $category_get;
                $addparam .= "cat={$category_get}&";
            } elseif ($medium_get) {
                int_check($medium_get, true, true, true);
                $wheremediumina[] = $medium_get;
                $addparam .= "medium={$medium_get}&";
            } elseif ($source_get) {
                int_check($source_get, true, true, true);
                $wheresourceina[] = $source_get;
                $addparam .= "source={$source_get}&";
            } elseif ($codec_get) {
                int_check($codec_get, true, true, true);
                $wherecodecina[] = $codec_get;
                $addparam .= "codec={$codec_get}&";
            } elseif ($standard_get) {
                int_check($standard_get, true, true, true);
                $wherestandardina[] = $standard_get;
                $addparam .= "standard={$standard_get}&";
            } elseif ($processing_get) {
                int_check($processing_get, true, true, true);
                $whereprocessingina[] = $processing_get;
                $addparam .= "processing={$processing_get}&";
            } elseif ($team_get) {
                int_check($team_get, true, true, true);
                $whereteamina[] = $team_get;
                $addparam .= "team={$team_get}&";
            } elseif ($audiocodec_get) {
                int_check($audiocodec_get, true, true, true);
                $whereaudiocodecina[] = $audiocodec_get;
                $addparam .= "audiocodec={$audiocodec_get}&";
            } else {
                // select and go
                $all = true;
                foreach ($cats as $cat) {
                    $__is = (isset($_GET["cat{$cat['id']}"]) && $_GET["cat{$cat['id']}"]);
                    $all &= $__is;
                    if ($__is) {
                        $wherecatina[] = $cat['id'];
                        $addparam .= "cat{$cat['id']}=1&";
                    }
                }
                if ($showsubcat) {
                    $taxFrame = [
                        'source' => [$sources ?? [], 'source', &$wheresourceina],
                        'medium' => [$media ?? [], 'medium', &$wheremediumina],
                        'codec' => [$codecs ?? [], 'codec', &$wherecodecina],
                        'standard' => [$standards ?? [], 'standard', &$wherestandardina],
                        'processing' => [$processings ?? [], 'processing', &$whereprocessingina],
                        'team' => [$teams ?? [], 'team', &$whereteamina],
                        'audiocodec' => [$audiocodecs ?? [], 'audiocodec', &$whereaudiocodecina],
                    ];
                    $showTax = [
                        'source' => $showsource,
                        'medium' => $showmedium,
                        'codec' => $showcodec,
                        'standard' => $showstandard,
                        'processing' => $showprocessing,
                        'team' => $showteam,
                        'audiocodec' => $showaudiocodec,
                    ];
                    foreach ($showTax as $taxonomy => $show) {
                        if (! $show) {
                            continue;
                        }
                        foreach ($taxFrame[$taxonomy][0] as $item) {
                            $__key = $taxFrame[$taxonomy][1] . $item['id'];
                            $__is = (isset($_GET[$__key]) && $_GET[$__key]);
                            $all &= $__is;
                            if ($__is) {
                                $taxFrame[$taxonomy][2][] = $item['id'];
                                $addparam .= "{$__key}=1&";
                            }
                        }
                    }
                }
            }
        }

        if ($all) {
            $wherecatina = [];
            if ($showsubcat) {
                $wheresourceina = [];
                $wheremediumina = [];
                $wherecodecina = [];
                $wherestandardina = [];
                $whereprocessingina = [];
                $whereteamina = [];
                $whereaudiocodecina = [];
            }
            $addparam .= '';
        }

        $wherecatin = $wheresourcein = $wheremediumin = $wherecodecin = $wherestandardin = $whereprocessingin = $whereteamin = $whereaudiocodecin = '';
        if (empty($wherecatina) && ! (in_array($inclbookmarked, [1, 2]) && $allsec == 1)) {
            // require limit in some category
            $wherecatina = $allCategoryId;
        }
        if (count($wherecatina) > 1) {
            $wherecatin = implode(',', $wherecatina);
        } elseif (count($wherecatina) == 1) {
            $wherea[] = "category = $wherecatina[0]";
        }

        if ($showsubcat) {
            if ($showsource) {
                if (count($wheresourceina) > 1) {
                    $wheresourcein = implode(',', $wheresourceina);
                } elseif (count($wheresourceina) == 1) {
                    $wherea[] = "source = $wheresourceina[0]";
                }
            }
            if ($showmedium) {
                if (count($wheremediumina) > 1) {
                    $wheremediumin = implode(',', $wheremediumina);
                } elseif (count($wheremediumina) == 1) {
                    $wherea[] = "medium = $wheremediumina[0]";
                }
            }
            if ($showcodec) {
                if (count($wherecodecina) > 1) {
                    $wherecodecin = implode(',', $wherecodecina);
                } elseif (count($wherecodecina) == 1) {
                    $wherea[] = "codec = $wherecodecina[0]";
                }
            }
            if ($showstandard) {
                if (count($wherestandardina) > 1) {
                    $wherestandardin = implode(',', $wherestandardina);
                } elseif (count($wherestandardina) == 1) {
                    $wherea[] = "standard = $wherestandardina[0]";
                }
            }
            if ($showprocessing) {
                if (count($whereprocessingina) > 1) {
                    $whereprocessingin = implode(',', $whereprocessingina);
                } elseif (count($whereprocessingina) == 1) {
                    $wherea[] = "processing = $whereprocessingina[0]";
                }
            }
        }
        if ($showteam) {
            if (count($whereteamina) > 1) {
                $whereteamin = implode(',', $whereteamina);
            } elseif (count($whereteamina) == 1) {
                $wherea[] = "team = $whereteamina[0]";
            }
        }
        if ($showaudiocodec) {
            if (count($whereaudiocodecina) > 1) {
                $whereaudiocodecin = implode(',', $whereaudiocodecina);
            } elseif (count($whereaudiocodecina) == 1) {
                $wherea[] = "audiocodec = $whereaudiocodecina[0]";
            }
        }

        $wherebase = $wherea;
        $search_area = 0;
        if (isset($searchstr)) {
            $notnewword = (! isset($_GET['notnewword']) || ! $_GET['notnewword']) ? '' : 'notnewword=1&';
            $search_mode = intval($_GET['search_mode'] ?? 0);
            if (! in_array($search_mode, [0, 2])) {
                $search_mode = 0;
                write_log('User ' . $curUser['username'] . ',' . $curUser['ip'] . ' is hacking search_mode field in' . ($_SERVER['SCRIPT_NAME'] ?? ''), 'mod');
            }

            $search_area = intval($_GET['search_area'] ?? 0);

            if ($search_area == 4) {
                $searchstr = (int) parse_imdb_id($searchstr);
            }
            $like_expression_array = [];

            switch ($search_mode) {
                case 0: // AND, OR
                case 1:
                    $searchstr = str_replace('.', ' ', $searchstr);
                    $searchstr_exploded = explode(' ', $searchstr);
                    $searchstr_exploded_count = 0;
                    foreach ($searchstr_exploded as $searchstr_element) {
                        $searchstr_element = trim($searchstr_element);
                        $searchstr_exploded_count++;
                        if ($searchstr_exploded_count > 3) {
                            // maximum 3 keywords
                            break;
                        }
                        $like_expression_array[] = " LIKE '%" . $searchstr_element . "%'";
                    }
                    break;
                case 2: // exact
                    $like_expression_array[] = " LIKE '%" . $searchstr . "%'";
                    break;
            }
            $ANDOR = ($search_mode == 0 ? ' AND ' : ' OR ');

            switch ($search_area) {
                case 0: // torrent name
                    foreach ($like_expression_array as &$like_expression_array_element) {
                        $like_expression_array_element = '(torrents.name' . $like_expression_array_element . ' OR torrents.small_descr' . $like_expression_array_element . ')';
                    }
                    unset($like_expression_array_element);
                    $wherea[] = implode($ANDOR, $like_expression_array);
                    break;
                case 1: // torrent description
                    foreach ($like_expression_array as &$like_expression_array_element) {
                        $like_expression_array_element = 'torrent_extras.descr' . $like_expression_array_element;
                    }
                    unset($like_expression_array_element);
                    $wherea[] = implode($ANDOR, $like_expression_array);
                    break;
                case 3: // torrent uploader
                    foreach ($like_expression_array as &$like_expression_array_element) {
                        $like_expression_array_element = 'users.username' . $like_expression_array_element;
                    }
                    unset($like_expression_array_element);
                    if (! isset($curUser)) {
                        $wherea[] = implode($ANDOR, $like_expression_array) . " AND torrents.anonymous = 'no'";
                    } else {
                        if (user_can('torrentmanage')) {
                            $wherea[] = implode($ANDOR, $like_expression_array);
                        } else {
                            $wherea[] = '(' . implode($ANDOR, $like_expression_array) . " AND torrents.anonymous = 'no') OR (" . implode($ANDOR, $like_expression_array) . " AND torrents.anonymous = 'yes' AND users.id=" . $curUser['id'] . ')';
                        }
                    }
                    break;
                case 4: // imdb url
                    foreach ($like_expression_array as &$like_expression_array_element) {
                        $like_expression_array_element = 'torrents.url' . $like_expression_array_element;
                    }
                    unset($like_expression_array_element);
                    $wherea[] = implode($ANDOR, $like_expression_array);
                    break;
                default: // unknown
                    $search_area = 0;
                    $wherea[] = "torrents.name LIKE '%" . $searchstr . "%'";
                    write_log('User ' . $curUser['username'] . ',' . $curUser['ip'] . ' is hacking search_area field in' . ($_SERVER['SCRIPT_NAME'] ?? ''), 'mod');
            }
            $addparam .= 'search_area=' . $search_area . '&';
            $addparam .= 'search=' . rawurlencode($searchstr) . '&' . $notnewword;
            $addparam .= 'search_mode=' . $search_mode . '&';
        }

        // approval status
        $approvalStatusNoneVisible = get_setting('torrent.approval_status_none_visible');
        $approvalStatusIconEnabled = get_setting('torrent.approval_status_icon_enabled');
        $approvalStatus = null;
        $showApprovalStatusFilter = false;
        if ($approvalStatusIconEnabled == 'yes' || (user_can('torrent-approval') && $approvalStatusNoneVisible == 'no')) {
            $showApprovalStatusFilter = true;
        }
        if ($showApprovalStatusFilter && isset($_REQUEST['approval_status']) && is_numeric($_REQUEST['approval_status'])) {
            $approvalStatus = intval($_REQUEST['approval_status']);
            $wherea[] = "torrents.approval_status = $approvalStatus";
            $searchParams['approval_status'] = $approvalStatus;
            $addparam .= "approval_status={$approvalStatus}&";
        } elseif ($approvalStatusNoneVisible == 'no' && ! user_can('torrent-approval')) {
            $wherea[] = 'torrents.approval_status = ' . \App\Models\Torrent::APPROVAL_STATUS_ALLOW;
            $searchParams['approval_status'] = \App\Models\Torrent::APPROVAL_STATUS_ALLOW;
        }

        // size / seeders / leechers / times_completed / added ranges
        if (isset($_GET['size_begin']) && ctype_digit($_GET['size_begin'])) {
            $wherea[] = 'torrents.size >= ' . intval($_GET['size_begin']) * 1024 * 1024 * 1024;
            $addparam .= 'size_begin=' . intval($_GET['size_begin']) . '&';
        }
        if (isset($_GET['size_end']) && ctype_digit($_GET['size_end'])) {
            $wherea[] = 'torrents.size <= ' . intval($_GET['size_end']) * 1024 * 1024 * 1024;
            $addparam .= 'size_end=' . intval($_GET['size_end']) . '&';
        }
        if (isset($_GET['seeders_begin']) && ctype_digit($_GET['seeders_begin'])) {
            $wherea[] = 'torrents.seeders >= ' . (int) $_GET['seeders_begin'];
            $addparam .= 'seeders_begin=' . intval($_GET['seeders_begin']) . '&';
        }
        if (isset($_GET['seeders_end']) && ctype_digit($_GET['seeders_end'])) {
            $wherea[] = 'torrents.seeders <= ' . (int) $_GET['seeders_end'];
            $addparam .= 'seeders_end=' . intval($_GET['seeders_end']) . '&';
        }
        if (isset($_GET['leechers_begin']) && ctype_digit($_GET['leechers_begin'])) {
            $wherea[] = 'torrents.leechers >= ' . (int) $_GET['leechers_begin'];
            $addparam .= 'leechers_begin=' . intval($_GET['leechers_begin']) . '&';
        }
        if (isset($_GET['leechers_end']) && ctype_digit($_GET['leechers_end'])) {
            $wherea[] = 'torrents.leechers <= ' . (int) $_GET['leechers_end'];
            $addparam .= 'leechers_end=' . intval($_GET['leechers_end']) . '&';
        }
        if (isset($_GET['times_completed_begin']) && ctype_digit($_GET['times_completed_begin'])) {
            $wherea[] = 'torrents.times_completed >= ' . (int) $_GET['times_completed_begin'];
            $addparam .= 'times_completed_begin=' . intval($_GET['times_completed_begin']) . '&';
        }
        if (isset($_GET['times_completed_end']) && ctype_digit($_GET['times_completed_end'])) {
            $wherea[] = 'torrents.times_completed <= ' . (int) $_GET['times_completed_end'];
            $addparam .= 'times_completed_end=' . intval($_GET['times_completed_end']) . '&';
        }
        if (isset($_GET['added_begin']) && ! empty($_GET['added_begin'])) {
            $wherea[] = 'torrents.added >= ' . sqlesc($_GET['added_begin']);
            $addparam .= 'added_begin=' . $_GET['added_begin'] . '&';
        }
        if (isset($_GET['added_end']) && ! empty($_GET['added_end'])) {
            $wherea[] = 'torrents.added <= ' . sqlesc(\Carbon\Carbon::parse($_GET['added_end'])->endOfDay()->toDateTimeString());
            $addparam .= 'added_end=' . $_GET['added_end'] . '&';
        }

        $where = implode(' AND ', $wherea);

        if ($wherecatin) {
            $where .= ($where ? ' AND ' : '') . 'category IN(' . $wherecatin . ')';
        }
        if ($showsubcat) {
            if ($wheresourcein) {
                $where .= ($where ? ' AND ' : '') . 'source IN(' . $wheresourcein . ')';
            }
            if ($wheremediumin) {
                $where .= ($where ? ' AND ' : '') . 'medium IN(' . $wheremediumin . ')';
            }
            if ($wherecodecin) {
                $where .= ($where ? ' AND ' : '') . 'codec IN(' . $wherecodecin . ')';
            }
            if ($wherestandardin) {
                $where .= ($where ? ' AND ' : '') . 'standard IN(' . $wherestandardin . ')';
            }
            if ($whereprocessingin) {
                $where .= ($where ? ' AND ' : '') . 'processing IN(' . $whereprocessingin . ')';
            }
            if ($whereteamin) {
                $where .= ($where ? ' AND ' : '') . 'team IN(' . $whereteamin . ')';
            }
            if ($whereaudiocodecin) {
                $where .= ($where ? ' AND ' : '') . 'audiocodec IN(' . $whereaudiocodecin . ')';
            }
        }
        if (! empty($whereothera)) {
            $where .= ($where ? ' AND ' : '') . implode(' AND ', $whereothera);
        }

        $tagFilter = '';
        $tagId = intval($_REQUEST['tag_id'] ?? 0);
        if ($tagId > 0) {
            $tagFilter = " inner join torrent_tags on torrents.id = torrent_tags.torrent_id and torrent_tags.tag_id = ${tagId} ";
            $addparam .= "tag_id={$tagId}&";
        }
        $torrentExtraFilter = '';
        if ($search_area == 1) {
            $torrentExtraFilter = ' inner join torrent_extras on torrents.id = torrent_extras.torrent_id ';
        }

        if ($allsec == 1 || $GLOBALS['enablespecial'] != 'yes') {
            $where = $where != '' ? "WHERE $where " : '';
            $sql = 'SELECT COUNT(*) FROM torrents ' . ($search_area == 3 || $column == 'owner' ? 'LEFT JOIN users ON torrents.owner = users.id ' : '') . $tagFilter . $torrentExtraFilter . $where;
        } else {
            $where = $where != '' ? "WHERE $where" : '';
            $sql = 'SELECT COUNT(*) FROM torrents ' . ($search_area == 3 || $column == 'owner' ? 'LEFT JOIN users ON torrents.owner = users.id ' : '') . $tagFilter . $torrentExtraFilter . $where;
        }

        $count = 0;
        $resultFromSearchRep = [];
        if ($shouldUseMeili) {
            $searchRep = new \App\Repositories\MeiliSearchRepository();
            $resultFromSearchRep = $searchRep->search($searchParams, $curUser['id']);
            $count = $resultFromSearchRep['total'];
        } else {
            do_log('[BEFORE_TORRENT_COUNT_SQL]', 'debug');
            $res = sql_query($sql);
            do_log("[AFTER_TORRENT_COUNT_SQL] $sql", 'debug');
            while ($row = mysql_fetch_array($res)) {
                $count += $row[0];
            }
        }

        $maxPageSize = 100;
        if (! empty($_GET['pageSize'])) {
            $torrentsperpage = $_GET['pageSize'];
        } elseif ($curUser['torrentsperpage']) {
            $torrentsperpage = (int) $curUser['torrentsperpage'];
        } elseif ($GLOBALS['torrentsperpage_main']) {
            $torrentsperpage = $GLOBALS['torrentsperpage_main'];
        } else {
            $torrentsperpage = $maxPageSize;
        }
        $torrentsperpage = min($maxPageSize, $torrentsperpage);

        $pagertop = '';
        $pagerbottom = '';
        $torrentTableHtml = '';
        $noResultsHtml = '';
        $res = null;
        if ($count) {
            if (isset($searchstr) && (! isset($_GET['notnewword']) || ! $_GET['notnewword'])) {
                insert_suggest($searchstr, $curUser['id']);
            }
            if ($addparam != '') {
                if ($pagerlink != '') {
                    if ($addparam[strlen($addparam) - 1] != ';') {
                        $addparam = $addparam . '&' . $pagerlink;
                    } else {
                        $addparam = $addparam . $pagerlink;
                    }
                }
            } else {
                $addparam = $pagerlink;
            }

            list($pagertop, $pagerbottom, $limit, $offset, $size, $page) = pager($torrentsperpage, $count, '?' . $addparam);
            $fieldsStr = implode(', ', \App\Models\Torrent::getFieldsForList(true));
            $query = "SELECT $fieldsStr, $sectiontype as search_box_id FROM torrents " . ($search_area == 3 || $column == 'owner' ? 'LEFT JOIN users ON torrents.owner = users.id ' : '') . "$tagFilter $torrentExtraFilter $where $orderby $limit";
            if (! $shouldUseMeili) {
                do_log('[BEFORE_TORRENT_LIST_SQL]', 'debug');
                $res = sql_query($query);
                do_log("[AFTER_TORRENT_LIST_SQL] $query", 'debug');
            }
        }

        $rows = [];
        if ($count) {
            if ($shouldUseMeili) {
                $rows = $resultFromSearchRep['list'];
            } else {
                while ($row = mysql_fetch_assoc($res)) {
                    $rows[] = $row;
                }
            }
            $rows = apply_filter('torrent_list', $rows, $page, $sectiontype, $_GET['search'] ?? '');
            $variant = 'bookmarks';
            if ($sectiontype == $GLOBALS['browsecatmode']) {
                $variant = 'torrents';
            } elseif ($sectiontype == $GLOBALS['specialcatmode']) {
                $variant = 'music';
            }
            $torrentTableHtml = $this->capture(function () use ($rows, $variant, $sectiontype) {
                torrenttable($rows, $variant, $sectiontype);
            });
        } else {
            if (isset($searchstr)) {
                $noResultsHtml = $this->capture(function () use ($lang_torrents, $searchstr_ori) {
                    print('<br />');
                    stdmsg($lang_torrents['std_search_results_for'] . $searchstr_ori . '"', $lang_torrents['std_try_again']);
                });
            } else {
                $noResultsHtml = $this->capture(function () use ($lang_torrents) {
                    stdmsg($lang_torrents['std_nothing_found'], $lang_torrents['std_no_active_torrents']);
                });
            }
        }

        // hot search
        $Cache->new_page('hot_search', 3670, true);
        if (! $Cache->get_page()) {
            $secs = 3 * 24 * 60 * 60;
            $dt = sqlesc(date('Y-m-d H:i:s', (TIMENOW - $secs)));
            $dt2 = sqlesc(date('Y-m-d H:i:s', (TIMENOW - $secs * 2)));
            sql_query('DELETE FROM suggest WHERE adddate <' . $dt2);
            $searchres = sql_query('SELECT keywords, COUNT(DISTINCT userid) as count FROM suggest WHERE adddate >' . $dt . ' GROUP BY keywords ORDER BY count DESC LIMIT 15');
            $hotcount = 0;
            $hotsearch = '';
            while ($searchrow = mysql_fetch_assoc($searchres)) {
                $hotsearch .= "<a href=\"" . htmlspecialchars('?search=' . rawurlencode($searchrow['keywords']) . '&notnewword=1') . "\"><u>" . htmlspecialchars($searchrow['keywords']) . "</u></a>&nbsp;&nbsp;";
                $hotcount += mb_strlen($searchrow['keywords'], 'UTF-8');
                if ($hotcount > 60) {
                    break;
                }
            }
            $Cache->add_whole_row();
            if ($hotsearch) {
                print('<tr><td class="embedded" colspan="3">&nbsp;&nbsp;' . $hotsearch . '</td></tr>');
            }
            $Cache->end_whole_row();
            $Cache->cache_page();
        }
        $hotSearchRow = (string) $Cache->next_row();

        $pageTitle = $lang_torrents['head_torrents'];
        if (isset($searchstr)) {
            $pageTitle = $lang_torrents['head_search_results_for'] . $searchstr_ori;
        } elseif ($sectiontype != $GLOBALS['browsecatmode']) {
            $pageTitle = $lang_torrents['head_special'];
        }

        $hotAndClassicHtml = $this->capture(function () {
            displayHotAndClassic();
        });

        $searchBoxRightTdStyle = 'padding: 1px;padding-left: 10px;white-space: nowrap';
        $queryString = $request->server('QUERY_STRING', '');

        return view('torrent.browse', compact(
            'lang_torrents',
            'curUser',
            'Advertisement',
            'pageTitle',
            'sectiontype',
            'allsec',
            'allTags',
            'tagRep',
            'include_dead',
            'special_state',
            'inclbookmarked',
            'showApprovalStatusFilter',
            'approvalStatus',
            'searchstr_ori',
            'filterInputWidth',
            'searchBoxRightTdStyle',
            'hotSearchRow',
            'hotAndClassicHtml',
            'count',
            'pagertop',
            'pagerbottom',
            'torrentTableHtml',
            'noResultsHtml',
            'queryString'
        ));
    }

    /**
     * Build the torrent row (with joins) the details page expects, mirroring
     * the legacy SELECT in public/details.php.
     */
    private function buildDetailsRow(int $id): ?array
    {
        $row = DB::table('torrents')
            ->select([
                'torrents.cache_stamp', 'torrents.sp_state', 'torrents.url', 'torrents.small_descr',
                'torrents.seeders', 'torrents.banned', 'torrents.leechers', 'torrents.info_hash',
                'torrents.filename', 'torrents.last_action', 'torrents.name', 'torrents.owner',
                'torrents.save_as', 'torrents.visible', 'torrents.size', 'torrents.added',
                'torrents.views', 'torrents.hits', 'torrents.times_completed', 'torrents.id',
                'torrents.type', 'torrents.numfiles', 'torrents.anonymous', 'torrents.hr',
                'torrents.promotion_until', 'torrents.promotion_time_type', 'torrents.approval_status',
                'torrents.price', 'categories.name AS cat_name', 'categories.mode as search_box_id',
                'sources.name AS source_name', 'media.name AS medium_name', 'codecs.name AS codec_name',
                'standards.name AS standard_name', 'processings.name AS processing_name',
                'teams.name AS team_name', 'audiocodecs.name AS audiocodec_name',
                'torrent_extras.descr', 'torrent_extras.nfo',
                DB::raw('LENGTH(torrent_extras.nfo) AS nfosz'),
                'torrent_extras.media_info as technical_info',
            ])
            ->leftJoin('categories', 'torrents.category', '=', 'categories.id')
            ->leftJoin('sources', 'torrents.source', '=', 'sources.id')
            ->leftJoin('media', 'torrents.medium', '=', 'media.id')
            ->leftJoin('codecs', 'torrents.codec', '=', 'codecs.id')
            ->leftJoin('standards', 'torrents.standard', '=', 'standards.id')
            ->leftJoin('processings', 'torrents.processing', '=', 'processings.id')
            ->leftJoin('teams', 'torrents.team', '=', 'teams.id')
            ->leftJoin('audiocodecs', 'torrents.audiocodec', '=', 'audiocodecs.id')
            ->leftJoin('torrent_extras', 'torrents.id', '=', 'torrent_extras.torrent_id')
            ->where('torrents.id', $id)
            ->first();

        return $row ? (array) $row : null;
    }

    /**
     * Render a single table row via the legacy tr() helper but return it as a
     * string instead of printing it.
     */
    private function row(string $x, string $y, int $noesc = 0): string
    {
        return (string) tr($x, $y, $noesc, '', true);
    }

    /**
     * Capture legacy helpers that print directly (commenttable, quickreply, ...).
     */
    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }

    public function searchBox()
    {
        $result = $this->repository->getSearchBox();

        return $this->success($result);
    }

    public function approvalPage(Request $request)
    {
        user_can('torrent-approval', true);
        $request->validate(['torrent_id' => 'required']);
        $torrentId = $request->torrent_id;
        $torrent = Torrent::query()->findOrFail($torrentId, Torrent::$commentFields);
        $denyReasons = TorrentDenyReason::query()->orderBy('priority', 'desc')->get();
        return view('torrent/approval', compact('torrent', 'denyReasons'));
    }

    public function approvalLogs(Request $request)
    {
        user_can('torrent-approval', true);
        $request->validate(['torrent_id' => 'required']);
        $torrentId = $request->torrent_id;
        $actionTypes = [
            TorrentOperationLog::ACTION_TYPE_APPROVAL_NONE,
            TorrentOperationLog::ACTION_TYPE_APPROVAL_ALLOW,
            TorrentOperationLog::ACTION_TYPE_APPROVAL_DENY,
        ];
        $records = TorrentOperationLog::query()
            ->with(['user'])
            ->where('torrent_id', $torrentId)
            ->whereIn('action_type', $actionTypes)
            ->orderBy('id', 'desc')
            ->paginate($request->limit);

        $resource = TorrentOperationLogResource::collection($records);

        return $this->success($resource);
    }

    public function approval(Request $request)
    {
        user_can('torrent-approval', true);
        $request->validate([
            'torrent_id' => 'required',
            'approval_status' => 'required',
        ]);
        $params = $request->all();
        $this->repository->approval(Auth::user(), $params);
        return $this->success($params);
    }

    public function queryByPiecesHash(Request $request)
    {
        $request->validate([
            'pieces_hash' => 'required|array',
        ]);
        $result = $this->repository->getPiecesHashCache($request->pieces_hash);
        return $this->success($result ?: (object)[]);
    }

}
