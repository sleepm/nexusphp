<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Torrent;
use App\Models\TorrentState;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FreeleechController extends Controller
{
    /**
     * Site-wide promotion (freeleech) switcher. Mirrors legacy public/freeleech.php
     * so the admin freeleech.php links keep working under the Laravel router:
     * setting a global state flips every torrents_state row and flushes the
     * global promotion cache.
     *
     * GET/POST /freeleech.php?action=setallfree|setall2up|setall2up_free|
     * setallhalf_down|setall2up_half_down|setallnormal applies the promotion;
     * action=main renders the action links.
     */
    public function web(Request $request)
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        if (! $currentUser) {
            abort(401);
        }
        $curUser = $currentUser->toArray();
        if (get_user_class() < User::CLASS_ADMINISTRATOR) {
            abort(403, 'Permission denied.');
        }

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $action = htmlspecialchars(trim((string) ($request->input('action', $request->query('action', 'main')))));

        $actions = [
            'setallfree' => [Torrent::PROMOTION_FREE, 'All torrents have been set free..'],
            'setall2up' => [Torrent::PROMOTION_TWO_TIMES_UP, 'All torrents have been set 2x up..'],
            'setall2up_free' => [Torrent::PROMOTION_FREE_TWO_TIMES_UP, 'All torrents have been set 2x up and free..'],
            'setallhalf_down' => [Torrent::PROMOTION_HALF_DOWN, 'All torrents have been set half down..'],
            'setall2up_half_down' => [Torrent::PROMOTION_HALF_DOWN_TWO_TIMES_UP, 'All torrents have been set 2x up and half down..'],
            'setallnormal' => [Torrent::PROMOTION_NORMAL, 'All torrents have been set normal..'],
        ];

        if (isset($actions[$action])) {
            [$state, $message] = $actions[$action];
            TorrentState::query()->update(['global_sp_state' => $state]);
            TorrentState::flushCache();

            $content = $this->capture(function () use ($message) {
                stderr('Success', $message, false, false, false, false);
            });

            return view('freeleech', compact('content') + [
                'pageTitle' => 'Freeleech',
            ]);
        }

        $content = $this->capture(function () {
            $links = [
                'setallfree' => 'set all torrents free',
                'setall2up' => 'set all torrents 2x up',
                'setall2up_free' => 'set all torrents 2x up and free',
                'setallhalf_down' => 'set all torrents half down',
                'setall2up_half_down' => 'set all torrents 2x up and half down',
                'setallnormal' => 'set all torrents normal',
            ];
            print '<p>Select action:</p>' . "\n";
            foreach ($links as $action => $label) {
                print "Click <a class=altlink href=freeleech.php?action=$action>here</a> to $label..<br />\n";
            }
        });

        return view('freeleech', compact('content') + [
            'pageTitle' => 'Freeleech',
        ]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}
