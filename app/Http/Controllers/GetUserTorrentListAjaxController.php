<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Claim;
use App\Models\Icon;
use App\Models\Snatch;
use App\Models\Torrent;
use App\Repositories\ClaimRepository;
use App\Repositories\SeedBoxRepository;
use App\Repositories\TorrentRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Nexus\Database\NexusDB;

/**
 * User torrent lists fragment, replaces legacy public/getusertorrentlistajax.php (Phase 2 P2).
 *
 * Loaded synchronously by the userdetails page via ajax.gets(); returns the
 * table HTML (no layout), with the same no-cache headers as the legacy page.
 */
class GetUserTorrentListAjaxController extends Controller
{
    private const TYPES = ['uploaded', 'seeding', 'leeching', 'completed', 'incomplete'];

    public function web(Request $request)
    {
        $currentUser = Auth::guard('nexus')->user();
        $lang = get_legacy_lang_file('getusertorrentlistajax');
        $langFunctions = get_legacy_lang_file('functions');
        $GLOBALS['lang_functions'] = $langFunctions;
        if ($currentUser) {
            $GLOBALS['CURUSER'] = $currentUser->toArray();
        }
        $GLOBALS['smalldescription_main'] = get_setting('main.smalldescription', 'yes');

        $id = (int) $request->get('userid', 0);
        $type = (string) $request->get('type', '');
        if (!in_array($type, self::TYPES, true)) {
            return response('');
        }
        if (!user_can('torrenthistory') && $id != ($currentUser->id ?? 0)) {
            abort(403);
        }

        $torrentRep = new TorrentRepository();
        $claimRep = new ClaimRepository();
        $seedBoxRep = new SeedBoxRepository();
        $claimTorrentTTL = Claim::getConfigTorrentTTL();

        [$baseQuery, $orderColumn] = $this->buildTypeQuery($type, $id, $currentUser);

        $count = 0;
        $totalSize = 0;
        if ($baseQuery !== null) {
            $cacheKey = sprintf('user:%s:type:%s:total_size', $id, $type);
            $page = (int) $request->get('page', 0);
            if ($page == 0) {
                $sum = (clone $baseQuery)->selectRaw('count(*) as count, COALESCE(sum(torrents.size), 0) as total_size')->first();
                $sumArr = ['count' => $sum->count, 'total_size' => $sum->total_size];
                NexusDB::cache_put($cacheKey, $sumArr);
            } else {
                $sumArr = NexusDB::remember($cacheKey, 3600, function () use ($baseQuery) {
                    $sum = (clone $baseQuery)->selectRaw('count(*) as count, COALESCE(sum(torrents.size), 0) as total_size')->first();
                    return ['count' => $sum->count, 'total_size' => $sum->total_size];
                });
            }
            $count = (int) $sumArr['count'];
            $totalSize = (int) $sumArr['total_size'];
        }

        $torrentList = '';
        $pagertop = $pagerbottom = '';
        $totalSizeThisPage = 0;
        if ($count > 0 && $baseQuery !== null) {
            $pageSize = 100;
            [$pagertop, $pagerbottom, $limit] = pager($pageSize, $count, 'getusertorrentlistajax.php?');
            $rows = (clone $baseQuery)
                ->select($this->typeFields($type))
                ->orderByRaw($orderColumn . ' ' . $limit)
                ->get()
                ->map(function ($row) {
                    return (array) $row;
                })
                ->all();
            [$torrentList, $totalSizeThisPage] = $this->makeTable($rows, $type, $id, $lang, $torrentRep, $claimRep, $seedBoxRep, $claimTorrentTTL);
        }

        $table = $pagertop . $torrentList . $pagerbottom;
        $hasData = false;
        $summary = sprintf('<b>%s</b>%s', $count, $lang['text_record'] . add_s($count));
        if (isset($totalSize) && $totalSize) {
            $hasData = true;
            $summary .= $lang['text_total_size'] . mksize($totalSize);
        } elseif ($count) {
            $hasData = true;
        }
        $output = '';
        if ($hasData) {
            $btnArr = apply_filter('user_seeding_top_btn', [], $currentUser->id ?? 0);
            $header = sprintf('<div style="display: flex;justify-content: space-between"><div>%s</div><div>%s</div></div>', $summary, implode('', $btnArr));
            $output = '<br/>' . $header . $table;
        } else {
            $output = $lang['text_no_record'];
        }

        return response($output)
            ->header('Expires', 'Mon, 26 Jul 1997 05:00:00 GMT')
            ->header('Last-Modified', gmdate('D, d M Y H:i:s') . 'GMT')
            ->header('Cache-Control', 'no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    /**
     * Returns [builder, orderSql]. The builder is shared by the count/sum query
     * and the paged list; null when the type is invalid.
     */
    private function buildTypeQuery(string $type, int $id, $currentUser): array
    {
        if ($type == 'uploaded') {
            $query = DB::table('torrents')
                ->leftJoin('categories', 'torrents.category', '=', 'categories.id')
                ->where('torrents.owner', $id);
            if (($currentUser->id ?? 0) != $id && !user_can('viewanonymous')) {
                $query->where('torrents.anonymous', 'no');
            }
            return [$query, 'torrents.id DESC'];
        }
        if ($type == 'seeding') {
            return [
                DB::table('peers')
                    ->leftJoin('torrents', 'peers.torrent', '=', 'torrents.id')
                    ->leftJoin('categories', 'torrents.category', '=', 'categories.id')
                    ->leftJoin('snatched', 'torrents.id', '=', 'snatched.torrentid')
                    ->where('peers.userid', $id)
                    ->where('snatched.userid', $id)
                    ->where('peers.seeder', 'yes'),
                'peers.id DESC',
            ];
        }
        if ($type == 'leeching') {
            return [
                DB::table('peers')
                    ->leftJoin('torrents', 'peers.torrent', '=', 'torrents.id')
                    ->leftJoin('categories', 'torrents.category', '=', 'categories.id')
                    ->leftJoin('snatched', 'torrents.id', '=', 'snatched.torrentid')
                    ->where('peers.userid', $id)
                    ->where('snatched.userid', $id)
                    ->where('peers.seeder', 'no'),
                'peers.id DESC',
            ];
        }
        if ($type == 'completed') {
            return [
                DB::table('torrents')
                    ->leftJoin('snatched', 'torrents.id', '=', 'snatched.torrentid')
                    ->leftJoin('categories', 'torrents.category', '=', 'categories.id')
                    ->where('snatched.finished', 'yes')
                    ->where('snatched.userid', $id)
                    ->where('torrents.owner', '!=', $id),
                'snatched.id DESC',
            ];
        }
        if ($type == 'incomplete') {
            return [
                DB::table('torrents')
                    ->leftJoin('snatched', 'torrents.id', '=', 'snatched.torrentid')
                    ->leftJoin('categories', 'torrents.category', '=', 'categories.id')
                    ->where('snatched.finished', 'no')
                    ->where('snatched.userid', $id)
                    ->where('torrents.owner', '!=', $id),
                'snatched.id DESC',
            ];
        }
        return [null, ''];
    }

    private function typeFields(string $type): array
    {
        switch ($type) {
            case 'uploaded':
                return [
                    'torrents.id AS torrent', 'torrents.name AS torrentname', 'torrents.small_descr',
                    'torrents.seeders', 'torrents.leechers', 'torrents.anonymous', 'torrents.banned',
                    'torrents.approval_status', 'categories.name AS catname', 'categories.image',
                    'torrents.category', 'torrents.sp_state', 'torrents.size', 'torrents.hr',
                    'torrents.added', 'torrents.owner AS userid', 'categories.mode AS search_box_id',
                ];
            case 'seeding':
            case 'leeching':
                return [
                    'peers.torrent', 'torrents.added', 'snatched.uploaded', 'snatched.downloaded',
                    'snatched.seedtime', 'torrents.name AS torrentname', 'torrents.small_descr',
                    'torrents.sp_state', 'torrents.banned', 'torrents.approval_status',
                    'categories.name AS catname', 'torrents.size', 'torrents.hr', 'categories.image',
                    'torrents.category', 'torrents.seeders', 'torrents.leechers', 'snatched.userid',
                    'categories.mode AS search_box_id', 'peers.peer_id', 'peers.agent', 'peers.port',
                    'peers.ipv4', 'peers.ipv6',
                ];
            case 'completed':
                return [
                    'torrents.id AS torrent', 'torrents.name AS torrentname', 'torrents.small_descr',
                    'categories.name AS catname', 'torrents.banned', 'torrents.approval_status',
                    'categories.image', 'torrents.category', 'torrents.sp_state', 'torrents.size',
                    'torrents.hr', 'torrents.added', 'snatched.uploaded', 'snatched.seedtime',
                    'snatched.leechtime', 'snatched.completedat', 'snatched.userid',
                    'categories.mode AS search_box_id',
                ];
            case 'incomplete':
                return [
                    'torrents.id AS torrent', 'torrents.name AS torrentname', 'torrents.small_descr',
                    'torrents.banned', 'torrents.approval_status', 'categories.name AS catname',
                    'categories.image', 'torrents.category', 'torrents.sp_state', 'torrents.size',
                    'torrents.hr', 'torrents.added', 'snatched.uploaded', 'snatched.downloaded',
                    'snatched.leechtime', 'snatched.seedtime', 'snatched.userid',
                    'categories.mode AS search_box_id',
                ];
            default:
                return ['torrents.id'];
        }
    }

    /**
     * Port of the legacy maketable(); renders the same columns and cells using
     * Eloquent-fed rows. $torrentRep/$claimRep/$seedBoxRep are injected to keep
     * a single repository instance across rows (matches the legacy behavior).
     */
    private function makeTable(array $results, string $mode, int $id, array $lang, TorrentRepository $torrentRep, ClaimRepository $claimRep, SeedBoxRepository $seedBoxRep, int $claimTorrentTTL): array
    {
        $currentUser = Auth::guard('nexus')->user();
        $langFunctions = $GLOBALS['lang_functions'];

        switch ($mode) {
            case 'uploaded':
                $showsize = $showsenum = $showlenum = $showuploaded = true;
                $showdownloaded = $showratio = $showletime = $showcotime = false;
                $showsetime = $showanonymous = $showtotalsize = true;
                $columncount = 8;
                $showActionClaim = $showClient = false;
                break;
            case 'seeding':
                $showsize = $showsenum = $showlenum = $showuploaded = $showdownloaded = $showratio = $showsetime = $showtotalsize = true;
                $showletime = $showcotime = $showanonymous = false;
                $columncount = 8;
                $showActionClaim = $showClient = true;
                break;
            case 'leeching':
                $showsize = $showsenum = $showlenum = $showuploaded = $showdownloaded = $showratio = $showtotalsize = true;
                $showsetime = $showletime = $showcotime = $showanonymous = false;
                $columncount = 8;
                $showActionClaim = false;
                $showClient = true;
                break;
            case 'completed':
                $showsize = $showuploaded = $showsetime = $showletime = $showcotime = true;
                $showsenum = $showlenum = $showdownloaded = $showratio = $showanonymous = $showtotalsize = false;
                $columncount = 8;
                $showActionClaim = true;
                $showClient = false;
                break;
            case 'incomplete':
                $showsize = $showuploaded = $showdownloaded = $showratio = $showletime = true;
                $showsenum = $showlenum = $showsetime = $showcotime = $showanonymous = $showtotalsize = false;
                $columncount = 7;
                $showActionClaim = false;
                $showClient = false;
                break;
            default:
                return ['', 0];
        }

        $shouldShowClient = false;
        if ($showClient && (user_can('userprofile') || ($currentUser && $currentUser->id == $id))) {
            $shouldShowClient = true;
        }

        $torrentIdArr = array_column($results, 'torrent');
        $seedTimeAndUploaded = collect();
        if ($mode == 'uploaded') {
            $seedTimeAndUploaded = Snatch::query()
                ->where('userid', $id)
                ->whereIn('torrentid', $torrentIdArr)
                ->select(['seedtime', 'uploaded', 'torrentid'])
                ->get()
                ->keyBy('torrentid');
        }
        $claimData = collect();
        if ($showActionClaim && $currentUser) {
            $claimData = Claim::query()
                ->where('uid', $currentUser->id)
                ->whereIn('torrent_id', $torrentIdArr)
                ->get()
                ->keyBy('torrent_id');
        }

        $ret = '<table border="1" cellspacing="0" cellpadding="5" width="100%"><tr><td class="colhead" style="padding: 0px">' . $lang['col_type'] . '</td><td class="colhead" align="center">' . $lang['col_name'] . '</td><td class="colhead" align="center">' . $lang['col_added'] . '</td>';
        if ($showsize) {
            $ret .= '<td class="colhead" align="center"><img class="size" src="pic/trans.gif" alt="size" title="' . $lang['title_size'] . '" /></td>';
        }
        if ($showsenum) {
            $ret .= '<td class="colhead" align="center"><img class="seeders" src="pic/trans.gif" alt="seeders" title="' . $lang['title_seeders'] . '" /></td>';
        }
        if ($showlenum) {
            $ret .= '<td class="colhead" align="center"><img class="leechers" src="pic/trans.gif" alt="leechers" title="' . $lang['title_leechers'] . '" /></td>';
        }
        if ($showuploaded) {
            $ret .= '<td class="colhead" align="center">' . $lang['col_uploaded'] . '</td>';
        }
        if ($showdownloaded) {
            $ret .= '<td class="colhead" align="center">' . $lang['col_downloaded'] . '</td>';
        }
        if ($showratio) {
            $ret .= '<td class="colhead" align="center">' . $lang['col_ratio'] . '</td>';
        }
        if ($showsetime) {
            $ret .= '<td class="colhead" align="center">' . $lang['col_se_time'] . '</td>';
        }
        if ($showletime) {
            $ret .= '<td class="colhead" align="center">' . $lang['col_le_time'] . '</td>';
        }
        if ($showcotime) {
            $ret .= '<td class="colhead" align="center">' . $lang['col_time_completed'] . '</td>';
        }
        if ($showanonymous) {
            $ret .= '<td class="colhead" align="center">' . $lang['col_anonymous'] . '</td>';
        }
        if ($shouldShowClient) {
            $ret .= sprintf('<td class="colhead" align="center">%s</td><td class="colhead" align="center">IP</td>', $lang['col_client']);
        }
        $ret .= sprintf('<td class="colhead" align="center">%s</td>', $langFunctions['std_action']);
        $ret .= '</tr>';

        $totalSize = 0;
        foreach ($results as $arr) {
            if ($mode == 'uploaded') {
                $seedData = $seedTimeAndUploaded->get($arr['torrent']);
                $arr['seedtime'] = $seedData ? $seedData->seedtime : 0;
                $arr['uploaded'] = $seedData ? $seedData->uploaded : 0;
            }
            $catimage = htmlspecialchars($arr['image']);
            $catname = htmlspecialchars($arr['catname']);

            $sphighlight = get_torrent_bg_color($arr['sp_state']);
            $bannedTorrent = ($arr['banned'] == 'yes' ? ' <b>(<font class="striking">' . $langFunctions['text_banned'] . '</font>)</b>' : '');
            $spTorrent = get_torrent_promotion_append($arr['sp_state'], '', false, '', 0, '', $arr['__ignore_global_sp_state'] ?? false);
            if ($showtotalsize) {
                $totalSize += $arr['size'];
            }

            $hrImg = get_hr_img($arr, $arr['search_box_id'] ?? null);
            $approvalStatusIcon = $torrentRep->renderApprovalStatus($arr['approval_status']);

            $dispname = $nametitle = htmlspecialchars($arr['torrentname']);
            $countDispname = mb_strlen($dispname, 'UTF-8');
            $maxLength = ($currentUser && $currentUser->fontsize == 'large' ? 70 : 80);
            if ($countDispname > $maxLength) {
                $dispname = mb_substr($dispname, 0, $maxLength, 'UTF-8') . '..';
            }
            $dissmallDescr = '';
            if ($GLOBALS['smalldescription_main'] == 'yes') {
                $dissmallDescr = htmlspecialchars(trim($arr['small_descr'] ?? ''));
                $countDissmallDescr = mb_strlen($dissmallDescr, 'UTF-8');
                if ($countDissmallDescr > 80) {
                    $dissmallDescr = mb_substr($dissmallDescr, 0, 80, 'UTF-8') . '..';
                }
            }
            $ret .= '<tr' . $sphighlight . '><td class="rowfollow nowrap" valign="middle" style="padding: 0px">' . $this->categoryImage($arr['category'], 'torrents.php?allsec=1&amp;') . "</td>\n" .
                '<td class="rowfollow" width="100%" align="left"><a href="' . htmlspecialchars('details.php?id=' . $arr['torrent'] . '&hit=1') . '" title="' . $nametitle . '"><b>' . $dispname . '</b></a>' . $bannedTorrent . $spTorrent . $hrImg . $approvalStatusIcon . ($dissmallDescr == '' ? '' : '<br />' . $dissmallDescr) . '</td>';
            $ret .= sprintf('<td class="rowfollow nowrap" align="center">%s<br/>%s</td>', substr($arr['added'], 0, 10), substr($arr['added'], 11));
            if ($showsize) {
                $ret .= '<td class="rowfollow" align="center">' . mksize_compact($arr['size']) . '</td>';
            }
            if ($showsenum) {
                $ret .= '<td class="rowfollow" align="center">' . $arr['seeders'] . '</td>';
            }
            if ($showlenum) {
                $ret .= '<td class="rowfollow" align="center">' . $arr['leechers'] . '</td>';
            }
            if ($showuploaded) {
                $ret .= '<td class="rowfollow" align="center">' . mksize_compact($arr['uploaded']) . '</td>';
            }
            if ($showdownloaded) {
                $ret .= '<td class="rowfollow" align="center">' . mksize_compact($arr['downloaded']) . '</td>';
            }
            if ($showratio) {
                if ($arr['downloaded'] > 0) {
                    $ratio = number_format($arr['uploaded'] / $arr['downloaded'], 3);
                    $ratio = '<font color="' . get_ratio_color($ratio) . '">' . $ratio . '</font>';
                } elseif ($arr['uploaded'] > 0) {
                    $ratio = 'Inf.';
                } else {
                    $ratio = '---';
                }
                $ret .= '<td class="rowfollow" align="center">' . $ratio . '</td>';
            }
            if ($showsetime) {
                $ret .= '<td class="rowfollow" align="center">' . mkprettytime($arr['seedtime']) . '</td>';
            }
            if ($showletime) {
                $ret .= '<td class="rowfollow" align="center">' . mkprettytime($arr['leechtime']) . '</td>';
            }
            if ($showcotime) {
                $ret .= '<td class="rowfollow" align="center">' . str_replace('&nbsp;', '<br />', gettime($arr['completedat'], false)) . '</td>';
            }
            if ($showanonymous) {
                $ret .= '<td class="rowfollow" align="center">' . $arr['anonymous'] . '</td>';
            }
            if ($shouldShowClient) {
                $ipArr = array_filter([$arr['ipv4'] ?? null, $arr['ipv6'] ?? null]);
                foreach ($ipArr as &$_ip) {
                    $_ip = sprintf('<span class="nowrap">%s</span>', $_ip . $seedBoxRep->renderIcon($_ip, $arr['userid']));
                }
                $ret .= sprintf(
                    '<td class="rowfollow" align="center">%s<br/>%s</td><td class="rowfollow" align="center">%s</td>',
                    get_agent($arr['peer_id'] ?? null, $arr['agent'] ?? ''), $arr['port'] ?? '',
                    implode('<br/>', $ipArr)
                );
            }
            $claimButton = '';
            if ($showActionClaim
                && Claim::getConfigIsEnabled()
                && \Carbon\Carbon::parse($arr['added'])->addDays($claimTorrentTTL)->lte(\Carbon\Carbon::now())
            ) {
                $claim = $claimData->get($arr['torrent']);
                if ($currentUser && $currentUser->id == $arr['userid']) {
                    $claimButton = $claimRep->buildActionButtons($arr['torrent'], $claim);
                } else {
                    if ($claim) {
                        $claimText = nexus_trans('claim.already_claimed');
                    } else {
                        $claimText = nexus_trans('claim.not_claim_yet');
                    }
                    $claimButton = sprintf('<button style="width: max-content;display: flex;align-items: center" disabled>%s</button>', $claimText);
                }
            }
            $ret .= sprintf('<td class="rowfollow" align="center">%s</td>', $claimButton);
            $ret .= "</tr>\n";
        }
        $ret .= '</table>' . "\n";
        return [$ret, $totalSize];
    }

    /**
     * Eloquent replacement for return_category_image() (legacy used $Cache +
     * sql_query). Renders the same cattrans.gif sprites markup.
     */
    private function categoryImage(int $categoryId, string $link = ''): string
    {
        $category = Category::query()->find($categoryId);
        if (!$category) {
            return '';
        }
        $catPath = '';
        $catMode = '';
        $searchBox = $category->searchbox;
        if ($searchBox) {
            $catMode = trim($searchBox->name, '/');
        }
        $icon = Icon::query()->find($category->icon_id ?: 1);
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
}