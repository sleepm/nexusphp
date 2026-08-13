<?php

namespace App\Http\Controllers;

use App\Http\Resources\PeerResource;
use App\Models\Peer;
use App\Models\Torrent;
use App\Models\User;
use App\Repositories\SeedBoxRepository;
use App\Repositories\TorrentRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PeerController extends Controller
{
    private $repository;

    public function __construct(TorrentRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Peer list fragment, replaces legacy public/viewpeerlist.php (Phase 2 P3).
     *
     * Loaded synchronously via ajax.gets() into the details page (#peerlist).
     * Renders the seeder/leecher tables and updates the live peers row counts.
     */
    public function web(Request $request)
    {
        $id = (int) $request->get('id', 0);
        if ($id <= 0 || !Auth::guard('nexus')->check()) {
            return response('');
        }
        $langViewpeerlist = get_legacy_lang_file('viewpeerlist');
        $langFunctions = get_legacy_lang_file('functions');
        $GLOBALS['lang_functions'] = $langFunctions;
        $seedBoxRep = new SeedBoxRepository();

        $torrent = Torrent::query()->find($id, ['id', 'seeders', 'leechers', 'owner', 'size', 'anonymous']);
        if (!$torrent) {
            return response('');
        }

        $seeders = $leechers = [];
        $seedersAndLeechers = apply_filter("torrent_seeder_leecher_list", [], $id);
        if (isset($seedersAndLeechers['seeders'], $seedersAndLeechers['leechers'])) {
            $seeders = $seedersAndLeechers['seeders'];
            $leechers = $seedersAndLeechers['leechers'];
            do_log("SEEDER_LEECHER_FROM_FILTER: torrent_seeder_leecher_list");
        } else {
            $peers = Peer::query()
                ->select(['id', 'seeder', 'finishedat', 'downloadoffset', 'uploadoffset', 'ip', 'ipv4', 'ipv6', 'port', 'uploaded', 'downloaded', 'to_go', 'started', 'connectable', 'agent', 'peer_id', 'last_action', 'userid'])
                ->where('torrent', $id)
                ->get();
            foreach ($peers as $peer) {
                $row = $peer->toArray();
                $row['st'] = $peer->started ? $peer->started->getTimestamp() : 0;
                $row['la'] = $peer->last_action ? $peer->last_action->getTimestamp() : 0;
                if ($peer->seeder == Peer::SEEDER_YES) {
                    $seeders[] = $row;
                } else {
                    $leechers[] = $row;
                }
            }
        }

        $seedersCount = count($seeders);
        $leechersCount = count($leechers);
        if ($torrent->seeders != $seedersCount || $torrent->leechers != $leechersCount) {
            $update = [
                'seeders' => $seedersCount,
                'leechers' => $leechersCount,
            ];
            $torrent->update($update);
            do_log("[UPDATE_TORRENT_SEEDERS_LEECHERS], torrent: $id, original: " . $torrent->toJson() . ", update: " . json_encode($update));
        }

        usort($seeders, function ($a, $b) {
            $x = $a['uploaded'];
            $y = $b['uploaded'];
            return $x == $y ? 0 : ($x < $y ? 1 : -1);
        });
        usort($leechers, function ($a, $b) {
            $x = $a['to_go'];
            $y = $b['to_go'];
            return $x == $y ? 0 : ($x < $y ? -1 : 1);
        });

        $isSeedBoxCaseWhens = [];
        $seederTable = $this->dltable($langViewpeerlist['text_seeders'], $seeders, $torrent->toArray(), $isSeedBoxCaseWhens);
        $leecherTable = $this->dltable($langViewpeerlist['text_leechers'], $leechers, $torrent->toArray(), $isSeedBoxCaseWhens);

        if (!empty($isSeedBoxCaseWhens) && get_setting('seed_box.enabled') == 'yes') {
            $sql = sprintf(
                "update peers set is_seed_box = case id %s end where id in (%s)",
                implode(' ', array_values($isSeedBoxCaseWhens)),
                implode(',', array_keys($isSeedBoxCaseWhens))
            );
            do_log("[IS_SEED_BOX], $sql");
            DB::statement($sql);
        }

        return response(view('viewpeerlist', [
            'seederTable' => $seederTable,
            'leecherTable' => $leecherTable,
        ]))->header('Content-Type', 'text/xml; charset=utf-8')
            ->header('Cache-Control', 'no-cache, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', 'Mon, 26 Jul 1997 05:00:00 GMT');
    }

    /**
     * Port of the legacy dltable() helper from public/viewpeerlist.php.
     */
    private function dltable($name, array $arr, array $torrent, array &$isSeedBoxCaseWhens): string
    {
        $lang = get_legacy_lang_file('viewpeerlist');
        $langFunctions = $GLOBALS['lang_functions'] ?? get_legacy_lang_file('functions');
        $seedBoxRep = new SeedBoxRepository();
        $currentUser = Auth::guard('nexus')->user();

        $s = "<b>" . count($arr) . " $name</b>\n";
        $showLocationColumn = get_setting('tweak.enablelocation') == 'yes' || user_can('userprofile');
        if (!count($arr)) {
            return $s;
        }
        $s .= "\n";
        $s .= "<table width=100% class=main border=1 cellspacing=0 cellpadding=3>\n";
        $s .= "<tr><td class=colhead align=center width=1%>" . $lang['col_user_ip'] . "</td>" .
            ($showLocationColumn ? "<td class=colhead align=center>" . $lang['col_location'] . "</td>" : "") .
            "<td class=colhead align=center width=1%>" . $lang['col_connectable'] . "</td>" .
            "<td class=colhead align=center width=1%>" . $lang['col_uploaded'] . "</td>" .
            "<td class=colhead align=center width=1%>" . $lang['col_rate'] . "</td>" .
            "<td class=colhead align=center width=1%>" . $lang['col_downloaded'] . "</td>" .
            "<td class=colhead align=center width=1%>" . $lang['col_rate'] . "</td>" .
            "<td class=colhead align=center width=1%>" . $lang['col_ratio'] . "</td>" .
            "<td class=colhead align=center width=1%>" . $lang['col_complete'] . "</td>" .
            "<td class=colhead align=center width=1%>" . $lang['col_connected'] . "</td>" .
            "<td class=colhead align=center width=1%>" . $lang['col_idle'] . "</td>" .
            "<td class=colhead align=center width=1%>" . $lang['col_client'] . "</td></tr>\n";
        $now = time();
        $num = 0;
        $privacyData = User::query()->whereIn('id', array_column($arr, 'userid'))->get(['id', 'privacy'])->keyBy('id');

        foreach ($arr as $e) {
            $privacy = $privacyData->get($e['userid'])->privacy ?? '';
            ++$num;

            $highlight = $currentUser && $currentUser->id == $e['userid'] ? " bgcolor=#BBAF9B" : "";
            $s .= "<tr$highlight>\n";
            $secs = max(1, ($e['la'] - $e['st']));
            $columnLocation = $usernameSeedBoxIcon = '';
            $isStrongPrivacy = $privacy == "strong" || ($torrent['anonymous'] == 'yes' && $e['userid'] == $torrent['owner']);
            $canView = user_can('viewanonymous') || ($currentUser && $e['userid'] == $currentUser->id);
            if ($showLocationColumn) {
                $columnLocationResult = $this->getLocationColumn($e, $isStrongPrivacy, $canView);
                $columnLocation = $columnLocationResult['td'];
                $isSeedBox = $columnLocationResult['is_seed_box'];
            } else {
                $usernameSeedBoxIcon = $this->getUsernameSeedBoxIcon($e);
                $isSeedBox = !empty($usernameSeedBoxIcon);
            }
            $isSeedBoxCaseWhens[$e['id']] = sprintf("when %s then %s", $e['id'], intval($isSeedBox));
            if ($isStrongPrivacy) {
                $columnUsername = "<td class=rowfollow align=left width=1%><i>" . $lang['text_anonymous'] . "</i>" . $usernameSeedBoxIcon;
                if ($canView) {
                    $columnUsername .= "<br />(" . get_username($e['userid']) . ")";
                }
                $columnUsername .= "</td>";
            } else {
                $columnUsername = "<td class=rowfollow align=left width=1%>" . get_username($e['userid']) . $usernameSeedBoxIcon . "</td>";
            }

            $s .= $columnUsername . $columnLocation;

            $s .= "<td class=rowfollow align=center width=1%><nobr>" . ($e['connectable'] == "yes" ? $lang['text_yes'] : "<font color=red>" . $lang['text_no'] . "</font>") . "</nobr></td>\n";
            $s .= "<td class=rowfollow align=center width=1%><nobr>" . mksize($e['uploaded']) . "</nobr></td>\n";

            $s .= "<td class=rowfollow align=center width=1%><nobr>" . mksize(($e['uploaded'] - $e['uploadoffset']) / $secs) . "/s</nobr></td>\n";
            $s .= "<td class=rowfollow align=center width=1%><nobr>" . mksize($e['downloaded']) . "</nobr></td>\n";

            if ($e['seeder'] == "no") {
                $s .= "<td class=rowfollow align=center width=1%><nobr>" . mksize(($e['downloaded'] - $e['downloadoffset']) / $secs) . "/s</nobr></td>\n";
            } else {
                $s .= "<td class=rowfollow align=center width=1%><nobr>" . mksize(($e['downloaded'] - $e['downloadoffset']) / max(1, $e['finishedat'] - $e['st'])) . "/s</nobr></td>\n";
            }
            if ($e['downloaded']) {
                $ratio = floor(($e['uploaded'] / $e['downloaded']) * 1000) / 1000;
                $s .= "<td class=rowfollow align=\"center\" width=1%><font color=" . get_ratio_color($ratio) . "><nobr>" . number_format($ratio, 3) . "</nobr></font></td>\n";
            } elseif ($e['uploaded']) {
                $s .= "<td class=rowfollow align=center width=1%>" . $lang['text_inf'] . "</td>\n";
            } else {
                $s .= "<td class=rowfollow align=center width=1%>---</td>\n";
            }
            $s .= "<td class=rowfollow align=center width=1%><nobr>" . sprintf("%.2f%%", 100 * (1 - ($e['to_go'] / $torrent['size']))) . "</nobr></td>\n";
            $s .= "<td class=rowfollow align=center width=1%><nobr>" . mkprettytime($now - $e['st']) . "</nobr></td>\n";
            $s .= "<td class=rowfollow align=center width=1%><nobr>" . mkprettytime($now - $e['la']) . "</nobr></td>\n";
            $s .= "<td class=rowfollow align=center width=1%><nobr>" . htmlspecialchars(get_agent($e['peer_id'], $e['agent'])) . "</nobr></td>\n";
            $s .= "</tr>\n";
        }
        $s .= "</table>\n";
        return $s;
    }

    /**
     * Port of the legacy get_location_column() helper from public/viewpeerlist.php.
     */
    private function getLocationColumn(array $e, bool $isStrongPrivacy, bool $canView): array
    {
        $langFunctions = $GLOBALS['lang_functions'] ?? get_legacy_lang_file('functions');
        $lang = get_legacy_lang_file('viewpeerlist');
        $seedBoxRep = new SeedBoxRepository();
        $address = $ips = [];
        $isSeedBox = false;
        if (get_setting('tweak.enablelocation') == 'yes') {
            if (!empty($e['ipv4'])) {
                list($locPub, $locMod) = get_ip_location($e['ipv4']);
                $seedBoxIcon = $seedBoxRep->renderIcon($e['ipv4'], $e['userid']);
                $address[] = $locPub . $seedBoxIcon;
                $ips[] = $e['ipv4'];
            }
            if (!empty($e['ipv6'])) {
                list($locPub, $locMod) = get_ip_location($e['ipv6']);
                $seedBoxIcon = $seedBoxRep->renderIcon($e['ipv6'], $e['userid']);
                $address[] = $locPub . $seedBoxIcon;
                $ips[] = $e['ipv6'];
            }
            if ($canView) {
                $title = sprintf('%s%s%s', $langFunctions['text_user_ip'], ':&nbsp;', implode(', ', $ips));
            } else {
                $title = '';
            }
            $addressStr = implode('<br/>', $address);
            $location = '<div style="margin-right: 6px" title="' . $title . '">' . $addressStr . '</div>';
        } else {
            if (!empty($e['ipv4'])) {
                $seedBoxIcon = $seedBoxRep->renderIcon($e['ipv4'], $e['userid']);
                $ips[] = $e['ipv4'] . $seedBoxIcon;
            }
            if (!empty($e['ipv6'])) {
                $seedBoxIcon = $seedBoxRep->renderIcon($e['ipv6'], $e['userid']);
                $ips[] = $e['ipv6'] . $seedBoxIcon;
            }
            $location = '<div style="margin-right: 6px">' . implode('<br/>', $ips) . '</div>';
        }

        if ($isStrongPrivacy) {
            $result = '<div><i>' . $lang['text_anonymous'] . '</i></div>';
            if ($canView) {
                $result = $location . $result;
            }
        } else {
            $result = $location;
        }
        if (isset($seedBoxIcon) && !empty($seedBoxIcon)) {
            $isSeedBox = true;
        }
        return [
            "td" => "<td class=rowfollow align=left width=1%><div style='display: flex;white-space: nowrap;align-items: center'>" . $result . "</div></td>",
            "is_seed_box" => $isSeedBox,
        ];
    }

    /**
     * Port of the legacy get_username_seed_box_icon() helper from public/viewpeerlist.php.
     */
    private function getUsernameSeedBoxIcon(array $e): string
    {
        $seedBoxRep = new SeedBoxRepository();
        foreach (array_filter([$e['ipv4'] ?? '', $e['ipv6'] ?? '']) as $ip) {
            $icon = $seedBoxRep->renderIcon($ip, $e['userid']);
            if (!empty($icon)) {
                return $icon;
            }
        }
        return '';
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $request->validate([
            'torrent_id' => 'required',
        ]);

        $response = [
            'seeder_list' => [],
            'leecher_list' => [],
//            'card_titles' => Peer::$cardTitles,
//            'page_title' => nexus_trans('peer.index.page_title'),
        ];
        $result = $this->repository->listPeers($request->torrent_id);
        if ($result['seeder_list']->isNotEmpty()) {
            $response['seeder_list'] = PeerResource::collection($result['seeder_list']);
        }
        if ($result['leecher_list']->isNotEmpty()) {
            $response['leecher_list'] = PeerResource::collection($result['leecher_list']);
        }

        return $this->success($response);

    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
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
}
