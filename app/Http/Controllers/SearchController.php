<?php

namespace App\Http\Controllers;

use App\Models\Bookmark;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Icon;
use App\Models\SearchBox;
use App\Models\SecondIcon;
use App\Models\Torrent;
use App\Models\TorrentExtra;
use App\Models\TorrentTag;
use App\Models\User;
use App\Repositories\MeiliSearchRepository;
use App\Repositories\SearchRepository;
use App\Repositories\SeedBoxRepository;
use App\Repositories\TagRepository;
use App\Repositories\TorrentRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Nexus\Database\NexusDB;

/**
 * Torrent search page, replaces legacy public/search.php (Phase 2 P2).
 *
 * Ports the legacy query + the huge torrenttable() renderer. The table is
 * produced by torrentTable(), which mirrors torrenttable() but feeds rows from
 * Eloquent models instead of the legacy $Cache/sql_query helpers.
 */
class SearchController extends Controller
{
    public function index(Request $request)
    {
        $currentUser = Auth::guard('nexus')->user();
        $lang = get_legacy_lang_file('torrents');
        $langFunctions = get_legacy_lang_file('functions');

        $GLOBALS['CURUSER'] = $currentUser->toArray();
        $GLOBALS['lang_functions'] = $langFunctions;
        $GLOBALS['waitsystem'] = get_setting('main.waitsystem', 'no');
        $GLOBALS['showextinfo'] = ['imdb' => get_setting('main.showimdbinfo', 'no')];
        $GLOBALS['torrentmanage_class'] = get_setting('authority.torrentmanage', '');
        $GLOBALS['smalldescription_main'] = get_setting('main.smalldescription', 'yes');
        $GLOBALS['enabletooltip_tweak'] = get_setting('tweak.enabletooltip', 'no');
        $GLOBALS['staffmem_class'] = get_setting('authority.staffmem', '');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        // promotion expiry globals used by get_torrent_promotion_append()
        $GLOBALS['expirehalfleech_torrent'] = get_setting('torrent.expirehalfleech');
        $GLOBALS['expirefree_torrent'] = get_setting('torrent.expirefree');
        $GLOBALS['expiretwoup_torrent'] = get_setting('torrent.expiretwoup');
        $GLOBALS['expiretwoupfree_torrent'] = get_setting('torrent.expiretwoupfree');
        $GLOBALS['expiretwouphalfleech_torrent'] = get_setting('torrent.expiretwouphalfleech');
        $GLOBALS['expirethirtypercentleech_torrent'] = get_setting('torrent.expirethirtypercentleech');

        $search = $request->input('search', '');
        $searchArea = $request->input('search_area', SearchRepository::SEARCH_AREA_TITLE);

        // approval status
        $approvalStatusNoneVisible = get_setting('torrent.approval_status_none_visible');
        $approvalStatus = null;
        if ($approvalStatusNoneVisible == 'no' && !user_can('torrent-approval')) {
            $approvalStatus = Torrent::APPROVAL_STATUS_ALLOW;
        }

        // section
        $modeArr = [SearchBox::getBrowseMode()];
        if (SearchBox::isSpecialEnabled() && user_can('view_special_torrent')) {
            $modeArr[] = SearchBox::getSpecialMode();
        }

        // see banned
        $banned = null;
        if (!user_can('seebanned')) {
            $banned = 'no';
        }

        $meilisearchEnabled = get_setting('meilisearch.enabled') == 'yes';
        $shouldUseMeili = $meilisearchEnabled && !empty($search);

        $count = 0;
        $rows = [];
        $resultFromSearchRep = [];
        if ($search) {
            $search = str_replace('.', ' ', $search);
            $searchArr = preg_split('/[\s]+/', $search, 10, PREG_SPLIT_NO_EMPTY);
            if ($shouldUseMeili) {
                $searchRep = new MeiliSearchRepository();
                $searchParams = $request->query();
                if ($approvalStatus != null) {
                    $searchParams['approval_status'] = $approvalStatus;
                }
                if ($banned != null) {
                    $searchParams['banned'] = $banned;
                }
                //Include dead
                $searchParams['incldead'] = 0;
                $searchParams['mode'] = $modeArr;
                $resultFromSearchRep = $searchRep->search($searchParams, $currentUser->id);
                $count = $resultFromSearchRep['total'];
            } else {
                $tableTorrent = 'torrents';
                $tableUser = 'users';
                $tableCategory = 'categories';
                $torrentQuery = NexusDB::table($tableTorrent)
                    ->join($tableCategory, "$tableTorrent.category", '=', "$tableCategory.id")
                    ->whereIn("$tableCategory.mode", $modeArr);

                if ($searchArea == SearchRepository::SEARCH_AREA_TITLE) {
                    foreach ($searchArr as $queryString) {
                        $q = "%{$queryString}%";
                        $torrentQuery->where(function (\Illuminate\Database\Query\Builder $query) use ($q, $tableTorrent) {
                            return $query->where("$tableTorrent.name", 'like', $q)->orWhere("$tableTorrent.small_descr", 'like', $q);
                        });
                    }
                } elseif ($searchArea == SearchRepository::SEARCH_AREA_DESC) {
                    foreach ($searchArr as $queryString) {
                        $q = "%{$queryString}%";
                        $torrentQuery->where("$tableTorrent.descr", 'like', $q);
                    }
                } elseif ($searchArea == SearchRepository::SEARCH_AREA_OWNER) {
                    $torrentQuery->join($tableUser, "$tableTorrent.owner", '=', "$tableUser.id");
                    foreach ($searchArr as $queryString) {
                        $q = "%{$queryString}%";
                        $torrentQuery->where("$tableUser.username", 'like', $q);
                    }
                } elseif ($searchArea == SearchRepository::SEARCH_AREA_IMDB) {
                    foreach ($searchArr as $queryString) {
                        $q = "%{$queryString}%";
                        $torrentQuery->where("$tableTorrent.url", 'like', $q);
                    }
                } else {
                    foreach ($searchArr as $queryString) {
                        $q = "%{$queryString}%";
                        $torrentQuery->where("$tableTorrent.name", 'like', $q);
                    }
                    write_log('User ' . $currentUser->username . ',' . $request->ip() . ' is hacking search_area field in search.php', 'mod');
                }
                if ($approvalStatus !== null) {
                    $torrentQuery->where("$tableTorrent.approval_status", $approvalStatus);
                }
                if ($banned !== null) {
                    $torrentQuery->where("$tableTorrent.banned", $banned);
                }

                $count = $torrentQuery->count();
            }
        }

        if ($currentUser->torrentsperpage) {
            $torrentsperpage = (int) $currentUser->torrentsperpage;
        } elseif ($torrentsperpageMain = get_setting('main.torrentsperpage')) {
            $torrentsperpage = (int) $torrentsperpageMain;
        } else {
            $torrentsperpage = 50;
        }

        // sorting by MarkoStamcar
        $column = 'id';
        $ascdesc = 'desc';
        $addparam = "?search=$search&search_area=$searchArea&";
        if ($request->get('sort') && $request->get('type')) {
            switch ($request->get('sort')) {
                case '1': $column = 'name'; break;
                case '2': $column = 'numfiles'; break;
                case '3': $column = 'comments'; break;
                case '4': $column = 'added'; break;
                case '5': $column = 'size'; break;
                case '6': $column = 'times_completed'; break;
                case '7': $column = 'seeders'; break;
                case '8': $column = 'leechers'; break;
                case '9': $column = 'owner'; break;
                default: $column = 'id'; break;
            }

            switch ($request->get('type')) {
                case 'asc': $ascdesc = 'ASC'; $linkascdesc = 'asc'; break;
                case 'desc': $ascdesc = 'DESC'; $linkascdesc = 'desc'; break;
                default: $ascdesc = 'DESC'; $linkascdesc = 'desc'; break;
            }

            $addparam .= 'sort=' . (int) $request->get('sort') . '&type=' . $linkascdesc . '&';
        }

        [$pagertop, $pagerbottom, $limit, $offset, $size, $page] = pager($torrentsperpage, $count, $addparam);

        $table = '';
        $showNoResult = false;
        if ($search && $count > 0) {
            if ($shouldUseMeili) {
                $rows = $resultFromSearchRep['list'];
            } else {
                $fieldsStr = implode(', ', Torrent::getFieldsForList(true));
                $rows = $torrentQuery->selectRaw("$fieldsStr, categories.mode as search_box_id")
                    ->forPage($page + 1, $torrentsperpage)
                    ->orderBy("$tableTorrent.$column", $ascdesc)
                    ->get()
                    ->map(function ($row) {
                        return (array) $row;
                    })
                    ->all();
            }
            $table = $this->torrentTable($rows);
        } else {
            $showNoResult = true;
        }

        return view('search', [
            'pageTitle' => nexus_trans('search.global_search'),
            'lang' => $lang,
            'pagertop' => $pagertop,
            'pagerbottom' => $pagerbottom,
            'table' => $table,
            'showNoResult' => $showNoResult,
            'search' => $search,
        ]);
    }

