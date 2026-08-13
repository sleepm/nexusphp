<?php

namespace App\Http\Controllers;

use App\Http\Resources\PeerResource;
use App\Http\Resources\SnatchResource;
use App\Models\Peer;
use App\Models\Snatch;
use App\Models\Torrent;
use App\Repositories\SeedBoxRepository;
use App\Repositories\TorrentRepository;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class SnatchController extends Controller
{
    private $repository;

    public function __construct(TorrentRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Snatch detail page, replaces legacy public/viewsnatches.php (Phase 2 P3).
     */
    public function web(Request $request)
    {
        $currentUser = Auth::user();
        if ($currentUser && $currentUser->parked == 'yes') {
            abort(403, 'Your account is parked.');
        }
        $id = (int) $request->get('id', 0);
        if (!is_valid_id($id)) {
            abort(404, 'Invalid torrent id.');
        }
        $lang = get_legacy_lang_file('viewsnatches');

        $torrent = Torrent::query()->find($id, ['id', 'name']);
        if (!$torrent) {
            abort(404, 'Torrent not found.');
        }

        $count = Snatch::query()
            ->where('torrentid', $id)
            ->where('finished', Snatch::FINISHED_YES)
            ->count();

        $perPage = 25;
        $pageIndex = max(0, (int) $request->get('page', 0));
        $page = $pageIndex + 1;

        $snatches = Snatch::query()
            ->where('torrentid', $id)
            ->where('finished', Snatch::FINISHED_YES)
            ->orderBy('completedat', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $paginator = new LengthAwarePaginator($snatches, $count, $perPage, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        $seedBoxRep = new SeedBoxRepository();
        $showIp = user_can('userprofile');
        $currentUserId = $currentUser->id ?? 0;

        $rows = $snatches->map(function (Snatch $snatch) use ($lang, $seedBoxRep, $showIp, $currentUserId) {
            if ($snatch->downloaded > 0) {
                $ratio = number_format($snatch->uploaded / $snatch->downloaded, 3);
                $ratio = "<font color=" . get_ratio_color($ratio) . ">$ratio</font>";
            } elseif ($snatch->uploaded > 0) {
                $ratio = $lang['text_inf'];
            } else {
                $ratio = "---";
            }
            $uploaded = mksize($snatch->uploaded);
            $downloaded = mksize($snatch->downloaded);
            $seedtime = mkprettytime($snatch->seedtime);
            $leechtime = mkprettytime($snatch->leechtime);
            $uprate = $snatch->seedtime > 0 ? mksize($snatch->uploaded / ($snatch->seedtime + $snatch->leechtime)) : mksize(0);
            $downrate = $snatch->leechtime > 0 ? mksize($snatch->downloaded / $snatch->leechtime) : mksize(0);

            $highlight = $currentUserId == $snatch->userid ? " bgcolor=#00A527" : "";
            $userrow = get_user_row($snatch->userid);
            if ($userrow && $userrow['privacy'] == 'strong') {
                $username = $lang['text_anonymous'];
                if (user_can('viewanonymous') || $snatch->userid == $currentUserId) {
                    $username .= "<br />(" . get_username($snatch->userid) . ")";
                }
            } else {
                $username = get_username($snatch->userid);
            }
            $reportImage = "<img class=\"f_report\" src=\"pic/trans.gif\" alt=\"Report\" title=\"" . $lang['title_report'] . "\" />";
            $canReport = (!$userrow || $userrow['privacy'] != 'strong') || user_can('viewanonymous');

            return [
                'highlight' => $highlight,
                'username' => $username,
                'ip' => $showIp ? "<span class='nowrap'>" . $snatch->ip . $seedBoxRep->renderIcon($snatch->ip, $snatch->userid) . "</span>" : '',
                'show_ip' => $showIp,
                'uploaded' => $uploaded . "@" . $uprate . $lang['text_per_second'] . "<br />" . $downloaded . "@" . $downrate . $lang['text_per_second'],
                'ratio' => $ratio,
                'seedtime' => $seedtime,
                'leechtime' => $leechtime,
                'completedat' => gettime($snatch->completedat, true, false),
                'last_action' => gettime($snatch->last_action, true, false),
                'userid' => $snatch->userid,
                'can_report' => $canReport,
                'report_image' => $reportImage,
            ];
        });

        return view('viewsnatches', [
            'request' => $request,
            'lang' => $lang,
            'pageTitle' => $lang['head_snatch_detail'],
            'torrentId' => $id,
            'torrentName' => $torrent->name,
            'rows' => $rows,
            'paginator' => $paginator,
            'hasRows' => $count > 0,
            'showIp' => $showIp,
        ]);
    }

    /**
     * @param Request $request
     * @return array
     */
    public function index(Request $request)
    {
        $request->validate([
            'torrent_id' => 'required',
        ]);
        $snatches = $this->repository->listSnatches($request->torrent_id);
        $resource = SnatchResource::collection($snatches);
//        $resource->additional([
//            'card_titles' => Snatch::$cardTitles,
//            'page_title' => nexus_trans('snatch.index.page_title'),
//        ]);

        return $this->success($resource);
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
