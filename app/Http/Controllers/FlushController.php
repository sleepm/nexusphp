<?php

namespace App\Http\Controllers;

use App\Models\Peer;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FlushController extends Controller
{
    /**
     * Ghost-peer cleanup. Mirrors legacy public/takeflush.php so the user
     * details "flush ghost torrents" link keeps working under the Laravel
     * router: deletes peers of the given user whose last_action predates the
     * deadtime (anninterthree * 1.3), then reports how many were cleaned.
     *
     * GET /takeflush.php?id= — only the user themselves or a moderator may
     * flush that user's peers.
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
        $lang = get_legacy_lang_file('takeflush');

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $id = $request->query('id');
        if (! is_numeric($id) || $id < 1 || floor((float) $id) != (float) $id) {
            abort(400, 'Invalid ID');
        }
        $id = (int) $id;

        if (get_user_class() < User::CLASS_MODERATOR && (int) $curUser['id'] != $id) {
            return $this->messagePage(
                $lang['std_failed'] ?? 'Failed',
                $lang['std_cannot_flush_others'] ?? 'You can only clean your own ghost torrents'
            );
        }

        $deadtime = deadtime();
        $lastAction = date('Y-m-d H:i:s', $deadtime);
        $effected = Peer::query()
            ->where('userid', $id)
            ->where('last_action', '<', $lastAction)
            ->delete();

        return $this->messagePage(
            $lang['std_success'] ?? 'Success',
            $effected . ' ' . ($lang['std_ghost_torrents_cleaned'] ?? 'ghost torrents were sucessfully cleaned.')
        );
    }

    /**
     * Full-page message (mirrors the legacy stdmsg()/stderr() box) rendered
     * through Blade instead of stderr()/stdhead()/stdfoot().
     */
    private function messagePage(string $heading, string $message)
    {
        return view('error.notification', [
            'pageTitle' => $heading,
            'heading' => htmlspecialchars($heading),
            'message' => htmlspecialchars($message),
        ]);
    }
}