    /**
     * Port of the legacy torrenttable(); renders the same table markup but
     * sources category icons, second icons, last comments and bookmarks from
     * Eloquent models instead of the $Cache-backed sql_query helpers.
     */
    private function torrentTable(array $rows): string
    {
        $langFunctions = $GLOBALS['lang_functions'];
        $currentUser = $GLOBALS['CURUSER'];
        $waitsystem = $GLOBALS['waitsystem'];
        $smalldescriptionMain = $GLOBALS['smalldescription_main'];
        $enabletooltipTweak = $GLOBALS['enabletooltip_tweak'];

        $torrent = new \Nexus\Torrent\Torrent();
        $torrentRep = new TorrentRepository();
        $imdb = new \Nexus\Imdb\Imdb();
        $torrentIdArr = $ownerIdArr = [];
        foreach ($rows as $row) {
            $torrentIdArr[] = $row['id'];
            $ownerIdArr[] = $row['owner'];
        }
        unset($row);

        $enableImdb = get_setting('main.showimdbinfo') == 'yes';
        $enablePtGen = get_setting('main.enable_pt_gen_systemyes') == 'yes';

        $torrentSeedingLeechingStatus = $torrent->listLeechingSeedingStatus($currentUser['id'], $torrentIdArr);
        $ptGenInfo = TorrentExtra::query()->whereIn('torrent_id', $torrentIdArr)->pluck('pt_gen', 'torrent_id')->toArray();
        $tagRep = new TagRepository();
        $torrentTagCollection = TorrentTag::query()->whereIn('torrent_id', $torrentIdArr)->get();
        $torrentTagResult = $torrentTagCollection->groupBy('torrent_id');
        $showCover = false;
        $showSeedBoxIcon = false;

        $lastBrowse = $currentUser['last_browse'];
        $timeNow = time();
        if ($lastBrowse > $timeNow) {
            $lastBrowse = $timeNow;
        }
        $wait = 0;
        if ((int) get_user_class() < (int) User::CLASS_VIP && $waitsystem == 'yes') {
            $ratio = get_ratio($currentUser['id'], false);
            $gigs = $currentUser['uploaded'] / (1024 * 1024 * 1024);
            if ($gigs > 10) {
                if ($ratio < 0.4) {
                    $wait = 24;
                } elseif ($ratio < 0.5) {
                    $wait = 12;
                } elseif ($ratio < 0.6) {
                    $wait = 6;
                } elseif ($ratio < 0.8) {
                    $wait = 3;
                } else {
                    $wait = 0;
                }
            } else {
                $wait = 0;
            }
        }

        $html = '<table class="torrents" cellspacing="0" cellpadding="5" width="100%">';
        $html .= '<tr>';

        $countGet = 0;
        $oldlink = '';
        foreach (request()->query() as $getName => $getValue) {
            $getName = strip_tags(str_replace(['"', "'"], ['', ''], (string) $getName));
            $getValue = strip_tags(str_replace(['"', "'"], ['', ''], (string) $getValue));

            if ($getName != 'sort' && $getName != 'type') {
                if ($countGet > 0) {
                    $oldlink .= '&amp;' . $getName . '=' . $getValue;
                } else {
                    $oldlink .= $getName . '=' . $getValue;
                }
                $countGet++;
            }
        }
        if ($countGet > 0) {
            $oldlink = $oldlink . '&amp;';
        }
        $sort = request()->get('sort', '');
        $link = [];
        for ($i = 1; $i <= 9; $i++) {
            if ($sort == $i) {
                $link[$i] = (request()->get('type') == 'desc' ? 'asc' : 'desc');
            } else {
                $link[$i] = ($i == 1 ? 'asc' : 'desc');
            }
        }

        $html .= '<td class="colhead" style="padding: 0px">' . $langFunctions['col_type'] . '</td>';
        $html .= '<td class="colhead"><a href="?' . $oldlink . 'sort=1&amp;type=' . $link[1] . '">' . $langFunctions['col_name'] . '</a></td>';
        if ($wait) {
            $html .= '<td class="colhead">' . $langFunctions['col_wait'] . '</td>';
        }
        if ($currentUser['showcomnum'] != 'no') {
            $html .= '<td class="colhead"><a href="?' . $oldlink . 'sort=3&amp;type=' . $link[3] . '"><img class="comments" src="pic/trans.gif" alt="comments" title="' . $langFunctions['title_number_of_comments'] . '" /></a></td>';
        }
        $html .= '<td class="colhead"><a href="?' . $oldlink . 'sort=4&amp;type=' . $link[4] . '"><img class="time" src="pic/trans.gif" alt="time" title="' . ($currentUser['timetype'] != 'timealive' ? $langFunctions['title_time_added'] : $langFunctions['title_time_alive']) . '" /></a></td>';
        $html .= '<td class="colhead"><a href="?' . $oldlink . 'sort=5&amp;type=' . $link[5] . '"><img class="size" src="pic/trans.gif" alt="size" title="' . $langFunctions['title_size'] . '" /></a></td>';
        $html .= '<td class="colhead"><a href="?' . $oldlink . 'sort=7&amp;type=' . $link[7] . '"><img class="seeders" src="pic/trans.gif" alt="seeders" title="' . $langFunctions['title_number_of_seeders'] . '" /></a></td>';
        $html .= '<td class="colhead"><a href="?' . $oldlink . 'sort=8&amp;type=' . $link[8] . '"><img class="leechers" src="pic/trans.gif" alt="leechers" title="' . $langFunctions['title_number_of_leechers'] . '" /></a></td>';
        $html .= '<td class="colhead"><a href="?' . $oldlink . 'sort=6&amp;type=' . $link[6] . '"><img class="snatched" src="pic/trans.gif" alt="snatched" title="' . $langFunctions['title_number_of_snatched'] . '" /></a></td>';
        $html .= '<td class="colhead"><a href="?' . $oldlink . 'sort=9&amp;type=' . $link[9] . '">' . $langFunctions['col_uploader'] . '</a></td>';
        if (user_can('torrentmanage')) {
            $html .= '<td class="colhead">' . $langFunctions['col_action'] . '</td>';
        }
        $html .= '</tr>';

        $caticon = (int) ($currentUser['caticon'] ?? 1);
        $caticonRow = Icon::query()->find($caticon);
        $hasSecondicon = ($caticonRow && $caticonRow->secondicon == 'yes');

        $counter = 0;
        if ($smalldescriptionMain == 'no' || $currentUser['showsmalldescr'] == 'no') {
            $displaysmalldescr = false;
        } else {
            $displaysmalldescr = true;
        }

        $lastcomTooltip = [];
        $torrentTooltip = [];

        $bookmarkIdArr = Bookmark::query()->where('userid', $currentUser['id'])->pluck('torrentid')->toArray();
        $lastComments = collect();
        if ($enabletooltipTweak == 'yes' && $currentUser['showlastcom'] != 'no') {
            $lastCommentModels = Comment::query()
                ->whereIn('torrent', $torrentIdArr)
                ->orderByDesc('id')
                ->get()
                ->groupBy('torrent');
            foreach ($lastCommentModels as $tid => $comments) {
                $lastComments[$tid] = $comments->first();
            }
        }

        foreach ($rows as $row) {
            $id = $row['id'];
            $sphighlight = get_torrent_bg_color($row['sp_state'], $row['pos_state'], $row);
            $html .= '<tr' . $sphighlight . '>';

            $html .= '<td class="rowfollow nowrap" valign="middle" style=\'padding: 0px\'>';
            if (isset($row['category'])) {
                $html .= $this->categoryImage($row['category'], '?');
                if ($hasSecondicon) {
                    $html .= $this->secondIcon($row);
                }
            } else {
                $html .= '-';
            }
            $html .= '</td>';

            //torrent name
            $dispname = trim($row['name']);
            $shortTorrentNameAlt = '';
            $mouseovertorrent = '';
            $tooltipblock = '';
            $hasTooltip = false;
            if ($enabletooltipTweak == 'yes') {
                $tooltiptype = $currentUser['tooltip'];
            } else {
                $tooltiptype = 'off';
            }
            switch ($tooltiptype) {
                case 'minorimdb': {
                    if ($GLOBALS['showextinfo']['imdb'] == 'yes' && $row['url']) {
                        $url = $row['url'];
                        $cache = $row['cache_stamp'];
                        $type = 'minor';
                        $hasTooltip = true;
                    }
                    break;
                }
                case 'medianimdb': {
                    if ($GLOBALS['showextinfo']['imdb'] == 'yes' && $row['url']) {
                        $url = $row['url'];
                        $cache = $row['cache_stamp'];
                        $type = 'median';
                        $hasTooltip = true;
                    }
                    break;
                }
                case 'off': break;
            }
            if (!$hasTooltip) {
                $shortTorrentNameAlt = 'title="' . htmlspecialchars($dispname) . '"';
            } else {
                $torrentTooltip[$counter]['id'] = 'torrent_' . $counter;
                $torrentTooltip[$counter]['content'] = '';
                $mouseovertorrent = "onmouseover=\"get_ext_info_ajax('" . $torrentTooltip[$counter]['id'] . "','" . $url . "','" . $cache . "','" . $type . "'); domTT_activate(this, event, 'content', document.getElementById('" . $torrentTooltip[$counter]['id'] . "'), 'trail', false, 'delay',600,'lifetime',6000,'fade','both','styleClass','niceTitle', 'fadeMax',87, 'maxWidth', 500);\"";
            }
            $countDispname = mb_strlen($dispname, 'UTF-8');
            if (!$displaysmalldescr || $row['small_descr'] == '') {
                // maximum length of torrent name
                $maxLengthOfTorrentName = 200;
            } elseif ($currentUser['fontsize'] == 'large') {
                $maxLengthOfTorrentName = 120;
            } elseif ($currentUser['fontsize'] == 'small') {
                $maxLengthOfTorrentName = 160;
            } else {
                $maxLengthOfTorrentName = 140;
            }

            if ($countDispname > $maxLengthOfTorrentName) {
                $dispname = mb_substr($dispname, 0, $maxLengthOfTorrentName - 2, 'UTF-8') . '..';
            }
            if ($currentUser['appendsticky'] == 'yes') {
                $posStates = Torrent::listPosStates();
                $stickyicon = str_repeat('<img class="sticky" src="pic/trans.gif" alt="Sticky" title="' . $posStates[$row['pos_state']]['text'] . '" />&nbsp;', $posStates[$row['pos_state']]['icon_counts'] ?? 0);
            } else {
                $stickyicon = '';
            }
            $stickyicon = apply_filter('sticky_icon', $stickyicon, $row);
            $spTorrent = get_torrent_promotion_append($row['sp_state'], '', true, $row['added'], $row['promotion_time_type'], $row['promotion_until'], $row['__ignore_global_sp_state'] ?? false);
            $hrImg = get_hr_img($row, $row['search_box_id']);

            //cover
            $coverSrc = $tdCover = '';
            if ($showCover) {
                if (!empty($row['cover'])) {
                    $coverSrc = $row['cover'];
                }
                if (empty($coverSrc) && !empty($row['url'])) {
                    $imdbId = parse_imdb_id($row['url']);
                    if ($imdbId) {
                        $coverSrc = $imdb->getMovieCover($imdbId);
                    }
                }
                $tdCover = sprintf('<td class="embedded" style="text-align: center;width: 46px;height: 46px"><img src="pic/misc/spinner.svg" data-src="%s" class="nexus-lazy-load" style="max-height: 46px;max-width: 46px" /></td>', $coverSrc);
            }

            $html .= '<td class="rowfollow" width="100%" align="left" style=\'padding: 0px\'><table class="torrentname" width="100%"><tr' . $sphighlight . '>' . $tdCover . '<td class="embedded" style=\'padding-left: 5px\'>' . $stickyicon . '<a ' . $shortTorrentNameAlt . ' ' . $mouseovertorrent . ' href="details.php?id=' . $id . '&amp;hit=1"><b>' . htmlspecialchars($dispname) . '</b></a>';
            $pickedTorrent = '';
            if ($currentUser['appendpicked'] != 'no') {
                if ($row['picktype'] == 'hot') {
                    $pickedTorrent = ' <b>[<font class=\'hot\'>' . $langFunctions['text_hot'] . '</font>]</b>';
                } elseif ($row['picktype'] == 'classic') {
                    $pickedTorrent = ' <b>[<font class=\'classic\'>' . $langFunctions['text_classic'] . '</font>]</b>';
                } elseif ($row['picktype'] == 'recommended') {
                    $pickedTorrent = ' <b>[<font class=\'recommended\'>' . $langFunctions['text_recommended'] . '</font>]</b>';
                }
            }
            if ($currentUser['appendnew'] != 'no' && strtotime($row['added']) >= $lastBrowse) {
                $html .= '<b> (<font class=\'new\'>' . $langFunctions['text_new_uppercase'] . '</font>)</b>';
            }

            $bannedTorrent = ($row['banned'] == 'yes' ? ' <b>(<font class="striking">' . $langFunctions['text_banned'] . '</font>)</b>' : '');
            $spTorrentSub = get_torrent_promotion_append_sub($row['sp_state'], '', true, $row['added'], $row['promotion_time_type'], $row['promotion_until'], $row['__ignore_global_sp_state'] ?? false);
            $approvalStatusIcon = $torrentRep->renderApprovalStatus($row['approval_status']);
            $seedBoxIcon = '';
            $paidIcon = $torrentRep->getPaidIcon($row);
            $titleSuffix = $bannedTorrent . $paidIcon . $pickedTorrent . $spTorrent . $spTorrentSub . $hrImg . $seedBoxIcon . $approvalStatusIcon;
            $titleSuffix = apply_filter('torrent_title_suffix', $titleSuffix, $row);
            $html .= $titleSuffix;

            //render tags
            $tagOwns = $torrentTagResult->get($id);
            if ($tagOwns) {
                $tags = $tagRep->renderSpan($row['search_box_id'], $tagOwns->pluck('tag_id')->toArray());
            } else {
                $tags = '';
            }

            if ($displaysmalldescr) {
                //small descr
                $dissmallDescr = trim($row['small_descr']);
                $countDissmallDescr = mb_strlen($dissmallDescr, 'UTF-8');
                $maxLenghtOfSmallDescr = $maxLengthOfTorrentName;
                if ($countDissmallDescr > $maxLenghtOfSmallDescr) {
                    $dissmallDescr = mb_substr($dissmallDescr, 0, $maxLenghtOfSmallDescr - 2, 'UTF-8') . '..';
                }
                $dissmallDescr = $tags . htmlspecialchars($dissmallDescr);
                $html .= ($dissmallDescr == '' ? '' : '<br />' . $dissmallDescr);
            } else {
                $html .= $tags ? '<br />' . $tags : '';
            }
            //progress bar
            if (isset($torrentSeedingLeechingStatus[$row['id']])) {
                $html .= $torrent->renderProgressBar($torrentSeedingLeechingStatus[$row['id']]['active_status'], $torrentSeedingLeechingStatus[$row['id']]['progress']);
            }
            $html .= '</td>';

            if ($enableImdb || $enablePtGen) {
                $html .= $torrent->renderTorrentsPageAverageRating($row, $ptGenInfo[$row['id']] ?? []);
            }
            $act = '';
            if ($currentUser['dlicon'] != 'no' && $currentUser['downloadpos'] != 'no') {
                $act .= '<a href="download.php?id=' . $id . '"><img class="download" src="pic/trans.gif" style=\'padding-bottom: 2px;\' alt="download" title="' . $langFunctions['title_download_torrent'] . '" /></a>';
            }
            if ($currentUser['bmicon'] == 'yes') {
                $bookmark = ' href="javascript: bookmark(' . $id . ',' . $counter . ');"';
                $act .= ($act ? '<br />' : '') . '<a id="bookmark' . $counter . '" ' . $bookmark . ' >' . (in_array($id, $bookmarkIdArr) ? $langFunctions['title_delbookmark_torrent'] : $langFunctions['title_bookmark_torrent']) . '</a>';
            }

            $html .= '<td width="20" class="embedded" style="text-align: right;padding-right: 5px" valign="middle">' . $act . '</td>';

            $html .= '</tr></table></td>';
            if ($wait) {
                $elapsed = floor((time() - strtotime($row['added'])) / 3600);
                if ($elapsed < $wait) {
                    $color = dechex(floor(127 * ($wait - $elapsed) / 48 + 128) * 65536);
                    $html .= '<td class="rowfollow nowrap"><a href="faq.php#id46"><font color="' . $color . '">' . number_format($wait - $elapsed) . $langFunctions['text_h'] . '</font></a></td>';
                } else {
                    $html .= '<td class="rowfollow nowrap">' . $langFunctions['text_none'] . '</td>';
                }
            }

            if ($currentUser['showcomnum'] != 'no') {
                $html .= '<td class="rowfollow">';
                $nl = '<br />';
                if (!$row['comments']) {
                    $html .= '<a href="comment.php?action=add&amp;pid=' . $id . '&amp;type=torrent" title="' . $langFunctions['title_add_comments'] . '">' . $row['comments'] . '</a>';
                } else {
                    if ($enabletooltipTweak == 'yes' && $currentUser['showlastcom'] != 'no') {
                        $lastcom = $lastComments[$id] ?? null;
                        $hasnewcom = false;
                        $onmouseover = '';
                        if ($lastcom) {
                            $timestamp = strtotime($lastcom->added);
                            $hasnewcom = ($lastcom->user != $currentUser['id'] && $timestamp >= $lastBrowse);
                            if ($currentUser['timetype'] != 'timealive') {
                                $lastcomtime = $langFunctions['text_at_time'] . $lastcom->added;
                            } else {
                                $lastcomtime = $langFunctions['text_blank'] . gettime($lastcom->added, true, false, true);
                            }
                            $lastcomTooltip[$counter]['id'] = 'lastcom_' . $counter;
                            $lastcomTooltip[$counter]['content'] = ($hasnewcom ? '<b>(<font class=\'new\'>' . $langFunctions['text_new_uppercase'] . '</font>)</b> ' : '') . $langFunctions['text_last_commented_by'] . get_username($lastcom->user) . $lastcomtime . '<br />' . format_comment(mb_substr($lastcom->text, 0, 100, 'UTF-8') . (mb_strlen($lastcom->text, 'UTF-8') > 100 ? ' ......' : ''), true, false, false, true, 600, false, false);
                            $onmouseover = "onmouseover=\"domTT_activate(this, event, 'content', document.getElementById('" . $lastcomTooltip[$counter]['id'] . "'), 'trail', false, 'delay', 500,'lifetime',3000,'fade','both','styleClass','niceTitle','fadeMax', 87,'maxWidth', 400);\"";
                        }
                    } else {
                        $hasnewcom = false;
                        $onmouseover = '';
                    }
                    $html .= '<b><a href="details.php?id=' . $id . '&amp;hit=1&amp;cmtpage=1#startcomments" ' . $onmouseover . '>' . ($hasnewcom ? '<font class=\'new\'>' : '') . $row['comments'] . ($hasnewcom ? '</font>' : '') . '</a></b>';
                }
                $html .= '</td>';
            }

            $time = $row['added'];
            $time = gettime($time, false, true);
            $html .= '<td class="rowfollow nowrap">' . $time . '</td>';

            //size
            $html .= '<td class="rowfollow">' . mksize_compact($row['size']) . '</td>';

            if ($row['seeders']) {
                $ratio = ($row['leechers'] ? ($row['seeders'] / $row['leechers']) : 1);
                $ratiocolor = get_slr_color($ratio);
                $html .= '<td class="rowfollow" align="center"><b><a href="details.php?id=' . $id . '&amp;hit=1&amp;dllist=1#seeders">' . ($ratiocolor ? '<font color="' . $ratiocolor . '">' . number_format($row['seeders']) . '</font>' : number_format($row['seeders'])) . '</a></b></td>';
            } else {
                $html .= '<td class="rowfollow"><span class="' . linkcolor($row['seeders']) . '">' . number_format($row['seeders']) . '</span></td>';
            }

            if ($row['leechers']) {
                $html .= '<td class="rowfollow"><b><a href="details.php?id=' . $id . '&amp;hit=1&amp;dllist=1#leechers">' . number_format($row['leechers']) . '</a></b></td>';
            } else {
                $html .= '<td class="rowfollow">0</td>';
            }

            if ($row['times_completed'] >= 1) {
                $html .= '<td class="rowfollow"><a href="viewsnatches.php?id=' . $row['id'] . '"><b>' . number_format($row['times_completed']) . '</b></a></td>';
            } else {
                $html .= '<td class="rowfollow">' . number_format($row['times_completed']) . '</td>';
            }

            if ($row['anonymous'] == 'yes'
                && (user_can('viewanonymous') || (isset($row['owner']) && $row['owner'] == $currentUser['id']))
            ) {
                $html .= '<td class="rowfollow" align="center"><i>' . $langFunctions['text_anonymous'] . '</i><br />' . (isset($row['owner']) ? '(' . get_username($row['owner']) . ')' : '<i>' . $langFunctions['text_orphaned'] . '</i>') . '</td>';
            } elseif ($row['anonymous'] == 'yes') {
                $html .= '<td class="rowfollow"><i>' . $langFunctions['text_anonymous'] . '</i></td>';
            } else {
                $html .= '<td class="rowfollow">' . (isset($row['owner']) ? get_username($row['owner']) : '<i>' . $langFunctions['text_orphaned'] . '</i>') . '</td>';
            }

            if (user_can('torrentmanage')) {
                $actions = [];
                if (user_can('torrent-delete')) {
                    $actions[] = '<a href="' . htmlspecialchars('fastdelete.php?id=' . $row['id']) . '"><img class="staff_delete" src="pic/trans.gif" alt="D" title="' . $langFunctions['text_delete'] . '" /></a>';
                }
                $actions[] = '<a href="edit.php?returnto=' . rawurlencode(request()->getRequestUri()) . '&amp;id=' . $row['id'] . '"><img class="staff_edit" src="pic/trans.gif" alt="E" title="' . $langFunctions['text_edit'] . '" /></a>';
                $html .= sprintf('<td class="rowfollow">%s</td>', implode('<br />', $actions));
            }
            $html .= '</tr>';
            $counter++;
        }
        $html .= '</table>';
        if ($currentUser['appendpromotion'] == 'highlight') {
            $html .= '<p align="center"> ' . $langFunctions['text_promoted_torrents_note'] . '</p>';
        }

        if ($enabletooltipTweak == 'yes' && (!isset($currentUser) || $currentUser['showlastcom'] == 'yes')) {
            $html .= $this->tooltipContainer($lastcomTooltip, 400);
        }
        $html .= $this->tooltipContainer($torrentTooltip, 500);
        return $html;
    }

