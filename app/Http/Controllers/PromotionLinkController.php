<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PromotionLinkController extends Controller
{
    /**
     * Promotion link page + click tracking. Mirrors legacy public/promotionlink.php
     * so the User CP "promotion link" links keep working under the Laravel router:
     *
     *  - GET /promotionlink.php?key=XXX grants the click bonus to the link owner
     *    (guests are fine) and redirects the visitor to the site home page.
     *  - GET /promotionlink.php?updatekey=1 (or a user without a stored key)
     *    regenerates the random key and redirects back to the promotion page.
     *  - GET /promotionlink.php renders the promotion page with the ready-made
     *    XHTML / HTML / BBCode snippets (and the BBCode userbar when the user
     *    holds the "userbar" permission).
     */
    public function web(Request $request)
    {
        $lang = get_legacy_lang_file('promotionlink');
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();
        $GLOBALS['SLOGAN'] = (string) get_setting('main.SLOGAN', '');

        $baseUrl = Setting::getBaseUrl();
        $prolinkPointBonus = (float) get_setting('bonus.prolinkpoint', 0);
        $prolinkTimeBonus = (int) get_setting('bonus.prolinktime', 0);

        // A visitor clicked a promotion link: reward the owner once per IP/time window.
        $key = trim((string) $request->query('key', ''));
        if ($key !== '') {
            if (! Auth::guard('nexus')->check() && $prolinkPointBonus > 0) {
                $this->registerPromotionClick($key, $prolinkPointBonus, $prolinkTimeBonus);
            }
            return redirect(get_protocol_prefix() . $baseUrl);
        }

        /** @var User|null $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        if (! $currentUser) {
            return redirect(sprintf('%s/login.php?returnto=%s', $request->getSchemeAndHttpHost(), urlencode($request->fullUrl())));
        }
        $curUser = $currentUser->toArray();
        $GLOBALS['CURUSER'] = $curUser;
        // Point the default auth guard at the nexus cookie guard so the legacy
        // get_user_class()/get_user_id()/user_can() helpers resolve this user
        // (the auth.nexus middleware normally does this for routed pages).
        Auth::shouldUse('nexus');

        // Generate a fresh key on demand or for accounts that still lack one.
        if ((string) $request->query('updatekey', '') !== '' || empty($curUser['promotion_link'])) {
            $promotionKey = md5($currentUser->email . date('Y-m-d H:i:s') . $currentUser->passhash);
            DB::table('users')->where('id', $curUser['id'])->update(['promotion_link' => $promotionKey]);
            return redirect(get_protocol_prefix() . $baseUrl . '/promotionlink.php');
        }

        $siteName = Setting::getSiteName();
        $slogan = (string) get_setting('main.SLOGAN', '');

        $content = $this->capture(function () use (
            $lang, $baseUrl, $curUser, $prolinkPointBonus, $prolinkTimeBonus, $siteName, $slogan
        ) {
            $yourLink = get_protocol_prefix() . $baseUrl . '/promotionlink.php?key=' . $curUser['promotion_link'];
            $imgUrl = get_protocol_prefix() . $baseUrl . '/' . get_setting('tweak.prolinkimg', 'pic/prolink.png');

            begin_main_frame();
            begin_frame($lang['text_promotion_link']);

            print '<div><p align="left">' . $lang['text_promotion_link_note_one'] . '</p>'
                . '<p align="left">' . $lang['text_promotion_link_note_two'] . '</p>'
                . '<p align="left">' . $lang['text_you_would_get'] . $prolinkPointBonus . $lang['text_bonus_points']
                . $prolinkTimeBonus . $lang['text_seconds'] . '</p>'
                . '<p align="left"><b>' . $lang['text_your_promotion_link_is'] . '</b><a href="' . $yourLink . '">' . $yourLink . '</a></p>'
                . '<p align="left">' . $lang['text_promotion_link_note_four'] . '</p></div>';

            print '<table border="1" cellspacing="0" cellpadding="10" width="100%">';
            print '<tr>';
            print '<td class="colhead">' . $lang['col_type'] . '</td>';
            print '<td class="colhead">' . $lang['col_code'] . '</td>';
            print '<td class="colhead">' . $lang['col_result'] . ' / ' . $lang['col_note'] . '</td>';
            print '</tr>';

            // XHTML 1.0
            print '<tr><td class="colfollow">' . $lang['row_xhtml'] . '</td><td class="colfollow">'
                . '<textarea cols="50" rows="4">' . htmlspecialchars('<a href="' . $yourLink . '" target="_blank"><img src="' . $imgUrl
                    . '" alt="' . $siteName . '" title="' . $siteName . ' - ' . $slogan . '" /></a>') . '</textarea></td>'
                . '<td class="colfollow" align="left"><div><a href="' . $yourLink . '" target="_blank"><img src="' . $imgUrl
                    . '" alt="' . htmlspecialchars($siteName) . '" title="' . htmlspecialchars($siteName) . ' - ' . htmlspecialchars($slogan) . '" /></a></div>'
                . '<div style="padding-top: 10px">' . $lang['text_xhtml_note'] . '</div></td></tr>';

            // HTML 4.01
            print '<tr><td class="colfollow">' . $lang['row_html'] . '</td><td class="colfollow">'
                . '<textarea cols="50" rows="4">' . htmlspecialchars('<a href="' . $yourLink . '"><img src="' . $imgUrl
                    . '" alt="' . $siteName . '" title="' . $siteName . ' - ' . $slogan . '"></a>') . '</textarea></td>'
                . '<td class="colfollow"><div><a href="' . $yourLink . '" target="_blank"><img src="' . $imgUrl
                    . '" alt="' . htmlspecialchars($siteName) . '" title="' . htmlspecialchars($siteName) . ' - ' . htmlspecialchars($slogan) . '" /></a></div>'
                . '<div style="padding-top: 10px">' . $lang['text_html_note'] . '</div></td></tr>';

            // BBCode
            print '<tr><td class="colfollow">' . $lang['row_bbcode'] . '</td><td class="colfollow">'
                . '<textarea cols="50" rows="4">' . htmlspecialchars('[url=' . $yourLink . '][img]' . $imgUrl . '[/img][/url]') . '</textarea></td>'
                . '<td class="colfollow"><div><a href="' . $yourLink . '"><img src="' . $imgUrl . '" /></a></div>'
                . '<div style="padding-top: 10px">' . $lang['text_bbcode_note'] . '</div></td></tr>';

            // BBCode userbar
            if (user_can('userbar')) {
                $userbarUrl = get_protocol_prefix() . $baseUrl . '/mybar.php?userid=' . $curUser['id'] . '.png';
                print '<tr><td class="colfollow">' . $lang['row_bbcode_userbar'] . '</td><td class="colfollow">'
                    . '<textarea cols="50" rows="4">' . htmlspecialchars('[url=' . $yourLink . '][img]' . $userbarUrl . '[/img][/url]') . '</textarea></td>'
                    . '<td class="colfollow"><div><a href="' . $yourLink . '"><img src="' . $userbarUrl . '" /></a></div>'
                    . '<div style="padding-top: 10px">' . $lang['text_bbcode_userbar_note'] . '</div></td></tr>';
            }

            print '</table>';
            print '</div>';

            end_frame();
            end_main_frame();
        });

        return view('promotionlink', compact('content') + [
            'pageTitle' => $lang['head_promotion_link'],
        ]);
    }

    /**
     * Grant the promotion click bonus, guarding against repeat clicks from the
     * same IP or within the configured time window (mirrors legacy logic).
     */
    private function registerPromotionClick(string $key, float $points, int $timeBonus): void
    {
        $user = DB::table('users')->select('id')->where('promotion_link', $key)->first();
        if (! $user) {
            return;
        }
        $ip = getip();
        $dt = date('Y-m-d H:i:s', TIMENOW - $timeBonus);
        $clicks = DB::table('prolinkclicks')
            ->where('userid', $user->id)
            ->where(function ($query) use ($dt, $ip) {
                $query->where('added', '>', $dt)->orWhere('ip', $ip);
            })
            ->count();
        if ($clicks > 0) {
            return;
        }
        $bonusTweak = (string) get_setting('tweak.bonus', 'enable');
        if (in_array($bonusTweak, ['enable', 'disablesave'], true)) {
            DB::table('users')->where('id', $user->id)->increment('seedbonus', $points);
        }
        DB::table('prolinkclicks')->insert(['userid' => $user->id, 'ip' => $ip, 'added' => now()]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}
