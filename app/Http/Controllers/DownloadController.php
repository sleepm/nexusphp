<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Torrent;
use App\Models\User;
use App\Repositories\IpLogRepository;
use App\Repositories\TorrentRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Rhilip\Bencode\TorrentFile;

/**
 * Torrent file download endpoint. Mirrors legacy public/download.php so seed
 * downloads keep working under the Laravel router instead of the procedural
 * script.
 *
 * Three entry points, matching the legacy script:
 *  1. ?downhash=UID.HASH      — anonymous download link (RSS clients). The hash
 *     is a per-user signed JWT resolved against the passkey of user UID.
 *  2. ?passkey=..&id=..       — passkey-gated download (requires the
 *     torrent.download_support_passkey setting).
 *  3. ?id=..                  — the regular logged-in path. Guards parked
 *     accounts, first-time / client / ratio notices and requires a session.
 *
 * Routes 1 & 2 are anonymous, so authentication is handled internally instead
 * of via the auth.nexus middleware.
 */
class DownloadController extends Controller
{
    public function web(Request $request)
    {
        $torrentRep = new TorrentRepository();
        $id = 0;
        /** @var User|null $currentUser */
        $currentUser = null;

        if (!empty($request->input('downhash'))) {
            $params = explode('.', $request->input('downhash'), 2);
            if (empty($params[0]) || empty($params[1])) {
                return response('invalid downhash, format error');
            }
            $user = User::query()->find($params[0]);
            if (!$user) {
                return response('invalid uid');
            }
            if ($user->enabled == User::ENABLED_NO || $user->parked == 'yes') {
                return response('account disabed or parked');
            }
            $user->ip = getip();
            $decrypted = $torrentRep->decryptDownHash($params[1], $user);
            if (empty($decrypted)) {
                do_log("downhash invalid: " . nexus_json_encode($request->all()));
                return response('invalid downhash, decrpyt fail');
            }
            $id = $decrypted[0];
            $currentUser = $user;
        } elseif (get_setting('torrent.download_support_passkey') == 'yes' && !empty($request->input('passkey')) && !empty($request->input('id'))) {
            $user = User::query()->where('passkey', $request->input('passkey'))->first();
            if (!$user) {
                return response('invalid passkey');
            }
            if ($user->enabled == User::ENABLED_NO || $user->parked == 'yes') {
                return response('account disabed or parked');
            }
            $user->ip = getip();
            $id = (int) $request->input('id');
            $currentUser = $user;
        } else {
            $id = (int) $request->input('id');
            if (!$id) {
                abort(404);
            }
            $currentUser = Auth::guard('nexus')->user();
            if (!$currentUser) {
                return redirect()->guest('/login.php');
            }
            if ($currentUser->parked == 'yes') {
                abort(403);
            }
            $letdown = (int) $request->input('letdown', 0);
            if (!$letdown && $currentUser->showdlnotice == 1) {
                return redirect("/downloadnotice.php?torrentid=" . $id . "&type=firsttime");
            } elseif (!$letdown && $currentUser->showclienterror == 'yes') {
                return redirect("/downloadnotice.php?torrentid=" . $id . "&type=client");
            } elseif (!$letdown && $currentUser->leechwarn == 'yes') {
                return redirect("/downloadnotice.php?torrentid=" . $id . "&type=ratio");
            }
        }

        if (!$id || !$currentUser) {
            abort(404);
        }

        $curUser = $this->buildCurUserArray($currentUser);
        // globals the shared legacy helpers expect (mirrors public/download.php bootstrap)
        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['SITENAME'] = Setting::getSiteName();
        $GLOBALS['specialcatmode'] = (int) get_setting('main.specialcat', 0);

        // user may download the torrent from RSS, so log ip changes + refresh last_access
        IpLogRepository::saveToCache($curUser['id']);
        User::query()->where('id', $curUser['id'])->update([
            'last_access' => date('Y-m-d H:i:s'),
            'ip' => $curUser['ip'],
        ]);

        if ($curUser['downloadpos'] == 'no') {
            abort(403);
        }

        $row = Torrent::query()
            ->select(['name', 'filename', 'save_as', 'size', 'owner', 'banned', 'approval_status', 'price', 'added'])
            ->with('basic_category')
            ->find($id);
        if (!$row) {
            do_log("[TORRENT_NOT_EXISTS_IN_DATABASE] $id");
            abort(404);
        }
        $rowArr = $row->toArray();
        $rowArr['search_box_id'] = $row->basic_category->mode ?? 0;

        $torrentDir = get_setting('main.torrent_dir');
        $fn = getFullDirectory("$torrentDir/$id.torrent");
        if (!is_file($fn)) {
            do_log("[TORRENT_NOT_EXISTS_IN_PATH] $fn", 'error');
            abort(404);
        }
        if (!is_readable($fn)) {
            do_log("[TORRENT_NOT_READABLE] $fn", 'error');
            abort(404);
        }
        if (filesize($fn) == 0) {
            do_log("[TORRENT_NOT_VALID_SIZE_ZERO] $fn", 'error');
            abort(404);
        }

        $approvalNotAllowed = $rowArr['approval_status'] != Torrent::APPROVAL_STATUS_ALLOW && get_setting('torrent.approval_status_none_visible') == 'no';
        $allowOwnerDownload = $rowArr['owner'] == $curUser['id'];
        $canSeedBanned = user_can('seebanned');
        $canAccessTorrent = can_access_torrent($rowArr, $curUser['id']);
        if ((($rowArr['banned'] == 'yes' || ($approvalNotAllowed && !$allowOwnerDownload)) && !$canSeedBanned) || !$canAccessTorrent) {
            do_log("[DENY_DOWNLOAD], user: {$curUser['id']}, approvalNotAllowed: $approvalNotAllowed, allowOwnerDownload: $allowOwnerDownload, canSeedBanned: $canSeedBanned, canAccessTorrent: $canAccessTorrent", 'error');
            abort(403);
        }

        Torrent::query()->where('id', $id)->increment('hits');

        if (strlen($curUser['passkey']) != 32) {
            $curUser['passkey'] = md5($curUser['username'] . date("Y-m-d H:i:s") . $curUser['passhash']);
            $GLOBALS['CURUSER']['passkey'] = $curUser['passkey'];
            User::query()->where('id', $curUser['id'])->update(['passkey' => $curUser['passkey']]);
        }
        $dict = TorrentFile::load($fn);
        $dict->cleanRootFields();
        $dict->setAnnounce(get_tracker_schema_and_host($curUser['tracker_url_id'] ?? 0, true) . "?passkey=" . $curUser['passkey']);
        $dict->setComment(getSchemeAndHttpHost(true) . "/details.php?id=" . $id);
        $dict->setCreatedBy($GLOBALS['SITENAME']);
        $dict->setCreationDate(strtotime($rowArr['added']));
        do_log(sprintf("[ANNOUNCE_URL], user: %s, torrent: %s, url: %s", $curUser['id'] ?? '', $id, $dict->getAnnounce()));

        $torrentnameprefix = get_setting('main.torrentnameprefix', '[Nexus]');
        return response($dict->dumpToString())
            ->header('Content-Type', 'application/x-bittorrent')
            ->header('Content-Disposition', make_content_disposition($torrentnameprefix . $rowArr['save_as'] . '.torrent'));
    }

    private function buildCurUserArray(User $user): array
    {
        $curUser = $user->makeVisible(['passkey', 'passhash', 'auth_key'])->toArray();
        // ensure keys the download flow relies on are always present
        $curUser['tracker_url_id'] = $user->tracker_url_id ?? 0;

        return $curUser;
    }
}