    private function tooltipContainer(array $idContentArr, int $width = 400): string
    {
        if (!count($idContentArr)) {
            return '';
        }
        $result = '<div style="display: none">';
        foreach ($idContentArr as $idContentArrEach) {
            $result .= '<div id="' . $idContentArrEach['id'] . '">' . $idContentArrEach['content'] . '</div>';
        }
        $result .= '</div>';
        return $result;
    }

    /**
     * Eloquent replacement for return_category_image().
     */
    private function categoryImage(int $categoryId, string $link = ''): string
    {
        $category = Category::query()->find($categoryId);
        if (!$category) {
            return '';
        }
        $catPath = '';
        $searchBox = $category->searchbox;
        if ($searchBox) {
            $catMode = trim($searchBox->name, '/');
        } else {
            $catMode = '';
        }
        $icon = $category->icon;
        if ($icon) {
            $catPath = sprintf('category/%s/%s', $catMode, trim($icon->folder, '/'));
            if ($icon->multilang == 'yes') {
                $catPath .= '/' . trim(get_langfolder_cookie(), '/');
            }
        }
        $img = '<img' . ($category->class_name ? ' class="' . $category->class_name . '"' : '') . ' src="pic/cattrans.gif" alt="' . $category->name . '" title="' . $category->name . '" style="background-image: url(pic/' . $catPath . '/' . $category->image . ');" />';
        if ($link) {
            $img = '<a href="' . $link . 'cat=' . $categoryId . '">' . $img . '</a>';
        }
        return $img;
    }

