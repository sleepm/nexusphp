<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Peer;
use App\Models\Setting;
use App\Models\Snatch;
use App\Models\Torrent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Reseed request. Mirrors legacy public/takereseed.php so the "ask for
 * reseed" link on the torrent details page keeps working under the Laravel
 * router: refuses when the torrent still has seeders or a reseed was already
 * requested in the last 15 minutes, otherwise PMs every user who completed
 * the torrent (localized) and stamps the torrent's last_reseed.
 */
class ReseedController extends Controller
{
    /**
     * GET /takereseed.php?reseedid=N — requires the askreseed permission.
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
        $lang = get_legacy_lang_file('takereseed');

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        // Mirrors legacy loggedinorreturn() + user_can('askreseed', true).
        if (! user_can('askreseed', false, $currentUser->id)) {
            $langFunctions = $GLOBALS['lang_functions'];
            return $this->messagePage(
                $langFunctions['std_sorry'] ?? 'Sorry',
                $langFunctions['std_permission_denied'] ?? 'Permission denied.'
            );
        }

        $reseedid = (int) $request->query('reseedid', 0);
        if ($reseedid <= 0) {
            return $this->messagePage($lang['std_error'], 'Invalid torrent ID.');
        }

        $torrent = Torrent::query()->find($reseedid, ['id', 'seeders', 'last_reseed']);
        if (! $torrent) {
            return $this->messagePage($lang['std_error'], 'The torrent does not exist.');
        }

        $seederCount = Peer::query()->where('torrent', $reseedid)->count();
        if ($seederCount > 0) {
            return $this->messagePage($lang['std_error'], $lang['std_torrent_not_dead']);
        }
        if ($torrent->last_reseed && strtotime((string) $torrent->last_reseed) > (time() - 900)) {
            return $this->messagePage($lang['std_error'], $lang['std_reseed_sent_recently']);
        }

        $snatchers = Snatch::query()
            ->select('snatched.userid', 'snatched.torrentid', 'torrents.name as torrent_name')
            ->join('users', 'snatched.userid', '=', 'users.id')
            ->join('torrents', 'snatched.torrentid', '=', 'torrents.id')
            ->where('snatched.finished', Snatch::FINISHED_YES)
            ->where('snatched.torrentid', $reseedid)
            ->get();

        foreach ($snatchers as $snatcher) {
            $locale = get_user_locale((int) $snatcher->userid);
            $subject = nexus_trans('torrent.msg_reseed_request', [], $locale);
            $msg = nexus_trans('torrent.msg_reseed_user', [], $locale)
                . $curUser['username']
                . nexus_trans('torrent.msg_ask_reseed', [], $locale)
                . '[url=' . get_protocol_prefix() . $GLOBALS['BASEURL'] . '/details.php?id=' . $reseedid . ']'
                . $snatcher->torrent_name
                . '[/url]'
                . nexus_trans('torrent.msg_thank_you', [], $locale);

            Message::add([
                'sender' => 0,
                'receiver' => (int) $snatcher->userid,
                'subject' => $subject,
                'msg' => $msg,
                'added' => now(),
            ]);
        }

        Torrent::query()->where('id', $reseedid)->update([
            'last_reseed' => now(),
            'seeders' => $seederCount,
        ]);

        return $this->messagePage($lang['head_reseed_request'], $lang['std_it_worked'], $lang['head_reseed_request']);
    }

    /**
     * Full-page message (mirrors the legacy stdmsg()/stderr() box) rendered
     * through Blade instead of stderr()/stdhead()/stdfoot().
     */
    private function messagePage(string $heading, string $message, string $pageTitle = 'Error')
    {
        return view('error.notification', [
            'pageTitle' => htmlspecialchars($pageTitle),
            'heading' => htmlspecialchars($heading),
            'message' => htmlspecialchars($message),
        ]);
    }
}
