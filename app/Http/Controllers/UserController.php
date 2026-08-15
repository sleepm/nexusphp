<?php

namespace App\Http\Controllers;

use App\Http\Resources\ExamResource;
use App\Http\Resources\InviteResource;
use App\Http\Resources\TorrentResource;
use App\Http\Resources\UserResource;
use App\Models\BonusLogs;
use App\Models\Claim;
use App\Models\Comment;
use App\Models\HitAndRun;
use App\Models\Peer;
use App\Models\Post;
use App\Models\Setting;
use App\Models\Snatch;
use App\Models\User;
use App\Models\UserMeta;
use App\Models\UserModifyLog;
use App\Repositories\ClaimRepository;
use App\Repositories\ExamRepository;
use App\Repositories\HitAndRunRepository;
use App\Repositories\SeedBoxRepository;
use App\Repositories\TorrentRepository;
use App\Repositories\UserRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use League\OAuth2\Server\Grant\AuthCodeGrant;

class UserController extends Controller
{
    private $repository;

    public function __construct(UserRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * User details page. Mirrors legacy public/userdetails.php so the page can
     * be served by the Laravel router instead of the procedural script.
     */
    public function web(Request $request)
    {
        /** @var User $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        if (!$currentUser) {
            abort(401);
        }
        if ($currentUser->parked == 'yes') {
            abort(403, 'Your account is parked.');
        }
        $curUser = $currentUser->toArray();

        $lang = get_legacy_lang_file('userdetails');
        $langFunctions = get_legacy_lang_file('functions');

        // globals the shared legacy helpers expect (mirrors public/userdetails.php bootstrap)
        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_userdetails'] = $lang;
        $GLOBALS['lang_functions'] = $langFunctions;
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();
        $GLOBALS['where_tweak'] = get_setting('tweak.where', 'no');
        $GLOBALS['enablelocation_tweak'] = get_setting('tweak.enablelocation', 'no');
        $GLOBALS['viewinvite_class'] = (int) get_setting('authority.viewinvite', 0);

        // legacy user-class constants are defined in include/core.php (not loaded
        // in the Laravel bootstrap); userdetails.php compares against UC_VIP/UC_STAFFLEADER
        foreach ([
            'UC_PEASANT' => 0, 'UC_USER' => 1, 'UC_POWER_USER' => 2, 'UC_ELITE_USER' => 3,
            'UC_CRAZY_USER' => 4, 'UC_INSANE_USER' => 5, 'UC_VETERAN_USER' => 6,
            'UC_EXTREME_USER' => 7, 'UC_ULTIMATE_USER' => 8, 'UC_NEXUS_MASTER' => 9,
            'UC_VIP' => 10, 'UC_RETIREE' => 11, 'UC_UPLOADER' => 12, 'UC_MODERATOR' => 13,
            'UC_ADMINISTRATOR' => 14, 'UC_SYSOP' => 15, 'UC_STAFFLEADER' => 16,
        ] as $constant => $value) {
            defined($constant) || define($constant, $value);
        }

        $id = (int) $request->input('id');
        if (!is_valid_id($id)) {
            abort(404, $lang['std_no_such_user']);
        }

        /** @var User $user */
        $user = User::query()->with('valid_medals')->find($id);
        if (!$user) {
            abort(404, $lang['std_no_such_user']);
        }
        if ($user->status == 'pending') {
            abort(403, $lang['std_user_not_confirmed']);
        }

        $userRep = new UserRepository();

        $added = $user->added ? $user->added->format('Y-m-d H:i:s') : null;
        if (in_array($added, ['0000-00-00 00:00:00', null], true)) {
            $joindate = $lang['text_not_available'];
        } else {
            $weeks = abs(number_format($user->added->diffInWeeks(), 1)) . nexus_trans('nexus.time_units.week');
            $joindate = $added . ' (' . gettime($added, true, false, true) . ', ' . $weeks . ')';
        }
        $lastseen = $user->last_access ? $user->last_access->format('Y-m-d H:i:s') : null;
        if (in_array($lastseen, ['0000-00-00 00:00:00', null], true)) {
            $lastseen = $lang['text_not_available'];
        } else {
            $lastseen .= ' (' . gettime($lastseen, true, false, true) . ')';
        }
        $torrentcomments = Comment::query()->where('user', $user->id)->count();
        $forumposts = Post::query()->where('userid', $user->id)->count();

        $arr = get_country_row($user->country);
        $country = $arr ? '<img src="pic/flag/' . $arr['flagpic'] . '" alt="' . $arr['name'] . '" style=\'margin-left: 8pt\' />' : '';

        $arr = (array) get_downloadspeed_row($user->download);
        $name = $arr['name'] ?? '';
        $download = '<img class="speed_down" src="pic/trans.gif" alt="Downstream Rate" title="' . $lang['title_download'] . $name . '" /> ' . $name;

        $arr = (array) get_uploadspeed_row($user->upload);
        $name = $arr['name'] ?? '';
        $upload = '<img class="speed_up" src="pic/trans.gif" alt="Upstream Rate" title="' . $lang['title_upload'] . $name . '" /> ' . $name;

        $arr = get_isp_row($user->isp);
        $name = $arr['name'] ?? '';
        $isp = $name;

        if ($user->gender == 'Male') {
            $gender = "<img class='male' src='pic/trans.gif' alt='Male' title='" . $lang['title_male'] . "' style='margin-left: 4pt' />";
        } elseif ($user->gender == 'Female') {
            $gender = "<img class='female' src='pic/trans.gif' alt='Female' title='" . $lang['title_female'] . "' style='margin-left: 4pt' />";
        } elseif ($user->gender == 'N/A') {
            $gender = "<img class='no_gender' src='pic/trans.gif' alt='N/A' title='" . $lang['title_not_available'] . "' style='margin-left: 4pt' />";
        }

        $pageTitle = $lang['head_details_for'] . $user->username;
        $content = '';
        $scripts = [];

        $enabled = $user->enabled == 'yes';
        $moviepicker = $user->picker == 'yes';

        $content .= '<h1 style="margin:0px">' . get_username($user->id, true, false) . $country . "</h1>\n";
        if ($user->valid_medals->isNotEmpty()) {
            $content .= build_medal_image($user->valid_medals, 120, $curUser['id'] == $user->id);
            $scripts[] = <<<JS
jQuery('#save-user-medal-btn').on("click", function (e) {
    let form = jQuery(this).closest('form');
    let data = form.serializeArray();
    console.log(data)
    jQuery.post('ajax.php', {params: data, action: 'saveUserMedal'}, function (response) {
        console.log(response)
        if (response.ret != 0) {
            layer.alert(response.msg)
        } else {
            window.location.reload()
        }
    }, 'json')
})
JS;
        }

        if (!$enabled) {
            $content .= '<p><b>' . $lang['text_account_disabled_note'] . "</b></p>\n";
        } elseif ($curUser['id'] != $user->id) {
            $friend = DB::table('friends')->where('userid', $curUser['id'])->where('friendid', $id)->count();
            $block = DB::table('blocks')->where('userid', $curUser['id'])->where('blockid', $id)->count();

            if ($friend) {
                $content .= '<p>(<a href="friends.php?action=delete&amp;type=friend&amp;targetid=' . $id . '">' . $lang['text_remove_from_friends'] . "</a>)</p>\n";
            } elseif ($block) {
                $content .= '<p>(<a href="friends.php?action=delete&amp;type=block&amp;targetid=' . $id . '">' . $lang['text_remove_from_blocks'] . "</a>)</p>\n";
            } else {
                $content .= '<p>(<a href="friends.php?action=add&amp;type=friend&amp;targetid=' . $id . '">' . $lang['text_add_to_friends'] . '</a>)';
                $content .= ' - (<a href="friends.php?action=add&amp;type=block&amp;targetid=' . $id . '">' . $lang['text_add_to_blocks'] . "</a>)</p>\n";
            }
        }

        if ($curUser['id'] == $user->id || user_can('cruprfmanage')) {
            $content .= '<h2>' . $lang['text_flush_ghost_torrents'] . '<a class="altlink" href="takeflush.php?id=' . $id . '">' . $lang['text_here'] . "</a></h2>\n";
        }

        $content .= "<table width=\"100%\" border=\"1\" cellspacing=\"0\" cellpadding=\"5\">\n";

        $userIdDisplay = $user->id;
        $userManageSystemUrl = sprintf('%s/%s/user/users/%s', getSchemeAndHttpHost(), nexus_env('FILAMENT_PATH', 'nexusphp'), $user->id);
        $userManageSystemText = sprintf('<a href="%s" target="_blank" class="altlink">%s</a>', $userManageSystemUrl, $langFunctions['text_management_system']);
        $migratedHelp = '&nbsp;&nbsp;' . sprintf($lang['change_field_value_migrated'], $userManageSystemText);
        if (user_can('prfmanage') && $user->class < get_user_class()) {
            $userIdDisplay .= '&nbsp;[' . $userManageSystemText . ']';
        }

        if (($user->privacy != 'strong' || user_can('prfmanage')) || $curUser['id'] == $user->id) {
            $content .= $this->row($lang['text_user_id'], (string) $userIdDisplay, 1);
            $tmpInviteCount = $user->temporary_invites()->count();
            if ($curUser['id'] == $user->id || user_can('viewinvite')) {
                if ($user->invites <= 0 && $tmpInviteCount <= 0) {
                    $content .= $this->rowSmall($lang['row_invitation'], $lang['text_no_invitation'], 1);
                } else {
                    $content .= $this->rowSmall($lang['row_invitation'], '<a href="invite.php?id=' . $user->id . '" title="' . $lang['link_send_invitation'] . '">' . sprintf('%s(%s)', $user->invites, $tmpInviteCount) . '</a>', 1);
                }
            } else {
                if ($curUser['id'] != $user->id || get_user_class() != $GLOBALS['viewinvite_class']) {
                    if ($user->invites <= 0) {
                        $content .= $this->rowSmall($lang['row_invitation'], $lang['text_no_invitation'], 1);
                    } else {
                        $content .= $this->row($lang['row_invitation'], $user->invites, 1);
                    }
                }
            }
            if ($user->invited_by > 0) {
                $content .= $this->rowSmall($lang['row_invited_by'], get_username($user->invited_by), 1);
            }
            $content .= $this->rowSmall($lang['row_join_date'], $joindate, 1);
            $content .= $this->rowSmall($lang['row_last_seen'], $lastseen, 1);
            if ($GLOBALS['where_tweak'] == 'yes') {
                $content .= $this->rowSmall($lang['row_last_seen_location'], (string) $user->page, 1);
            }
            if (user_can('userprofile') || $user->privacy == 'low' || $user->id == $curUser['id']) {
                $content .= $this->rowSmall($lang['row_email'], '<a href="mailto:' . $user->email . '">' . $user->email . '</a>', 1);
            }
            if (user_can('userprofile')) {
                $iphistory = DB::table('iplog')->where('userid', $id)->groupBy('ip')->count('ip');
                if ($iphistory > 0) {
                    $content .= $this->rowSmall($lang['row_ip_history'], $lang['text_user_earlier_used'] . '<b><a href="iphistory.php?id=' . $user->id . '">' . $iphistory . $lang['text_different_ips'] . add_s($iphistory, true) . '</a></b>', 1);
                }
            }
            $seedBoxRep = new SeedBoxRepository();
            if (user_can('userprofile') || $user->id == $curUser['id']) {
                $seedBoxIcon = $seedBoxRep->renderIcon($curUser['ip'], $curUser['id']);
                if ($GLOBALS['enablelocation_tweak'] == 'yes') {
                    list($loc_pub, $loc_mod) = get_ip_location($user->ip);
                    $locationinfo = '<span title="' . $loc_mod . '">[' . $loc_pub . ']</span>';
                } else {
                    $locationinfo = '';
                }
                $ip = $user->ip;
                $content .= $this->rowSmall($lang['row_ip_address'], hide_text($ip . $locationinfo . $seedBoxIcon), 1);
            }

            $clientselect = '';
            $peerRows = DB::table('peers')
                ->selectRaw('min(peer_id) as peer_id, agent, ipv4, ipv6, port')
                ->where('userid', $user->id)
                ->groupBy('agent', 'ipv4', 'ipv6', 'port')
                ->orderBy('peer_id')
                ->get();
            if ($peerRows->isNotEmpty()) {
                $clientselect .= "<table border='1' cellspacing='0' cellpadding='5'><tr><td class='colhead'>Agent</td><td class='colhead'>IPV4</td><td class='colhead'>IPV6</td><td class='colhead'>Port</td></tr>";
                foreach ($peerRows as $peerRow) {
                    $clientselect .= '<tr>';
                    $clientselect .= sprintf('<td>%s</td>', get_agent($peerRow->peer_id, $peerRow->agent));
                    if (user_can('userprofile') || $user->id == $curUser['id']) {
                        $v4 = $user->id == $curUser['id'] ? hide_text($peerRow->ipv4) : $peerRow->ipv4;
                        $v6 = $user->id == $curUser['id'] ? hide_text($peerRow->ipv6) : $peerRow->ipv6;
                        $clientselect .= sprintf(
                            '<td>%s</td><td>%s</td><td>%s</td>',
                            $v4 . $seedBoxRep->renderIcon($peerRow->ipv4, $user->id),
                            $v6 . $seedBoxRep->renderIcon($peerRow->ipv6, $user->id),
                            $peerRow->port
                        );
                    } else {
                        $clientselect .= '<td>---</td><td>---</td><td>---</td>';
                    }
                    $clientselect .= '</tr>';
                }
                $clientselect .= '</table>';
            }
            if ($clientselect) {
                $content .= $this->rowSmall($lang['row_bt_client'], $clientselect, 1);
            }

            // 真实分享、上传、下载率显示
            $trueRow = DB::table('snatched')
                ->where('userid', $user->id)
                ->selectRaw('SUM(uploaded) as up, SUM(downloaded) as dl')
                ->first();
            $true_upload = (int) ($trueRow->up ?? 0);
            $true_download = (int) ($trueRow->dl ?? 0);

            $sr = null;
            if ($user->downloaded > 0 && $true_download > 0) {
                $sr = floor($user->uploaded / $user->downloaded * 1000) / 1000;
                $true_ratio = floor($true_upload / $true_download * 1000) / 1000;
                $sr = '<tr><td class="embedded"><strong>' . $lang['row_share_ratio'] . '</strong>:  <font color="' . get_ratio_color($sr) . '">' . number_format($sr, 3) . '</font>（<strong>' . $lang['row_real_share_ratio'] . '</strong>：' . number_format($true_ratio, 3) . '）</td><td class="embedded">&nbsp;&nbsp;' . get_ratio_img($sr) . '</td></tr>';
            }

            $xfer = '<tr><td class="embedded"><strong>' . $lang['row_uploaded'] . '</strong>:  ' . mksize($user->uploaded) . '</td><td class="embedded">&nbsp;&nbsp;<strong>' . $lang['row_downloaded'] . '</strong>:  ' . mksize($user->downloaded) . '</td></tr>';
            $true_xfer = '<tr><td class="embedded"><strong>' . $lang['row_real_uploaded'] . '</strong>:  ' . mksize($true_upload) . '</td><td class="embedded">&nbsp;&nbsp;<strong>' . $lang['row_real_downloaded'] . '</strong>:  ' . mksize($true_download) . '</td><td class="embedded text-muted">&nbsp;&nbsp;' . $lang['row_real_ps'] . '</td></tr>';
            $content .= $this->rowSmall($lang['row_transfer'], '<table border="0" cellspacing="0" cellpadding="0">' . ($sr ?? '') . $xfer . $true_xfer . '</table>', 1);

            $slr = null;
            if ($user->leechtime > 0) {
                $slr = floor($user->seedtime / $user->leechtime * 1000) / 1000;
                $slr = '<tr><td class="embedded"><strong>' . $lang['text_seeding_leeching_time_ratio'] . '</strong>:  <font color="' . get_ratio_color($slr) . '">' . number_format($slr, 3) . '</font></td><td class="embedded">&nbsp;&nbsp;' . get_ratio_img($slr) . '</td></tr>';
            }

            $slt = '<tr><td class="embedded"><strong>' . $lang['text_seeding_time'] . '</strong>:  ' . mkprettytime($user->seedtime) . '</td><td class="embedded">&nbsp;&nbsp;<strong>' . $lang['text_leeching_time'] . '</strong>:  ' . mkprettytime($user->leechtime) . '</td><td class="embedded text-muted">&nbsp;&nbsp;(' . nexus_trans('label.updated_at') . ': ' . $user->seed_time_updated_at . ')</td></tr>';
            $content .= $this->rowSmall($lang['row_sltime'], '<table border="0" cellspacing="0" cellpadding="0">' . ($slr ?? '') . $slt . '</table>', 1);

            if ($user->download && $user->upload) {
                $content .= $this->rowSmall($lang['row_internet_speed'], $download . '&nbsp;&nbsp;&nbsp;&nbsp;' . $upload . '&nbsp;&nbsp;&nbsp;&nbsp;' . $isp, 1);
            }
            $content .= $this->rowSmall($lang['row_gender'], $gender ?: '', 1);

            if (($user->donated > 0 || $user->donated_cny > 0) && (user_can('userprofile') || $curUser['id'] == $user->id)) {
                $content .= $this->rowSmall($lang['row_donated'], '$' . htmlspecialchars($user->donated) . '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' . htmlspecialchars($user->donated_cny), 1);
            }

            if ($user->avatar) {
                $content .= $this->rowSmall($lang['row_avatar'], return_avatar_image(htmlspecialchars(trim($user->avatar))), 1);
            }

            $uclass = get_user_class_image($user->class);
            $uclassImg = '<img alt="' . get_user_class_name($user->class, false, false, true) . '" title="' . get_user_class_name($user->class, false, false, true) . '" src="' . $uclass . '" /> ' . ($user->title !== '' ? '&nbsp;' . htmlspecialchars(trim($user->title)) : '');
            if ($user->class == UC_VIP && !empty($user->vip_until) && strtotime($user->vip_until)) {
                $uclassImg .= sprintf('%s: %s', $lang['row_vip_until'], $user->vip_until);
            }
            $content .= $this->rowSmall($lang['row_class'], $uclassImg, 1);

            // User meta
            $metas = $userRep->listMetas($user->id);
            $props = [];
            $metaKey = UserMeta::META_KEY_CHANGE_USERNAME;
            if ($metas->has($metaKey)) {
                $triggerId = 'consume-' . $metaKey;
                $changeUsernameCards = $metas->get($metaKey);
                $cardName = $changeUsernameCards->first()->meta_key_text;
                $useInput = '';
                if ($curUser['id'] == $user->id) {
                    $useInput = sprintf('<input type="button" value="%s" id="%s">', $lang['consume'], $triggerId);
                }
                $props[] = sprintf(
                    '<div><strong>[%s]</strong>(%s)</div>%s',
                    $cardName, $changeUsernameCards->count(), $useInput
                );
                if ($useInput) {
                    $consumeChangeUsernameForm = <<<HTML
<div class="layer-form">
<form id="layer-form-{$metaKey}">
    <input type="hidden" name="params[meta_key]" value="{$metaKey}">
    <div class="form-control-row">
        <div class="label">{$lang['meta_key_change_username_username']}</div>
        <div class="field"><input type="text" name="params[username]"></div>
    </div>
</form>
</div>
HTML;
                    $scripts[] = <<<JS
jQuery('#{$triggerId}').on("click", function () {
    layer.open({
        type: 1,
        title: "{$lang['consume']} {$cardName}",
        content: `$consumeChangeUsernameForm`,
        btn: ['OK'],
        btnAlign: 'c',
        yes: function () {
            let params = jQuery('#layer-form-{$metaKey}').serialize()
            jQuery.post('ajax.php', params + "&action=consumeBenefit", function (response) {
                console.log(response)
                if (response.ret != 0) {
                    layer.alert(response.msg)
                    return
                }
                window.location.reload()
            }, 'json')
        }
    })
})
JS;
                }
            }

            $metaKey = UserMeta::META_KEY_PERSONALIZED_USERNAME;
            if ($metas->has($metaKey)) {
                $rainbowID = $metas->get($metaKey)->first();
                if ($rainbowID->isValid()) {
                    $props[] = sprintf(
                        '<div><strong>[%s]</strong>(%s)</div>',
                        $rainbowID->metaKeyText, $rainbowID->getDeadlineText()
                    );
                }
            }

            if (!empty($props)) {
                $content .= $this->rowSmall($lang['row_user_props'], sprintf('<div style="display: flex;align-items: center">%s</div>', implode('&nbsp;|&nbsp;', $props)), 1);
            }

            do_action('user_detail_rows', $user->id, 'web');

            $content .= $this->rowSmall($lang['row_torrent_comment'], ($torrentcomments && ($user->id == $curUser['id'] || user_can('viewhistory')) ? '<a href="userhistory.php?action=viewcomments&amp;id=' . $id . '" title="' . $lang['link_view_comments'] . '">' . $torrentcomments . '</a>' : $torrentcomments), 1);

            $content .= $this->rowSmall($lang['row_forum_posts'], ($forumposts && ($user->id == $curUser['id'] || user_can('viewhistory')) ? '<a href="userhistory.php?action=viewposts&amp;id=' . $id . '" title="' . $lang['link_view_posts'] . '">' . $forumposts . '</a>' : $forumposts), 1);

            if ($user->id == $curUser['id'] || user_can('viewhistory')) {
                if (HitAndRun::getIsEnabled()) {
                    $hrStatus = (new HitAndRunRepository())->getStatusStats($user->id);
                    $content .= $this->rowSmall('H&R', sprintf('<a href="myhr.php?userid=%s" target="_blank">%s</a>', $user->id, $hrStatus), 1);
                }
                if (Claim::getConfigIsEnabled()) {
                    $states = (new ClaimRepository())->getStats($user->id);
                    $content .= $this->rowSmall($langFunctions['menu_claim'], sprintf('<a href="claim.php?uid=%s" target="_blank">%s</a>', $user->id, $states), 1);
                }
                $bonusLogText = sprintf('&nbsp;&nbsp;<a href="bonus-log.php?uid=%s" target="_blank" class="altlink">[%s]</a>', $user->id, nexus_trans('bonus-log.view_detail'));
                $content .= $this->rowSmall($lang['row_karma_points'], number_format($user->seedbonus, 1) . $bonusLogText, 1);
                $content .= $this->rowSmall($langFunctions['text_seed_points'], number_format($user->seed_points, 1) . '&nbsp;&nbsp;<span class=\'text-muted\'>( ' . nexus_trans('label.updated_at') . ': ' . $user->seed_points_updated_at . ')</span>', 1);
            }

            if (user_can('prfmanage') && $user->class < get_user_class()) {
                $bonusTable = build_bonus_table($user->toArray());
                $content .= $this->rowSmall($lang['text_bonus_table'], $bonusTable['table'], 1);
            }

            if ($user->ip && (user_can('torrenthistory') || $user->id == $curUser['id'])) {
                $content .= $this->rowSmall($lang['row_uploaded_torrents'], '<a href="javascript: getusertorrentlistajax(\'' . $user->id . '\', \'uploaded\', \'ka\'); klappe_news(\'a\')"><img class="plus" src="pic/trans.gif" id="pica" alt="Show/Hide" title="' . $lang['title_show_or_hide'] . '" />   <u>' . $lang['text_show_or_hide'] . '</u></a><div id="ka" style="display: none;" data-type=\'uploaded\'></div>', 1);

                $content .= $this->rowSmall($lang['row_current_seeding'], '<a href="javascript: getusertorrentlistajax(\'' . $user->id . '\', \'seeding\', \'ka1\'); klappe_news(\'a1\')"><img class="plus" src="pic/trans.gif" id="pica1" alt="Show/Hide" title="' . $lang['title_show_or_hide'] . '" />   <u>' . $lang['text_show_or_hide'] . '</u></a><div id="ka1" style="display: none;" data-type=\'seeding\'></div>', 1);

                $content .= $this->rowSmall($lang['row_current_leeching'], '<a href="javascript: getusertorrentlistajax(\'' . $user->id . '\', \'leeching\', \'ka2\'); klappe_news(\'a2\')"><img class="plus" src="pic/trans.gif" id="pica2" alt="Show/Hide" title="' . $lang['title_show_or_hide'] . '" />   <u>' . $lang['text_show_or_hide'] . '</u></a><div id="ka2" style="display: none;" data-type=\'leeching\'></div>', 1);

                $content .= $this->rowSmall($lang['row_completed_torrents'], '<a href="javascript: getusertorrentlistajax(\'' . $user->id . '\', \'completed\', \'ka3\'); klappe_news(\'a3\')"><img class="plus" src="pic/trans.gif" id="pica3" alt="Show/Hide" title="' . $lang['title_show_or_hide'] . '" />   <u>' . $lang['text_show_or_hide'] . '</u></a><div id="ka3" style="display: none;" data-type=\'completed\'></div>', 1);

                $content .= $this->rowSmall($lang['row_incomplete_torrents'], '<a href="javascript: getusertorrentlistajax(\'' . $user->id . '\', \'incomplete\', \'ka4\'); klappe_news(\'a4\')"><img class="plus" src="pic/trans.gif" id="pica4" alt="Show/Hide" title="' . $lang['title_show_or_hide'] . '" />   <u>' . $lang['text_show_or_hide'] . '</u></a><div id="ka4" style="display: none;" data-type=\'incomplete\'></div>', 1);
            }

            if ($user->info) {
                $content .= '<tr><td align="left" colspan="2" class="text">' . format_comment($user->info, false) . "</td></tr>\n";
            }
        } else {
            $content .= '<tr><td align="left" colspan="2" class="text"><font color="blue">' . $lang['text_public_access_denied'] . $user->username . $lang['text_user_wants_privacy'] . "</font></td></tr>\n";
        }

        $showpmbutton = 0;
        if ($curUser['id'] != $user->id) {
            if (user_can('staffmem')) {
                $showpmbutton = 1;
            } elseif ($user->acceptpms == 'yes') {
                $block = DB::table('blocks')->where('userid', $user->id)->where('blockid', $curUser['id'])->count();
                $showpmbutton = ($block == 1 ? 0 : 1);
            } elseif ($user->acceptpms == 'friends') {
                $friend = DB::table('friends')->where('userid', $user->id)->where('friendid', $curUser['id'])->count();
                $showpmbutton = ($friend == 1 ? 1 : 0);
            }
            $content .= '<tr><td colspan="2" align="center">';
            if ($showpmbutton) {
                $content .= '<a href="sendmessage.php?receiver=' . htmlspecialchars($user->id) . '"><img class="f_pm" src="pic/trans.gif" alt="PM" title="' . $lang['title_send_pm'] . '" /></a>';
            }
            $content .= '<a href="report.php?user=' . htmlspecialchars($user->id) . '"><img class="f_report" src="pic/trans.gif" alt="Report" title="' . $lang['title_report_user'] . '" /></a>';
            $content .= '</td></tr>';
        }
        $content .= "</table>\n";

        if (user_can('prfmanage') && $user->class < get_user_class()) {
            $content .= $this->capture(function () use ($lang, $langFunctions, $user, $id, $migratedHelp, $curUser, &$scripts) {
                begin_frame($lang['text_edit_user'], true);
                print('<form method="post" action="modtask.php">');
                print('<input type="hidden" name="action" value="edituser" />');
                print('<input type="hidden" name="userid" value="' . $id . '" />');
                print('<input type="hidden" name="returnto" value="' . htmlspecialchars('userdetails.php?id=' . $id) . '" />');
                print('<table width="100%" class="main" border="1" cellspacing="0" cellpadding="5">' . "\n");
                tr($lang['row_title'], '<input type="text" size="60" name="title" value="' . htmlspecialchars(trim($user->title)) . '" />', 1);
                $avatar = htmlspecialchars(trim($user->avatar));

                tr($lang['row_privacy_level'], '<input type="radio" name="privacy" value="low"' . ($user->privacy == 'low' ? ' checked="checked"' : '') . ' />' . $lang['radio_low'] . '<input type="radio" name="privacy" value="normal"' . ($user->privacy == 'normal' ? ' checked="checked"' : '') . ' />' . $lang['radio_normal'] . '<input type="radio" name="privacy" value="strong"' . ($user->privacy == 'strong' ? ' checked="checked"' : '') . ' />' . $lang['radio_strong'], 1);
                tr($lang['row_avatar_url'], '<input type="text" size="60" name="avatar" value="' . $avatar . '" />', 1);
                $signature = trim($user->signature);
                tr($lang['row_signature'], '<textarea cols="60" rows="6" name="signature">' . $signature . '</textarea>', 1);

                if (get_user_class() == UC_STAFFLEADER) {
                    tr($lang['row_donor_status'], '<input type="radio" name="donor" value="yes"' . ($user->donor == 'yes' ? ' checked="checked"' : '') . ' />' . $lang['radio_yes'] . ' <input type="radio" name="donor" value="no"' . ($user->donor == 'no' ? ' checked="checked"' : '') . '>' . $lang['radio_no'], 1);
                    tr($lang['row_donated'], 'USD: <input type="text" size="5" name="donated" value="' . htmlspecialchars($user->donated) . '" />&nbsp;&nbsp;&nbsp;&nbsp;CNY: <input type="text" size="5" name="donated_cny" value="' . htmlspecialchars($user->donated_cny) . '" />' . $lang['text_transaction_memo'] . '<input type="text" size="50" name="donation_memo" />', 1);
                    tr($lang['row_donoruntil'], '<input type="text" name="donoruntil" value="' . htmlspecialchars($user->donoruntil) . '" /> ' . $lang['text_donoruntil_note'], 1);
                }
                if (user_can('user-change-class')) {
                    $maxclass = get_user_class() - 1;
                    $classselect = classlist('class', $maxclass, $user->class, 0, false, true);
                    tr($lang['row_class'], $classselect . $migratedHelp, 1);
                }
                tr($lang['row_vip_by_bonus'], '<input type="radio" name="vip_added" value="yes"' . ($user->vip_added == 'yes' ? ' checked="checked"' : '') . " disabled='disabled'/>" . $lang['radio_yes'] . ' <input type="radio" name="vip_added" value="no"' . ($user->vip_added == 'no' ? ' checked="checked"' : '') . " disabled='disabled'/>" . $lang['radio_no'] . $migratedHelp, 1);
                tr($lang['row_vip_until'], '<input type="text" name="vip_until" value="' . htmlspecialchars($user->vip_until) . '" disabled=\'disabled\'/> ' . $lang['text_vip_until_note'] . $migratedHelp, 1);
                $supportlang = htmlspecialchars($user->supportlang);
                $supportfor = htmlspecialchars($user->supportfor);
                $pickfor = htmlspecialchars($user->pickfor);
                $staffduties = htmlspecialchars($user->stafffor);

                tr($lang['row_staff_duties'], '<textarea cols="60" rows="6" name="staffduties">' . $staffduties . '</textarea>', 1);
                tr($lang['row_support_language'], '<input type="text" name="supportlang" value="' . $supportlang . '" />', 1);
                tr($lang['row_support'], '<input type="radio" name="support" value="yes"' . ($user->support == 'yes' ? ' checked="checked"' : '') . ' />' . $lang['radio_yes'] . ' <input type="radio" name="support" value="no"' . ($user->support == 'no' ? ' checked="checked"' : '') . ' />' . $lang['radio_no'], 1);
                tr($lang['row_support_for'], '<textarea cols="60" rows="6" name="supportfor">' . $supportfor . '</textarea>', 1);

                $moviepicker = $user->picker == 'yes';
                tr($lang['row_movie_picker'], '<input name="moviepicker" value="yes" type="radio"' . ($moviepicker ? ' checked="checked"' : '') . ' />' . $lang['radio_yes'] . '<input name="moviepicker" value="no" type="radio"' . (!$moviepicker ? ' checked="checked"' : '') . ' />' . $lang['radio_no'], 1);
                tr($lang['row_pick_for'], '<textarea cols="60" rows="6" name="pickfor">' . $pickfor . '</textarea>', 1);

                if (user_can('cruprfmanage')) {
                    $modcomment = UserModifyLog::query()
                        ->where('user_id', $user->id)
                        ->orderBy('id', 'desc')
                        ->limit(20)
                        ->get()
                        ->map(fn ($item) => sprintf('[%s] %s', $item->created_at->format('Y-m-d'), $item->content))
                        ->implode("\n")
                    ;
                    tr($lang['row_comment'], '<textarea cols="60" rows="6" name="modcomment">' . $modcomment . '</textarea>', 1);
                    $bonuscomment = BonusLogs::query()
                        ->where('uid', $user->id)
                        ->whereNotIn('business_type', BonusLogs::$businessTypeSeeding)
                        ->orderBy('id', 'desc')
                        ->limit(20)
                        ->get()
                        ->map(fn ($item) => sprintf('[%s] %s', $item->created_at->format('Y-m-d'), $item->comment))
                        ->implode("\n")
                    ;
                    tr($lang['row_seeding_karma'], '<textarea cols="60" rows="6" name="bonuscomment" readonly="readonly">' . $bonuscomment . '</textarea>', 1);
                }
                $warned = $user->warned == 'yes';

                print('<tr><td class="rowhead">' . $lang['row_warning_system'] . '</td><td class="rowfollow" align="left" ><table class="main" cellspacing="0" cellpadding="5"><tr><td class="rowfollow">' . ($warned ? '<input name="warned" value="yes" type="radio" checked="checked" />' . $lang['radio_yes'] . '<input name="warned" value="no" type="radio" />' . $lang['radio_no'] : $lang['text_not_warned']) . '</td>');

                if ($warned) {
                    $warneduntil = $user->warneduntil;
                    if ($warneduntil == '0000-00-00 00:00:00' || $warneduntil == null) {
                        print('<td align="center" class="rowfollow">' . $lang['text_arbitrary_duration'] . "</td>\n");
                    } else {
                        print('<td align="left" class="rowfollow">' . $lang['text_until'] . $warneduntil);
                        print('<br />(' . mkprettytime(strtotime($warneduntil) - strtotime(date('Y-m-d H:i:s'))) . $lang['text_to_go'] . ")</td>\n");
                    }
                    print('</tr>');
                } else {
                    print('<td align="left" class="rowfollow">' . $lang['text_warn_for'] . '<select name="warnlength">' . "\n");
                    print('<option value="0">------</option>' . "\n");
                    print('<option value="1">1 ' . $lang['text_week'] . '</option>' . "\n");
                    print('<option value="2">2 ' . $lang['text_weeks'] . '</option>' . "\n");
                    print('<option value="4">4 ' . $lang['text_weeks'] . '</option>' . "\n");
                    print('<option value="8">8 ' . $lang['text_weeks'] . '</option>' . "\n");
                    print('<option value="255">' . $lang['text_unlimited'] . '</option>' . "\n");
                    print("</select></td></tr>\n");
                    print('<tr><td align="left" class="rowfollow">' . $lang['text_reason_of_warning'] . '</td><td align="left" class="rowfollow"><input type="text" size="60" name="warnpm" /></td></tr>');
                }

                $elapsedlw = get_elapsed_time(strtotime($user->lastwarned));
                print('<tr><td align="left" class="rowfollow">' . $lang['text_times_warned'] . '</td><td align="left" class="rowfollow">' . $user->timeswarned . "</td></tr>\n");

                if ($user->timeswarned == 0) {
                    print('<tr><td align="left" class="rowfollow">' . $lang['text_last_warning'] . '</td><td align="left" class="rowfollow">' . $lang['text_not_warned_note'] . "</td></tr>\n");
                } else {
                    $warnedby = '';
                    if ($user->warnedby != 'System') {
                        $arr = DB::table('users')->where('id', $user->warnedby)->first();
                        $warnedby = '<br />[' . $lang['text_by'] . '<u>' . ($arr ? get_username($arr->id) : '[' . $user->warnedby . ']') . '</u>]';
                    } else {
                        $warnedby = '<br />[' . $lang['text_by_system'] . ']';
                        print('<tr><td class="rowfollow">' . $lang['text_last_warning'] . '</td><td align="left" class="rowfollow"> ' . $user->lastwarned . ' (' . $lang['text_until'] . $elapsedlw . ')   ' . $warnedby . '</td></tr>' . "\n");
                    }
                    print('<tr><td class="rowfollow">' . $lang['text_last_warning'] . '</td><td align="left" class="rowfollow"> ' . $user->lastwarned . ' (' . $elapsedlw . $lang['text_ago'] . ')   ' . $warnedby . "</td></tr>\n");
                }

                $leechwarn = $user->leechwarn == 'yes';
                print('<tr><td class="rowfollow">' . $lang['row_auto_warning'] . '<br /><i>(' . $lang['text_low_ratio'] . ')</i></td>');

                if ($leechwarn) {
                    print('<td align="left" class="rowfollow"><font color="red">' . $lang['text_leech_warned'] . '</font> ');
                    $leechwarnuntil = $user->leechwarnuntil;
                    if ($leechwarnuntil != '0000-00-00 00:00:00' || $leechwarnuntil != null) {
                        print($lang['text_until'] . $leechwarnuntil);
                        print('<br />(' . mkprettytime(strtotime($leechwarnuntil) - strtotime(date('Y-m-d H:i:s'))) . $lang['text_to_go'] . ')');
                        printf(
                            '&nbsp;<input id="remove-leech-warn" type="button" class="btn" value="Remove" data-uid="%s" />',
                            $user->id
                        );
                        $scripts[] = <<<JS
jQuery('#remove-leech-warn').on('click', function () {
    if (!window.confirm('{$lang['sure_to_remove_leech_warn']}')) {
        return
    }
    let params = {action: 'removeUserLeechWarn', params: {uid: jQuery(this).attr('data-uid')}}
    jQuery.post('ajax.php', params, function (response) {
        console.log(response)
        if (response.ret == 0) {
            location.reload()
        } else {
            alert(response.msg)
        }
    }, 'json')
})
JS;
                    } else {
                        print('<i>' . $lang['text_for_unlimited_time'] . '</i>');
                    }
                    print('</td></tr>');
                } else {
                    print('<td class="rowfollow">' . $lang['text_no_warned'] . "</td></tr>\n");
                }
                print('</table></td></tr>');
                tr($lang['row_enabled'], $migratedHelp, 1);
                tr($lang['row_forum_post_possible'], '<input type="radio" name="forumpost" value="yes"' . ($user->forumpost == 'yes' ? ' checked="checked"' : '') . ' />' . $lang['radio_yes'] . '<input type="radio" name="forumpost" value="no"' . ($user->forumpost == 'no' ? ' checked="checked"' : '') . ' />' . $lang['radio_no'], 1);
                tr($lang['row_upload_possible'], '<input type="radio" name="uploadpos" value="yes"' . ($user->uploadpos == 'yes' ? ' checked="checked"' : '') . ' />' . $lang['radio_yes'] . '<input type="radio" name="uploadpos" value="no"' . ($user->uploadpos == 'no' ? ' checked="checked"' : '') . ' />' . $lang['radio_no'], 1);
                tr($lang['row_download_possible'], '<input type="radio" name="downloadpos" value="yes"' . ($user->downloadpos == 'yes' ? ' checked="checked"' : '') . ' />' . $lang['radio_yes'] . '<input type="radio" name="downloadpos" value="no"' . ($user->downloadpos == 'no' ? ' checked="checked"' : '') . ' />' . $lang['radio_no'], 1);
                tr($lang['row_show_ad'], '<input type="radio" name="noad" value="no"' . ($user->noad == 'no' ? ' checked="checked"' : '') . ' />' . $lang['radio_yes'] . '<input type="radio" name="noad" value="yes"' . ($user->noad == 'yes' ? ' checked="checked"' : '') . ' />' . $lang['radio_no'], 1);
                tr($lang['row_no_ad_until'], '<input type="text" name="noaduntil" value="' . htmlspecialchars($user->noaduntil) . '" /> ' . $lang['text_no_ad_until_note'], 1);
                if (user_can('cruprfmanage')) {
                    tr($lang['row_change_username'], '<input type="text" size="25" name="username" value="' . htmlspecialchars($user->username) . '" />', 1);
                    tr($lang['row_change_email'], '<input type="text" size="80" name="email" value="' . htmlspecialchars($user->email) . '" />', 1);
                }

                tr($lang['row_change_password'], '<input disabled type="password" name="chpassword" size="50" />' . $migratedHelp, 1);
                tr($lang['row_repeat_password'], '<input disabled type="password" name="passagain" size="50" />' . $migratedHelp, 1);

                if (user_can('cruprfmanage')) {
                    tr($lang['row_amount_uploaded'], '<input disabled type="text" size="60" name="uploaded" value="' . htmlspecialchars($user->uploaded) . '" /><input type="hidden" name="ori_uploaded" value="' . htmlspecialchars($user->uploaded) . '" />' . $migratedHelp, 1);
                    tr($lang['row_amount_downloaded'], '<input disabled type="text" size="60" name="downloaded" value="' . htmlspecialchars($user->downloaded) . '" /><input type="hidden" name="ori_downloaded" value="' . htmlspecialchars($user->downloaded) . '" />' . $migratedHelp, 1);
                    tr($lang['row_seeding_karma'], '<input disabled type="text" size="60" name="bonus" value="' . number_format($user->seedbonus, 1) . '" /><input type="hidden" name="ori_bonus" value="' . number_format($user->seedbonus, 1) . '" />' . $migratedHelp, 1);
                    tr($lang['row_invites'], '<input disabled type="text" size="60" name="invites" value="' . htmlspecialchars($user->invites) . '" />' . $migratedHelp, 1);
                }
                tr($lang['row_passkey'], '<input name="resetkey" value="yes" type="checkbox" />' . $lang['checkbox_reset_passkey'], 1);

                print('<tr><td class="toolbox" colspan="2" align="center"><input type="submit" class="btn" value="' . $lang['submit_okay'] . '" /></td></tr>' . "\n");
                print("</table>\n");
                print("</form>\n");
                end_frame();
                if (user_can('user-delete')) {
                    begin_frame($lang['text_delete_user'], true);
                    print('<form method="post" action="delacctadmin.php" name="deluser">
		<input name="userid" size="10" type="hidden" value="' . $user->id . '" />
		<input name="delenable" type="checkbox" onclick="if (this.checked) {enabledel(\'' . $lang['js_delete_user_note'] . '\');}else{disabledel();}" /><input name="submit" type="submit" value="' . $lang['submit_delete'] . '" disabled="disabled" /></form>');
                    end_frame();
                }
            });
        }

        $claimAllSeedingConfirmation = nexus_trans('claim.claim_all_seeding_confirmation');
        $claimJs = '';
        if ($user->id == $curUser['id'] && has_role_work_seeding($user->id)) {
            $claimJs = <<<JS
jQuery("body").on("click", "#claim-all-seeding", function (e) {
    layer.confirm("$claimAllSeedingConfirmation", {}, function () {
        jQuery.post('/plugin/claim_all_seeding', {"action": "claimAllSeeding"}, function (response) {
            if (response.ret == 0) {
                window.location.reload()
            } else {
                layer.alert(response.msg)
            }
        }, 'json')
    })
})
JS;
        }
        $paginationJs = <<<JS
jQuery("body").on("click", ".nexus-pagination a", function (e) {
    e.preventDefault()
    let _this = jQuery(this)
    let box = _this.closest("[data-type]")
    let type = box.attr("data-type");
    let url = _this.attr("href") + "&userid={$user->id}&type=" + type;
    let result = ajax.gets(url);
    box.html(result)
})
$claimJs
JS;
        $scripts[] = $paginationJs;

        return view('user.details', compact('lang', 'pageTitle', 'content', 'scripts'));
    }

    /**
     * Render a single table row via the legacy tr_small() helper but return it
     * as a string instead of printing it.
     */
    private function row(string $x, string $y, int $noesc = 0): string
    {
        return (string) tr($x, $y, $noesc, '', true);
    }

    /**
     * Render a single table row via the legacy tr_small() helper but return it
     * as a string instead of printing it.
     */
    private function rowSmall(string $x, string $y, int $noesc = 0): string
    {
        return (string) tr_small($x, $y, $noesc, '', true);
    }

    /**
     * Capture legacy helpers that print directly (begin_frame/end_frame, ...).
     */
    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return array
     */
    public function index(Request $request)
    {
        $result = $this->repository->getList($request->all());
        $resource = UserResource::collection($result);
        return $this->success($resource);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function store(Request $request)
    {
        $rules = [
            'username' => 'required|string',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:6|max:40',
            'password_confirmation' => 'required|string|same:password'
        ];
        $request->validate($rules);
        $result = $this->repository->store($request->all());
        $resource = new UserResource($result);
        return $this->success($resource);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return array
     */
    public function show($id = null)
    {
        $currentUser = Auth::user();
        if ($id === null) {
            $id = $currentUser->id;
        }
        $result = $this->repository->getDetail($id, $currentUser);
        $resource = new UserResource($result);
        return $this->success($resource);
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

    public function resetPassword(Request $request)
    {
        $rules = [
            'uid' => 'required',
            'password' => 'required|string|min:6|max:40',
            'password_confirmation' => 'required|same:password',
        ];
        $request->validate($rules);
        $result = $this->repository->resetPassword($request->uid, $request->password, $request->password_confirmation);
        return $this->success($result, 'Reset password success!');
    }

    public function classes()
    {
        $result = $this->repository->listClass();
        return $this->success($result);
    }

    public function base()
    {
        $id = Auth::id();
        $result = $this->repository->getBase($id);
        $resource = new UserResource($result);
        return $this->success($resource);
    }

    public function matchExams(Request $request)
    {
        $request->validate([
            'uid' => 'required',
        ]);
        $examRepository = new ExamRepository();
        $result = $examRepository->listMatchExam($request->uid);
        $resource = ExamResource::collection($result);
        return $this->success($resource);
    }

    public function disable(Request $request)
    {
        $request->validate([
            'uid' => 'required',
            'reason' => 'required',
        ]);
        $result = $this->repository->disableUser(Auth::user(), $request->uid, $request->reason);
        return $this->success($result, 'Disable user success!');
    }

    public function enable(Request $request)
    {
        $request->validate([
            'uid' => 'required',
        ]);
        $result = $this->repository->enableUser(Auth::user(), $request->uid);
        return $this->success($result, 'Enable user success!');
    }

    public function inviteInfo(Request $request)
    {
        $request->validate([
            'uid' => 'required',
        ]);
        $result = $this->repository->getInviteInfo($request->uid);
        $resource = $result ? (new InviteResource($result)) : null;
        return $this->success($resource);
    }

    public function modComment(Request $request)
    {
        $request->validate([
            'uid' => 'required',
        ]);
        $result = $this->repository->getModComment($request->uid);
        return $this->success($result);
    }

    public function me()
    {
        $user = Auth::user();

        $resource = $this->getUserProfile($user->id);
//
//        $rows = [
//            [
//                ['icon' => 'icon-user', 'label' => '种子评论', 'name' => 'comments_count'],
//                ['icon' => 'icon-user', 'label' => '论坛帖子', 'name' => 'posts_count'],
//            ],[
//                ['icon' => 'icon-user', 'label' => '发布种子', 'name' => 'torrents_count'],
//                ['icon' => 'icon-user', 'label' => '当前做种', 'name' => 'seeding_torrents_count'],
//                ['icon' => 'icon-user', 'label' => '当前下载', 'name' => 'leeching_torrents_count'],
//                ['icon' => 'icon-user', 'label' => '完成种子', 'name' => 'completed_torrents_count'],
//                ['icon' => 'icon-user', 'label' => '未完成种子', 'name' => 'incomplete_torrents_count'],
//            ]
//        ];
//        $resource->additional([
//            'card_titles' => User::$cardTitles,
//            'rows' => $rows
//        ]);

        return $this->success($resource);
    }

    private function getUserProfile($id)
    {
        $user = User::query()->withCount([
            'comments', 'posts', 'seeding_torrents', 'leeching_torrents',
            'torrents' => function ($query) use ($id) {$query->whereHas('snatches');},
            'completed_torrents' => function ($query) use ($id) {$query->where('torrents.owner', '!=', $id);},
            'incomplete_torrents' => function ($query) use ($id) {$query->where('torrents.owner', '!=', $id);},
        ])->findOrFail($id);
        $resource = new UserResource($user);
        return $resource;
    }

    public function publishTorrent(Request $request)
    {
        $user = Auth::user();

        $result = $user->torrents()->orderBy('id', 'desc')->paginate();

        $resource = TorrentResource::collection($result);

        return $resource;

    }

    public function seedingTorrent(Request $request)
    {
        $user = Auth::user();

        $result = $user->peers_torrents()->where('seeder', Peer::SEEDER_YES)->orderBy('torrent', 'desc')->paginate();

        $resource = TorrentResource::collection($result);

        return $resource;

    }

    public function LeechingTorrent(Request $request)
    {
        $user = Auth::user();

        $result = $user->peers_torrents()->where('seeder', Peer::SEEDER_NO)->orderBy('torrent', 'desc')->paginate();

        $resource = TorrentResource::collection($result);

        return $resource;

    }

    public function finishedTorrent(Request $request)
    {
        $user = Auth::user();

        $result = $user->snatched_torrents()
            ->where('owner', '<>', $user->id)
            ->where('finished', Snatch::FINISHED_YES)
            ->orderBy('torrentid', 'desc')
            ->paginate();

        $resource = TorrentResource::collection($result);

        return $resource;

    }

    public function notFinishedTorrent(Request $request)
    {
        $user = Auth::user();

        $result = $user->snatched_torrents()
            ->where('owner', '<>', $user->id)
            ->where('finished', Snatch::FINISHED_NO)
            ->orderBy('torrentid', 'desc')
            ->paginate();

        $resource = TorrentResource::collection($result);

        return $resource;

    }

    public function incrementDecrement(Request $request): array
    {
        $user = Auth::user();
        $request->validate([
            'uid' => 'required',
            'action' => 'required',
            'field' => 'required',
            'value' => 'required|numeric',
        ]);
        $result = $this->repository->incrementDecrement($user, $request->uid, $request->action, $request->field, $request->value, $request->reason);
        return $this->success(['success' => $result]);
    }

    public function removeTwoStepAuthentication(Request $request): array
    {
        $user = Auth::user();
        $request->validate([
            'uid' => 'required',
        ]);
        $result = $this->repository->removeTwoStepAuthentication($user, $request->uid, );
        return $this->success(['success' => $result]);
    }

}