    /**
     * Eloquent replacement for get_second_icon() (legacy $Cache + sql_query).
     */
    private function secondIcon(array $row): string
    {
        $source = $row['source'] ?? 0;
        $medium = $row['medium'] ?? 0;
        $codec = $row['codec'] ?? 0;
        $standard = $row['standard'] ?? 0;
        $processing = $row['processing'] ?? 0;
        $team = $row['team'] ?? 0;
        $audiocodec = $row['audiocodec'] ?? 0;
        $mode = $row['search_box_id'] ?? 0;

        $sirow = SecondIcon::query()
            ->where(function ($query) use ($mode) {
                $query->where('mode', $mode)->orWhere('mode', 0);
            })
            ->where(function ($query) use ($source) {
                $query->where('source', $source)->orWhere('source', 0);
            })
            ->where(function ($query) use ($medium) {
                $query->where('medium', $medium)->orWhere('medium', 0);
            })
            ->where(function ($query) use ($codec) {
                $query->where('codec', $codec)->orWhere('codec', 0);
            })
            ->where(function ($query) use ($standard) {
                $query->where('standard', $standard)->orWhere('standard', 0);
            })
            ->where(function ($query) use ($processing) {
                $query->where('processing', $processing)->orWhere('processing', 0);
            })
            ->where(function ($query) use ($team) {
                $query->where('team', $team)->orWhere('team', 0);
            })
            ->where(function ($query) use ($audiocodec) {
                $query->where('audiocodec', $audiocodec)->orWhere('audiocodec', 0);
            })
            ->first();

        $catimgurl = $this->catFolder($row['category']);
        if (!$sirow) {
            return '<img src="pic/cattrans.gif" style="background-image: url(pic/' . $catimgurl . '/additional/notallowed.png);" title="Not Allowed" alt="Not Allowed" />';
        }
        return '<img' . ($sirow->class_name ? ' class="' . $sirow->class_name . '"' : '') . ' src="pic/cattrans.gif" style="background-image: url(pic/' . $catimgurl . '/additional/' . $sirow->image . ');" alt="' . $sirow->name . '" title="' . $sirow->name . '" />';
    }

    /**
     * Eloquent replacement for get_cat_folder().
     */
    private function catFolder(int $cat): string
    {
        $category = Category::query()->find($cat);
        if (!$category) {
            return '';
        }
        $catMode = '';
        $searchBox = $category->searchbox;
        if ($searchBox) {
            $catMode = trim($searchBox->name, '/');
        }
        $caticonrow = Icon::query()->find($category->icon_id ?: 1);
        if (!$caticonrow) {
            return sprintf('category/%s', $catMode);
        }
        $path = sprintf('category/%s/%s', $catMode, trim($caticonrow->folder, '/'));
        if ($caticonrow->multilang == 'yes') {
            $path .= '/' . trim($GLOBALS['CURLANGDIR'], '/');
        }
        return $path;
    }
}
