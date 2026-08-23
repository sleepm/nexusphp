<?php

namespace App\Http\Controllers;

use App\Models\ForumMod;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StaffController extends Controller
{
    private const CACHE_KEY = 'staff_page';
    private const CACHE_TTL = 900;

    /**
     * Staff listing page. Mirrors legacy public/staff.php: renders firstline
     * support, critics, forum moderators, general staff and VIP sections with
     * online/offline indicators, country flags and PM links.
     *
     * GET /staff.php requires staffmem permission.
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
        if (! user_can('staffmem', false, $currentUser->id)) {
            abort(403, 'Access denied.');
        }

        $lang = get_legacy_lang_file('staff');
        $GLOBALS['lang_staff'] = $lang;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();
        $GLOBALS['CURUSER'] = $curUser;

        $content = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () use ($lang, $curUser) {
            return $this->capture(function () use ($lang, $curUser) {
                $this->renderStaffPage($lang, $curUser);
            });
        });

        return view('staff', compact('content') + [
            'pageTitle' => $lang['head_staff'],
        ]);
    }

    private function renderStaffPage(array $lang, array $curUser): void
    {
        $secs = self::CACHE_TTL;
        $dt = time() - $secs;
        $onlineImg = '<img class="button_online" src="pic/trans.gif" alt="online" title="' . $lang['title_online'] . '" />';
        $offlineImg = '<img class="button_offline" src="pic/trans.gif" alt="offline" title="' . $lang['title_offline'] . '" />';
        $sendPmImg = '<img class="button_pm" src="pic/trans.gif" alt="pm" />';

        begin_main_frame();

        // -- Firstline Support --
        $this->renderSupportSection($lang, $dt, $onlineImg, $offlineImg, $sendPmImg);

        // -- Movie Critics --
        $this->renderCriticsSection($lang, $dt, $onlineImg, $offlineImg, $sendPmImg);

        // -- Forum Moderators --
        $this->renderForumModsSection($lang, $dt, $onlineImg, $offlineImg, $sendPmImg);

        // -- General Staff --
        $this->renderGeneralStaffSection($lang, $dt, $onlineImg, $offlineImg, $sendPmImg);

        // -- VIP --
        $this->renderVipSection($lang, $dt, $onlineImg, $offlineImg, $sendPmImg);

        end_main_frame();
    }

    private function isOnline(?string $lastAccess, int $dt): bool
    {
        if (! $lastAccess) {
            return false;
        }

        return strtotime($lastAccess) > $dt;
    }

    private function renderSupportSection(array $lang, int $dt, string $onlineImg, string $offlineImg, string $sendPmImg): void
    {
        $users = User::query()
            ->where('support', 'yes')
            ->where('status', 'confirmed')
            ->orderBy('username')
            ->get(['id', 'username', 'country', 'last_access', 'supportlang', 'supportfor', 'enabled', 'donor', 'donoruntil', 'leechwarn', 'warned', 'class']);

        $ppl = '';
        foreach ($users as $user) {
            $countryImg = $this->countryFlag($user->country);
            $online = $this->isOnline($user->last_access, $dt) ? $onlineImg : $offlineImg;
            $ppl .= '<tr><td class=embedded>' . get_username($user->id) . '</td>'
                . '<td class=embedded>' . $countryImg . '</td>'
                . '<td class=embedded>' . $online . '</td>'
                . '<td class=embedded><a href=sendmessage.php?receiver=' . $user->id . ' title="' . $lang['title_send_pm'] . '">' . $sendPmImg . '</a></td>'
                . '<td class=embedded>' . htmlspecialchars($user->supportlang) . '</td>'
                . '<td class=embedded>' . htmlspecialchars($user->supportfor) . '</td></tr>' . "\n";
        }

        begin_frame($lang['text_firstline_support'] . '<font class=small> - [<a class=altlink href=contactstaff.php><b>' . $lang['text_apply_for_it'] . '</b></a>]</font>');
        echo $lang['text_firstline_support_note'] . '<br /><br />';
        echo '<table width=100% cellspacing=0 align=center>'
            . '<tr><td class=embedded><b>' . $lang['text_username'] . '</b></td>'
            . '<td class=embedded align=center><b>' . $lang['text_country'] . '</b></td>'
            . '<td class=embedded align=center><b>' . $lang['text_online_or_offline'] . '</b></td>'
            . '<td class=embedded align=center><b>' . $lang['text_contact'] . '</b></td>'
            . '<td class=embedded align=center><b>' . $lang['text_language'] . '</b></td>'
            . '<td class=embedded><b>' . $lang['text_support_for'] . '</b></td></tr>'
            . '<tr><td class=embedded colspan=6><hr color="#4040c0"></td></tr>'
            . $ppl . '</table>';
        end_frame();
    }

    private function renderCriticsSection(array $lang, int $dt, string $onlineImg, string $offlineImg, string $sendPmImg): void
    {
        $users = User::query()
            ->where('picker', 'yes')
            ->where('status', 'confirmed')
            ->orderBy('username')
            ->get(['id', 'username', 'country', 'last_access', 'pickfor', 'enabled', 'donor', 'donoruntil', 'leechwarn', 'warned', 'class']);

        $ppl = '';
        foreach ($users as $user) {
            $countryImg = $this->countryFlag($user->country);
            $online = $this->isOnline($user->last_access, $dt) ? $onlineImg : $offlineImg;
            $ppl .= '<tr height=15><td class=embedded>' . get_username($user->id) . '</td>'
                . '<td class=embedded>' . $countryImg . '</td>'
                . '<td class=embedded>' . $online . '</td>'
                . '<td class=embedded><a href=sendmessage.php?receiver=' . $user->id . ' title="' . $lang['title_send_pm'] . '">' . $sendPmImg . '</a></td>'
                . '<td class=embedded>' . htmlspecialchars($user->pickfor) . '</td></tr>' . "\n";
        }

        begin_frame($lang['text_movie_critics'] . '<font class=small> - [<a class=altlink href=contactstaff.php><b>' . $lang['text_apply_for_it'] . '</b></a>]</font>');
        echo $lang['text_movie_critics_note'] . '<br /><br />';
        echo '<table width=100% cellspacing=0 align=center>'
            . '<tr><td class=embedded><b>' . $lang['text_username'] . '</b></td>'
            . '<td class=embedded align=center><b>' . $lang['text_country'] . '</b></td>'
            . '<td class=embedded align=center><b>' . $lang['text_online_or_offline'] . '</b></td>'
            . '<td class=embedded align=center><b>' . $lang['text_contact'] . '</b></td>'
            . '<td class=embedded><b>' . $lang['text_responsible_for'] . '</b></td></tr>'
            . '<tr><td class=embedded colspan=5><hr color="#4040c0"></td></tr>'
            . $ppl . '</table>';
        end_frame();
    }

    private function renderForumModsSection(array $lang, int $dt, string $onlineImg, string $offlineImg, string $sendPmImg): void
    {
        $modUserIds = ForumMod::query()->select('userid')->distinct()->pluck('userid');
        $users = User::query()
            ->whereIn('id', $modUserIds)
            ->get(['id', 'username', 'country', 'last_access', 'enabled', 'donor', 'donoruntil', 'leechwarn', 'warned', 'class'])
            ->keyBy('id');

        $ppl = '';
        foreach ($modUserIds as $uid) {
            $user = $users->get($uid);
            if (! $user) {
                continue;
            }
            $countryImg = $this->countryFlag($user->country);
            $online = $this->isOnline($user->last_access, $dt) ? $onlineImg : $offlineImg;

            $forums = DB::table('forums')
                ->join('forummods', 'forums.id', '=', 'forummods.forumid')
                ->where('forummods.userid', $uid)
                ->pluck('forums.name', 'forums.id');

            $forumLinks = [];
            foreach ($forums as $fid => $fname) {
                $forumLinks[] = '<a href=forums.php?action=viewforum&forumid=' . $fid . '>' . htmlspecialchars($fname) . '</a>';
            }
            $forumStr = implode(', ', $forumLinks);

            $ppl .= '<tr height=15><td class=embedded>' . get_username($uid) . '</td>'
                . '<td class=embedded>' . $countryImg . '</td>'
                . '<td class=embedded>' . $online . '</td>'
                . '<td class=embedded><a href=sendmessage.php?receiver=' . $uid . ' title="' . $lang['title_send_pm'] . '">' . $sendPmImg . '</a></td>'
                . '<td class=embedded>' . $forumStr . '</td></tr>' . "\n";
        }

        begin_frame($lang['text_forum_moderators'] . '<font class=small> - [<a class=altlink href=contactstaff.php><b>' . $lang['text_apply_for_it'] . '</b></a>]</font>');
        echo $lang['text_forum_moderators_note'] . '<br /><br />';
        echo '<table width=100% cellspacing=0 align=center>'
            . '<tr><td class=embedded><b>' . $lang['text_username'] . '</b></td>'
            . '<td class=embedded align=center><b>' . $lang['text_country'] . '</b></td>'
            . '<td class=embedded align=center><b>' . $lang['text_online_or_offline'] . '</b></td>'
            . '<td class=embedded align=center><b>' . $lang['text_contact'] . '</b></td>'
            . '<td class=embedded><b>' . $lang['text_forums'] . '</b></td></tr>'
            . '<tr><td class=embedded colspan=5><hr color="#4040c0"></td></tr>'
            . $ppl . '</table>';
        end_frame();
    }

    private function renderGeneralStaffSection(array $lang, int $dt, string $onlineImg, string $offlineImg, string $sendPmImg): void
    {
        $staff = User::query()
            ->where('class', '>', User::CLASS_VIP)
            ->where('status', 'confirmed')
            ->orderBy('class', 'desc')
            ->orderBy('username')
            ->get(['id', 'username', 'country', 'last_access', 'stafffor', 'class', 'enabled', 'donor', 'donoruntil', 'leechwarn', 'warned']);

        begin_frame($lang['text_general_staff'] . '<font class=small> - [<a class=altlink href=contactstaff.php><b>' . $lang['text_apply_for_it'] . '</b></a>]</font>');
        echo $lang['text_general_staff_note'] . '<br /><br />';
        echo '<table width=100% cellspacing=0 align=center>';

        $ppl = '';
        $currClass = '';
        foreach ($staff as $user) {
            if ($currClass != $user->class) {
                $currClass = $user->class;
                if ($ppl !== '') {
                    $ppl .= '<tr height=15><td class=embedded colspan=5 align=right>&nbsp;</td></tr>';
                }
                $ppl .= '<tr height=15><td class=embedded colspan=5 align=right>' . get_user_class_name($user->class, false, true, true) . '</td></tr>';
                $ppl .= '<tr>'
                    . '<td class=embedded><b>' . $lang['text_username'] . '</b></td>'
                    . '<td class=embedded align=center><b>' . $lang['text_country'] . '</b></td>'
                    . '<td class=embedded align=center><b>' . $lang['text_online_or_offline'] . '</b></td>'
                    . '<td class=embedded align=center><b>' . $lang['text_contact'] . '</b></td>'
                    . '<td class=embedded><b>' . $lang['text_duties'] . '</b></td></tr>';
                $ppl .= '<tr height=15><td class=embedded colspan=5><hr color="#4040c0"></td></tr>';
            }
            $countryImg = $this->countryFlag($user->country);
            $online = $this->isOnline($user->last_access, $dt) ? $onlineImg : $offlineImg;
            $ppl .= '<tr><td class=embedded>' . get_username($user->id) . '</td>'
                . '<td class=embedded>' . $countryImg . '</td>'
                . '<td class=embedded>' . $online . '</td>'
                . '<td class=embedded><a href=sendmessage.php?receiver=' . $user->id . ' title="' . $lang['title_send_pm'] . '">' . $sendPmImg . '</a></td>'
                . '<td class=embedded>' . htmlspecialchars($user->stafffor) . '</td></tr>' . "\n";
        }

        echo $ppl . '</table>';
        end_frame();
    }

    private function renderVipSection(array $lang, int $dt, string $onlineImg, string $offlineImg, string $sendPmImg): void
    {
        $vips = User::query()
            ->where('class', User::CLASS_VIP)
            ->where('status', 'confirmed')
            ->orderBy('username')
            ->get(['id', 'username', 'country', 'last_access', 'stafffor', 'enabled', 'donor', 'donoruntil', 'leechwarn', 'warned', 'class']);

        $ppl = '';
        foreach ($vips as $user) {
            $countryImg = $this->countryFlag($user->country);
            $online = $this->isOnline($user->last_access, $dt) ? $onlineImg : $offlineImg;
            $ppl .= '<tr><td class=embedded>' . get_username($user->id) . '</td>'
                . '<td class=embedded>' . $countryImg . '</td>'
                . '<td class=embedded>' . $online . '</td>'
                . '<td class=embedded><a href=sendmessage.php?receiver=' . $user->id . ' title="' . $lang['title_send_pm'] . '">' . $sendPmImg . '</a></td>'
                . '<td class=embedded>' . htmlspecialchars($user->stafffor) . '</td></tr>' . "\n";
        }

        $siteName = Setting::getSiteName();
        begin_frame($lang['text_vip']);
        echo sprintf($lang['text_vip_note'], $siteName) . '<br /><br />';
        echo '<table width=100% cellspacing=0 align=center>'
            . '<tr><td class=embedded><b>' . $lang['text_username'] . '</b></td>'
            . '<td class=embedded><b>' . $lang['text_country'] . '</b></td>'
            . '<td class=embedded><b>' . $lang['text_online_or_offline'] . '</b></td>'
            . '<td class=embedded><b>' . $lang['text_contact'] . '</b></td>'
            . '<td class=embedded><b>' . $lang['text_reason'] . '</b></td></tr>'
            . '<tr><td class=embedded colspan=5><hr color="#4040c0"></td></tr>'
            . $ppl . '</table>';
        end_frame();
    }

    private function countryFlag($countryId): string
    {
        $row = get_country_row($countryId);
        if (! $row) {
            return '';
        }

        return '<img width=24 height=15 src="pic/flag/' . htmlspecialchars($row['flagpic']) . '" title="' . htmlspecialchars($row['name']) . '" style="padding-bottom:1px;">';
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();

        return (string) ob_get_clean();
    }
}