<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Torrent;
use App\Models\User;
use App\Repositories\TorrentRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Nexus\PTGen\PTGen;

class RetriverController extends Controller
{
    /**
     * Refresh external info (IMDb / PT-Gen) for a torrent. Mirrors legacy
     * public/retriver.php: requires the updateextinfo permission, validates
     * id/type/siteid, and triggers a background-ish info backfill before
     * redirecting back to the torrent details page.
     *
     * siteid: 1 => IMDb via TorrentRepository::fetchImdb()
     * siteid: imdb|douban|bangumi => PT-Gen refresh
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
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $minClass = (int) get_setting('authority.updateextinfo', User::CLASS_EXTREME_USER);
        if (get_user_class() < $minClass) {
            abort(403, 'Access denied.');
        }

        $id = (int) $request->query('id', 0);
        $type = (int) $request->query('type', 0);
        $siteid = $request->query('siteid', 0);

        if (! $id || ! $type || ! $siteid) {
            abort(400);
        }

        if (! Torrent::query()->whereKey($id)->exists()) {
            abort(400);
        }

        switch ($siteid) {
            case 1:
                (new TorrentRepository())->fetchImdb($id);
                break;
            case PTGen::SITE_IMDB:
            case PTGen::SITE_DOUBAN:
            case PTGen::SITE_BANGUMI:
                $ptGen = new PTGen();
                try {
                    $ptGen->updateTorrentPtGen($id);
                } catch (\Exception $e) {
                    do_log($e->getMessage() . ', trace: ' . $e->getTraceAsString(), 'error');
                }
                break;
            default:
                abort(400);
        }

        return redirect("/details.php?id=$id");
    }
}
