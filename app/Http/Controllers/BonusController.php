<?php

namespace App\Http\Controllers;

use App\Models\BonusLogs;
use App\Models\Message;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserMeta;
use App\Repositories\BonusRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Nexus\Database\NexusDB;
use Nexus\Database\NexusLock;

class BonusController extends Controller
{
    /**
     * Karma (bonus) centre page. Mirrors legacy public/mybonus.php so the page
     * can be served by the Laravel router instead of the procedural script.
     */
    public function web(Request $request)
    {
        [$curUser, $lang] = $this->bootstrap($request);

        $lockSeconds = 10;
        $lockText = sprintf($lang['lock_text'], $lockSeconds);

        $msg = '';
        $do = (string) $request->query('do', '');
        if ($do !== '') {
            $msg = match ($do) {
                'upload' => $lang['text_success_upload'],
                'download' => $lang['text_success_download'],
                'invite' => $lang['text_success_invites'],
                'tmp_invite' => $lang['text_success_tmp_invites'],
                'vip' => $lang['text_success_vip'] . '<b>' . get_user_class_name(\App\Models\User::CLASS_VIP, false, false, true) . '</b>' . $lang['text_success_vip_two'],
                'vipfalse' => $lang['text_no_permission'],
                'title' => sprintf($lang['text_success_custom_title'], $curUser['title']),
                'transfer' => $lang['text_success_gift'],
                'noad' => $lang['text_success_no_ad'],
                'charity' => $lang['text_success_charity'],
                'cancel_hr' => $lang['text_success_cancel_hr'],
                'buy_medal' => $lang['text_success_buy_medal'],
                'attendance_card' => $lang['text_success_buy_attendance_card'],
                'rainbow_id' => $lang['text_success_buy_rainbow_id'],
                'change_username_card' => $lang['text_success_buy_change_username_card'],
                'duplicated' => $lockText,
                default => '',
            };
        }

        $pageTitle = $curUser['username'] . $lang['head_karma_page'];

        $content = $this->buildExchangeTable($curUser, $lang, $msg, $lockText);
        $content .= $this->buildKarmaInfoSection($curUser, $lang);

        return view('mybonus', compact('pageTitle', 'content', 'lang'));
    }

    /**
     * Bonus exchange submission. Mirrors the legacy public/mybonus.php
     * `action=exchange` block.
     */
    public function webExchange(Request $request)
    {
        [$curUser, $lang] = $this->bootstrap($request);

        $allBonus = $this->bonusList($lang);
        $lockSeconds = 10;
        $lockText = sprintf($lang['lock_text'], $lockSeconds);

        $option = (int) $request->input('option');
        if (
            $request->has('userid') || $request->has('points') || $request->has('bonus') || $request->has('art')
            || ! $request->has('option') || ! isset($allBonus[$option])
        ) {
            write_log("User " . $curUser['username'] . "," . $curUser['ip'] . " is trying to cheat at bonus system", 'mod');
            abort(400, $lang['text_cheat_alert']);
        }
        $bonusarray = $allBonus[$option];
        $points = (float) $bonusarray['points'];
        $userid = $curUser['id'];
        $art = $bonusarray['art'];

        if ($curUser['seedbonus'] < $points) {
            abort(400, $lang['text_not_enough_bonus']);
        }

        $bonusRep = new BonusRepository();
        $lockName = "user:$userid:exchange:bonus";
        $lock = new NexusLock($lockName, $lockSeconds);
        if (! $lock->get()) {
            do_log("[LOCKED], $lockName, $lockText");
            return redirect('mybonus.php?do=duplicated');
        }

        $ratiolimit_bonus = (float) get_setting('bonus.ratiolimit', 0);
        $dlamountlimit_bonus = (float) get_setting('bonus.dlamountlimit', 0);
        $buyinvite_class = (int) get_setting('authority.buyinvite', 0);
        $taxpercentage_bonus = (float) get_setting('bonus.taxpercentage', 0);
        $basictax_bonus = (float) get_setting('bonus.basictax', 0);
        $enablead_advertisement = get_setting('advertisement.enablead', 'no');
        $enablenoad_advertisement = get_setting('advertisement.enablenoad', 'no');
        $enablebonusnoad_advertisement = get_setting('advertisement.enablebonusnoad', 'no');
        $bonusnoad_advertisement = (int) get_setting('advertisement.bonusnoad', 0);
        $noad_advertisement = (int) get_setting('advertisement.noad', 0);
        $bonusnoadtime_advertisement = (int) get_setting('advertisement.bonusnoadtime', 0);
        $bonusgift_bonus = get_setting('bonus.bonusgift', 'no');

        // trade for upload credit
        if ($art == 'traffic') {
            if ($curUser['uploaded'] > $dlamountlimit_bonus * 1073741824) {
                // uploaded amount reached limit
                if ($curUser['downloaded'] > 0) {
                    $ratio = $curUser['uploaded'] / $curUser['downloaded'];
                } else {
                    $ratio = PHP_INT_MAX;
                }
            } else {
                $ratio = 0;
            }
            if ($ratiolimit_bonus > 0 && $ratio > $ratiolimit_bonus) {
                abort(400, $lang['text_cheat_alert']);
            }
            $up = $curUser['uploaded'] + $bonusarray['menge'];
            do_log(sprintf(
                "user: %s going to use %s bonus to exchange uploaded from %s to %s",
                $curUser['id'], $points, $curUser['uploaded'], $up
            ));
            $bonusRep->consumeUserBonus($curUser['id'], $points, \App\Models\BonusLogs::BUSINESS_TYPE_EXCHANGE_UPLOAD, $points . " Points for uploaded.", ['uploaded' => $up]);
            return redirect('mybonus.php?do=upload');
        }

        // trade for download credit
        if ($art == 'traffic_downloaded') {
            $down = $curUser['downloaded'] + $bonusarray['menge'];
            do_log(sprintf(
                "user: %s going to use %s bonus to exchange downloaded from %s to %s",
                $curUser['id'], $points, $curUser['downloaded'], $down
            ));
            $bonusRep->consumeUserBonus($curUser['id'], $points, \App\Models\BonusLogs::BUSINESS_TYPE_EXCHANGE_DOWNLOAD, $points . " Points for downloaded.", ['downloaded' => $down]);
            return redirect('mybonus.php?do=download');
        }

        // one month VIP status
        if ($art == 'class') {
            if (get_user_class() >= \App\Models\User::CLASS_VIP) {
                abort(400, $lang['text_no_permission'] . ' ' . $lang['std_class_above_vip']);
            }
            $vip_until = date("Y-m-d H:i:s", (strtotime(date("Y-m-d H:i:s")) + 28 * 86400));
            $bonusRep->consumeUserBonus($curUser['id'], $points, \App\Models\BonusLogs::BUSINESS_TYPE_BUY_VIP, $points . " Points for 1 month VIP Status.", ['class' => \App\Models\User::CLASS_VIP, 'vip_added' => 'yes', 'vip_until' => $vip_until]);
            return redirect('mybonus.php?do=vip');
        }

        // invite
        if ($art == 'invite') {
            if (! user_can('buyinvite')) {
                abort(400, get_user_class_name($buyinvite_class, false, false, true) . $lang['text_plus_only']);
            }
            $inv = $curUser['invites'] + $bonusarray['menge'];
            $bonusRep->consumeUserBonus($curUser['id'], $points, \App\Models\BonusLogs::BUSINESS_TYPE_EXCHANGE_INVITE, $points . " Points for invites.", ['invites' => $inv]);
            return redirect('mybonus.php?do=invite');
        }

        // temporary invite
        if ($art == 'tmp_invite') {
            if (! user_can('buyinvite')) {
                abort(400, get_user_class_name($buyinvite_class, false, false, true) . $lang['text_plus_only']);
            }
            $bonusRep->consumeToBuyTemporaryInvite($curUser['id']);
            return redirect('mybonus.php?do=tmp_invite');
        }

        // custom title, with the same banned words as the legacy page
        if ($art == 'title') {
            $title = (string) $request->input('title', '');
            $words = ["fuck", "shit", "pussy", "cunt", "nigger", "Staff Leader", "SysOp", "Administrator", "Moderator", "Uploader", "Retiree", "VIP", "Nexus Master", "Ultimate User", "Extreme User", "Veteran User", "Insane User", "Crazy User", "Elite User", "Power User", "User", "Peasant", "Champion"];
            $title = str_replace($words, $lang['text_wasted_karma'], $title);
            $bonusRep->consumeUserBonus($curUser['id'], $points, \App\Models\BonusLogs::BUSINESS_TYPE_CUSTOM_TITLE, $points . " Points for custom title. Old title is " . htmlspecialchars(trim($curUser['title'])) . " and new title is $title.", ['title' => $title]);
            return redirect('mybonus.php?do=title');
        }

        // time without ads
        if ($art == 'noad' && $enablead_advertisement == 'yes' && $enablebonusnoad_advertisement == 'yes') {
            if (($enablenoad_advertisement == 'yes' && get_user_class() >= $noad_advertisement) || strtotime($curUser['noaduntil'] ?? '') >= time() || get_user_class() < $bonusnoad_advertisement) {
                abort(400, $lang['text_cheat_alert']);
            }
            $noaduntil = date("Y-m-d H:i:s", (time() + $bonusarray['menge']));
            $bonusRep->consumeUserBonus($curUser['id'], $points, \App\Models\BonusLogs::BUSINESS_TYPE_NO_AD, $points . " Points for " . $bonusnoadtime_advertisement . " days without ads.", ['noad' => 'yes', 'noaduntil' => $noaduntil]);
            return redirect('mybonus.php?do=noad');
        }

        // charity giving to users with a low share ratio
        if ($art == 'gift_2') {
            $points = (float) $request->input('bonuscharity', 0);
            if ($points < 1000 || $points > 50000) {
                abort(400, $lang['bonus_amount_not_allowed_two']);
            }
            $ratiocharity = (float) $request->input('ratiocharity', 0);
            if ($ratiocharity < 0.1 || $ratiocharity > 0.8) {
                abort(400, $lang['bonus_ratio_not_allowed']);
            }
            if ($curUser['seedbonus'] >= $points) {
                $points2 = number_format($points, 1);
                $receiverQuery = User::query()
                    ->where('enabled', 'yes')
                    ->where('downloaded', '>', 10737418240)
                    ->whereRaw("$ratiocharity > uploaded/downloaded");
                $charityReceiverCount = (clone $receiverQuery)->count();
                if ($charityReceiverCount) {
                    $bonusRep->consumeUserBonus($curUser['id'], $points, \App\Models\BonusLogs::BUSINESS_TYPE_GIFT_TO_LOW_SHARE_RATIO, $points . " Points as charity to users with ratio below " . htmlspecialchars(trim(strval($ratiocharity))) . ".", ['charity' => NexusDB::raw("charity + $points")]);
                    $charityPerUser = $points / $charityReceiverCount;
                    (clone $receiverQuery)->increment('seedbonus', (float) $charityPerUser);
                    return redirect('mybonus.php?do=charity');
                }
                abort(400, $lang['std_no_users_need_charity']);
            }
            abort(400, $lang['text_not_enough_bonus']);
        }

        // give a karma gift to another user
        if ($art == 'gift_1' && $bonusgift_bonus == 'yes') {
            $points = (float) $request->input('bonusgift', 0);
            $message = (string) $request->input('message', '');
            $username = trim((string) $request->input('username', ''));
            $receiver = User::query()->where('username', $username)->first(['id', 'seedbonus']);
            if (empty($receiver)) {
                abort(400, $lang['text_receiver_not_exists']);
            }
            $useridgift = $receiver->id;
            $userseedbonus = $receiver->seedbonus;
            if (! is_numeric($request->input('bonusgift')) || $points < $bonusarray['points']) {
                abort(400, $lang['bonus_amount_not_allowed']);
            }
            if ($curUser['seedbonus'] < $points) {
                abort(400, $lang['text_not_enough_karma']);
            }
            $points2 = number_format($points, 1);
            $aftertaxpoint = $points;
            if ($taxpercentage_bonus) {
                $aftertaxpoint -= $aftertaxpoint * $taxpercentage_bonus * 0.01;
            }
            if ($basictax_bonus) {
                $aftertaxpoint -= $basictax_bonus;
            }
            $points2receiver = number_format($aftertaxpoint, 1);
            if ($userid == $useridgift) {
                abort(400, $lang['text_karma_self_giving_warning']);
            }

            $bonusRep->consumeUserBonus($curUser['id'], $points, \App\Models\BonusLogs::BUSINESS_TYPE_GIFT_TO_SOMEONE, $points2 . " Points as gift to " . htmlspecialchars(trim($username)));
            User::query()->where('id', $useridgift)->increment('seedbonus', (float) $aftertaxpoint);
            \App\Models\BonusLogs::add($useridgift, $userseedbonus, $aftertaxpoint, $userseedbonus + $aftertaxpoint, " + " . $points2receiver . " Points (after tax) as a gift from " . $curUser['username'], \App\Models\BonusLogs::BUSINESS_TYPE_RECEIVE_GIFT);

            // send the receiver a message
            $locale = get_user_locale($useridgift);
            $subject = nexus_trans("bonus.msg_someone_loves_you", [], $locale);
            $added = date("Y-m-d H:i:s");
            $msg = nexus_trans("bonus.msg_you_have_been_given", [], $locale) . $points2 . nexus_trans("bonus.msg_after_tax", [], $locale) . $points2receiver . nexus_trans("bonus.msg_karma_points_by", [], $locale) . $curUser['username'];
            if ($message) {
                $msg .= "\n" . nexus_trans("bonus.msg_personal_message_from", [], $locale) . $curUser['username'] . nexus_trans("bonus.msg_colon", [], $locale) . $message;
            }
            \App\Models\Message::add([
                'sender' => 0,
                'subject' => $subject,
                'added' => now(),
                'msg' => $msg,
                'receiver' => $useridgift,
            ]);
            return redirect('mybonus.php?do=transfer');
        }

        // cancel one hit-and-run
        if ($art == 'cancel_hr') {
            $hrId = $request->input('hr_id');
            if (empty($hrId)) {
                abort(400, "Invalid H&R ID: " . ($hrId ?? ''));
            }
            try {
                $bonusRep->consumeToCancelHitAndRun($userid, $hrId);
            } catch (\Throwable $exception) {
                abort(400, $exception->getMessage());
            }
            return redirect('mybonus.php?do=cancel_hr');
        }

        // attendance card / rainbow id / change username card
        if ($art == 'attendance_card') {
            try {
                $bonusRep->consumeToBuyAttendanceCard($userid);
            } catch (\Throwable $exception) {
                abort(400, $exception->getMessage());
            }
            return redirect('mybonus.php?do=attendance_card');
        }
        if ($art == 'rainbow_id') {
            try {
                $bonusRep->consumeToBuyRainbowId($userid);
            } catch (\Throwable $exception) {
                abort(400, $exception->getMessage());
            }
            return redirect('mybonus.php?do=rainbow_id');
        }
        if ($art == 'change_username_card') {
            try {
                $bonusRep->consumeToBuyChangeUsernameCard($userid);
            } catch (\Throwable $exception) {
                abort(400, $exception->getMessage());
            }
            return redirect('mybonus.php?do=change_username_card');
        }

        abort(400, $lang['text_cheat_alert']);
    }

    /**
     * Authenticate the request, set the legacy globals used by the shared
     * helpers, and return the current user array + mybonus lang file.
     */
    private function bootstrap(Request $request): array
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        if (! $currentUser) {
            abort(401);
        }
        if ($currentUser->parked == 'yes') {
            abort(403, 'Your account is parked.');
        }
        $curUser = $currentUser->toArray();

        $lang = get_legacy_lang_file('mybonus');
        $langFunctions = get_legacy_lang_file('functions');

        // globals the shared legacy helpers expect (mirrors public/mybonus.php bootstrap)
        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_mybonus'] = $lang;
        $GLOBALS['lang_functions'] = $langFunctions;
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        // legacy user-class constants are defined in include/core.php (not loaded
        // in the Laravel bootstrap); mybonus.php compares against UC_VIP etc.
        foreach ([
            'UC_PEASANT' => 0, 'UC_USER' => 1, 'UC_POWER_USER' => 2, 'UC_ELITE_USER' => 3,
            'UC_CRAZY_USER' => 4, 'UC_INSANE_USER' => 5, 'UC_VETERAN_USER' => 6,
            'UC_EXTREME_USER' => 7, 'UC_ULTIMATE_USER' => 8, 'UC_NEXUS_MASTER' => 9,
            'UC_VIP' => 10, 'UC_RETIREE' => 11, 'UC_UPLOADER' => 12, 'UC_MODERATOR' => 13,
            'UC_ADMINISTRATOR' => 14, 'UC_SYSOP' => 15, 'UC_STAFFLEADER' => 16,
        ] as $constant => $value) {
            defined($constant) || define($constant, $value);
        }

        $bonus_tweak = \App\Models\Setting::getByName('tweak.bonus', 'enable');
        if ($bonus_tweak == 'disable' || $bonus_tweak == 'disablesave') {
            abort(403, $lang['std_sorry'] . $lang['std_karma_system_disabled'] . ($bonus_tweak == 'disablesave' ? "<b>" . $lang['std_points_active'] . "</b>" : ""));
        }

        return [$curUser, $lang];
    }

    /**
     * The list of exchangeable bonus options. Mirrors legacy bonusarray().
     */
    private function bonusList(array $lang): array
    {
        $results = [];

        // 1.0 GB Uploaded
        $results[] = [
            'points' => (float) get_setting('bonus.onegbupload', 0),
            'art' => 'traffic',
            'menge' => 1073741824,
            'name' => $lang['text_uploaded_one'],
            'description' => $lang['text_uploaded_note'],
        ];
        // 5.0 GB Uploaded
        $results[] = [
            'points' => (float) get_setting('bonus.fivegbupload', 0),
            'art' => 'traffic',
            'menge' => 5368709120,
            'name' => $lang['text_uploaded_two'],
            'description' => $lang['text_uploaded_note'],
        ];
        // 10.0 GB Uploaded
        $results[] = [
            'points' => (float) get_setting('bonus.tengbupload', 0),
            'art' => 'traffic',
            'menge' => 10737418240,
            'name' => $lang['text_uploaded_three'],
            'description' => $lang['text_uploaded_note'],
        ];
        // 100.0 GB Uploaded
        $results[] = [
            'points' => (float) get_setting('bonus.hundredgbupload', 0),
            'art' => 'traffic',
            'menge' => 107374182400,
            'name' => $lang['text_uploaded_four'],
            'description' => $lang['text_uploaded_note'],
        ];
        // 10.0 GB Downloaded
        $results[] = [
            'points' => (float) get_setting('bonus.tengbdownload', 0),
            'art' => 'traffic_downloaded',
            'menge' => 10737418240,
            'name' => $lang['text_downloaded_ten_gb'],
            'description' => $lang['text_download_note'],
        ];
        // 100.0 GB Downloaded
        $results[] = [
            'points' => (float) get_setting('bonus.hundredgbdownload', 0),
            'art' => 'traffic_downloaded',
            'menge' => 107374182400,
            'name' => $lang['text_downloaded_hundred_gb'],
            'description' => $lang['text_download_note'],
        ];
        // Invite
        if ((float) get_setting('bonus.oneinvite', 0) > 0) {
            $results[] = [
                'points' => (float) get_setting('bonus.oneinvite', 0),
                'art' => 'invite',
                'menge' => 1,
                'name' => $lang['text_buy_invite'],
                'description' => $lang['text_buy_invite_note'],
            ];
        }
        // Temporary invite
        $tmpInviteBonus = \App\Models\BonusLogs::getBonusForBuyTemporaryInvite();
        if ($tmpInviteBonus > 0) {
            $results[] = [
                'points' => (float) $tmpInviteBonus,
                'art' => 'tmp_invite',
                'menge' => 1,
                'name' => $lang['text_buy_tmp_invite'],
                'description' => $lang['text_buy_tmp_invite_note'],
            ];
        }
        // Custom title
        $results[] = [
            'points' => (float) get_setting('bonus.customtitle', 0),
            'art' => 'title',
            'menge' => 0,
            'name' => $lang['text_custom_title'],
            'description' => $lang['text_custom_title_note'],
        ];
        // VIP status
        $results[] = [
            'points' => (float) get_setting('bonus.vipstatus', 0),
            'art' => 'class',
            'menge' => 0,
            'name' => $lang['text_vip_status'],
            'description' => $lang['text_vip_status_note'],
        ];
        // Karma gift
        $basictax_bonus = (float) get_setting('bonus.basictax', 0);
        $taxpercentage_bonus = (float) get_setting('bonus.taxpercentage', 0);
        $bonus = [
            'points' => 100,
            'art' => 'gift_1',
            'menge' => 0,
            'name' => $lang['text_bonus_gift'],
            'description' => $lang['text_bonus_gift_note'],
        ];
        if ($basictax_bonus || $taxpercentage_bonus) {
            $onehundredaftertax = 100 - $taxpercentage_bonus - $basictax_bonus;
            $bonus['description'] .= "<br /><br />" . $lang['text_system_charges_receiver'] . "<b>" . ($basictax_bonus ? $basictax_bonus . $lang['text_tax_bonus_point'] . add_s($basictax_bonus) . ($taxpercentage_bonus ? $lang['text_tax_plus'] : "") : "") . ($taxpercentage_bonus ? $taxpercentage_bonus . $lang['text_percent_of_transfered_amount'] : "") . "</b>" . $lang['text_as_tax'] . $onehundredaftertax . $lang['text_tax_example_note'];
        }
        $results[] = $bonus;
        // No ads for a period
        $bonusnoadpoint_advertisement = (float) get_setting('advertisement.bonusnoadpoint', 0);
        $bonusnoadtime_advertisement = (int) get_setting('advertisement.bonusnoadtime', 0);
        $results[] = [
            'points' => $bonusnoadpoint_advertisement,
            'art' => 'noad',
            'menge' => $bonusnoadtime_advertisement * 86400,
            'name' => (string) $bonusnoadtime_advertisement . $lang['text_no_advertisements'],
            'description' => $lang['text_no_advertisements_note'],
        ];
        // Attendance card
        $results[] = [
            'points' => (float) \App\Models\BonusLogs::getBonusForBuyAttendanceCard(),
            'art' => 'attendance_card',
            'menge' => 0,
            'name' => $lang['text_attendance_card'],
            'description' => $lang['text_attendance_card_note'],
        ];
        // Rainbow ID
        $results[] = [
            'points' => (float) \App\Models\BonusLogs::getBonusForBuyRainbowId(),
            'art' => 'rainbow_id',
            'menge' => 0,
            'name' => $lang['text_buy_rainbow_id'],
            'description' => $lang['text_buy_rainbow_id_note'],
        ];
        // Change username card
        $results[] = [
            'points' => (float) \App\Models\BonusLogs::getBonusForBuyChangeUsernameCard(),
            'art' => 'change_username_card',
            'menge' => 0,
            'name' => $lang['text_buy_change_username_card'],
            'description' => $lang['text_buy_change_username_card_note'],
        ];
        // Charity giving
        $results[] = [
            'points' => 1000,
            'art' => 'gift_2',
            'menge' => 0,
            'name' => $lang['text_charity_giving'],
            'description' => $lang['text_charity_giving_note'],
        ];
        // Cancel hit and run
        $results[] = [
            'points' => (float) \App\Models\BonusLogs::getBonusForCancelHitAndRun(),
            'art' => 'cancel_hr',
            'menge' => 0,
            'name' => $lang['text_cancel_hr_title'],
            'description' => '<p>
            <span style="">' . $lang['text_cancel_hr_label'] . '</span>
            <input type="number" name="hr_id" />
        </p>',
        ];

        return $results;
    }

    /**
     * Render the "exchange your karma" table. Mirrors legacy bonusarray display.
     */
    private function buildExchangeTable(array $curUser, array $lang, string $msg, string $lockText): string
    {
        $allBonus = $this->bonusList($lang);

        $bonusgift_bonus = get_setting('bonus.bonusgift', 'no');
        $enablead_advertisement = get_setting('advertisement.enablead', 'no');
        $enablenoad_advertisement = get_setting('advertisement.enablenoad', 'no');
        $bonusnoad_advertisement = (int) get_setting('advertisement.bonusnoad', 0);
        $noad_advertisement = (int) get_setting('advertisement.noad', 0);
        $ratiolimit_bonus = (float) get_setting('bonus.ratiolimit', 0);
        $dlamountlimit_bonus = (float) get_setting('bonus.dlamountlimit', 0);

        $bonus = number_format((float) $curUser['seedbonus'], 1);

        $content = "<table align=\"center\" width=\"97%\" border=\"1\" cellspacing=\"0\" cellpadding=\"3\">\n";
        $content .= "<tr><td class=\"colhead\" colspan=\"4\" align=\"center\"><font class=\"big\">" . $GLOBALS['SITENAME'] . $lang['text_karma_system'] . "</font></td></tr>\n";
        if ($msg) {
            $content .= "<tr><td align=\"center\" colspan=\"4\"><font class=\"striking\"><b>" . $msg . "</b></font></td></tr>";
        }
        $content .= "<tr><td class=\"text\" align=\"center\" colspan=\"4\">" . $lang['text_exchange_your_karma'] . $bonus . $lang['text_for_goodies'];
        $content .= "<br /><b>" . $lang['text_no_buttons_note'] . "</b><br /><small style=\"color: orangered\">(" . $lockText . ")</small></td></tr>";

        $content .= "<tr><td class=\"colhead\" align=\"center\">" . $lang['col_option'] . "</td>"
            . "<td class=\"colhead\" align=\"left\">" . $lang['col_description'] . "</td>"
            . "<td class=\"colhead\" align=\"center\">" . $lang['col_points'] . "</td>"
            . "<td class=\"colhead\" align=\"center\">" . $lang['col_trade'] . "</td>"
            . "</tr>";

        foreach ($allBonus as $i => $bonusarray) {
            if (
                ($bonusarray['art'] == 'gift_1' && $bonusgift_bonus == 'no')
                || ($bonusarray['art'] == 'noad' && ($enablead_advertisement == 'no' || get_setting('advertisement.enablebonusnoad', 'no') == 'no'))
                || ($bonusarray['art'] == 'cancel_hr' && ! \App\Models\HitAndRun::getIsEnabled())
            ) {
                continue;
            }
            $bonusarray['points'] = (float) $bonusarray['points'];

            $content .= "<tr>";
            $content .= "<form action=\"?action=exchange\" method=\"post\">";
            $content .= "<td class=\"rowhead_center\"><input type=\"hidden\" name=\"option\" value=\"" . $i . "\" /><b>" . ($i + 1) . "</b></td>";
            if ($bonusarray['art'] == 'title') {
                $otheroption_title = "<input type=\"text\" name=\"title\" style=\"width: 200px\" maxlength=\"30\" />";
                $content .= "<td class=\"rowfollow\" align='left'><h1>" . $bonusarray['name'] . "</h1>" . $bonusarray['description'] . "<br /><br />" . $lang['text_enter_titile'] . $otheroption_title . $lang['text_click_exchange'] . "</td><td class=\"rowfollow\" align='center'>" . number_format($bonusarray['points']) . "</td>";
            } elseif ($bonusarray['art'] == 'gift_1') {
                $otheroption = "<table width=\"100%\"><tr><td class=\"embedded\"><b>" . $lang['text_username'] . "</b><input type=\"text\" name=\"username\" style=\"width: 200px\" maxlength=\"24\" /></td><td class=\"embedded\"><b>" . $lang['text_to_be_given'] . "</b><input type=\"number\" name=\"bonusgift\" id=\"giftcustom\" style='width: 80px' min='100' />" . $lang['text_karma_points'] . "</td></tr><tr><td class=\"embedded\" colspan=\"2\"><b>" . $lang['text_message'] . "</b><input type=\"text\" name=\"message\" style=\"width: 400px\" maxlength=\"100\" /></td></tr></table>";
                $content .= "<td class=\"rowfollow\" align='left'><h1>" . $bonusarray['name'] . "</h1>" . $bonusarray['description'] . "<br /><br />" . $lang['text_enter_receiver_name'] . "<br />$otheroption</td><td class=\"rowfollow nowrap\" align='center'>" . $lang['text_min'] . "100</td>";
            } elseif ($bonusarray['art'] == 'gift_2') {
                $otheroption = "<table width=\"100%\"><tr><td class=\"embedded\">" . $lang['text_ratio_below'] . "<select name=\"ratiocharity\"> <option value=\"0.1\"> 0.1</option><option value=\"0.2\"> 0.2</option><option value=\"0.3\" selected=\"selected\"> 0.3</option> <option value=\"0.4\"> 0.4</option> <option value=\"0.5\"> 0.5</option> <option value=\"0.6\"> 0.6</option><option value=\"0.7\"> 0.7</option><option value=\"0.8\"> 0.8</option></select>" . $lang['text_and_downloaded_above'] . " 10 GB</td><td class=\"embedded\"><b>" . $lang['text_to_be_given'] . "</b><select name=\"bonuscharity\" id=\"charityselect\" > <option value=\"1000\"> 1,000</option><option value=\"2000\"> 2,000</option><option value=\"3000\" selected=\"selected\"> 3000</option> <option value=\"5000\"> 5,000</option> <option value=\"8000\"> 8,000</option> <option value=\"10000\"> 10,000</option><option value=\"20000\"> 20,000</option><option value=\"50000\"> 50,000</option></select>" . $lang['text_karma_points'] . "</td></tr></table>";
                $content .= "<td class=\"rowfollow\" align='left'><h1>" . $bonusarray['name'] . "</h1>" . $bonusarray['description'] . "<br /><br />" . $lang['text_select_receiver_ratio'] . "<br />$otheroption</td><td class=\"rowfollow nowrap\" align='center'>" . $lang['text_min'] . "1,000<br />" . $lang['text_max'] . "50,000</td>";
            } else {
                $content .= "<td class=\"rowfollow\" align='left'><h1>" . $bonusarray['name'] . "</h1>" . $bonusarray['description'] . "</td><td class=\"rowfollow\" align='center'>" . number_format($bonusarray['points']) . "</td>";
            }

            if ($curUser['seedbonus'] >= $bonusarray['points']) {
                $permission = 'sendinvite';
                if ($bonusarray['art'] == 'gift_1') {
                    $content .= "<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"" . $lang['submit_karma_gift'] . "\" /></td>";
                } elseif ($bonusarray['art'] == 'noad') {
                    if ($enablenoad_advertisement == 'yes' && get_user_class() >= $noad_advertisement) {
                        $content .= "<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"" . $lang['submit_class_above_no_ad'] . "\" disabled=\"disabled\" /></td>";
                    } elseif (! empty($curUser['noaduntil']) && strtotime($curUser['noaduntil']) >= time()) {
                        $content .= "<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"" . $lang['submit_already_disabled'] . "\" disabled=\"disabled\" /></td>";
                    } elseif (get_user_class() < $bonusnoad_advertisement) {
                        $content .= "<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"" . get_user_class_name($bonusnoad_advertisement, false, false, true) . $lang['text_plus_only'] . "\" disabled=\"disabled\" /></td>";
                    } else {
                        $content .= "<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"" . $lang['submit_exchange'] . "\" /></td>";
                    }
                } elseif ($bonusarray['art'] == 'gift_2') {
                    $content .= "<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"" . $lang['submit_charity_giving'] . "\" /></td>";
                } elseif ($bonusarray['art'] == 'invite' || $bonusarray['art'] == 'tmp_invite') {
                    if (\App\Models\Setting::get('main.invitesystem') != 'yes') {
                        $content .= "<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"" . nexus_trans('invite.send_deny_reasons.invite_system_closed') . "\" disabled=\"disabled\" /></td>";
                    } elseif (! user_can($permission, false, 0)) {
                        $requireClass = get_setting("authority.$permission");
                        $content .= "<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"" . nexus_trans('invite.send_deny_reasons.no_permission', ['class' => \App\Models\User::getClassText($requireClass)]) . "\" disabled=\"disabled\" /></td>";
                    } else {
                        $content .= "<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"" . $lang['submit_exchange'] . "\" /></td>";
                    }
                } elseif ($bonusarray['art'] == 'class') {
                    if (get_user_class() >= \App\Models\User::CLASS_VIP) {
                        $content .= "<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"" . $lang['std_class_above_vip'] . "\" disabled=\"disabled\" /></td>";
                    } else {
                        $content .= "<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"" . $lang['submit_exchange'] . "\" /></td>";
                    }
                } elseif ($bonusarray['art'] == 'title') {
                    $content .= "<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"" . $lang['submit_exchange'] . "\" /></td>";
                } elseif ($bonusarray['art'] == 'traffic') {
                    if ($curUser['downloaded'] > 0) {
                        if ($curUser['uploaded'] > $dlamountlimit_bonus * 1073741824) {
                            $ratio = $curUser['uploaded'] / $curUser['downloaded'];
                        } else {
                            $ratio = 0;
                        }
                    } else {
                        $ratio = $ratiolimit_bonus + 1;
                    }
                    if ($ratiolimit_bonus > 0 && $ratio > $ratiolimit_bonus) {
                        $content .= "<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"" . $lang['text_ratio_too_high'] . "\" disabled=\"disabled\" /></td>";
                    } else {
                        $content .= "<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"" . $lang['submit_exchange'] . "\" /></td>";
                    }
                } elseif ($bonusarray['art'] == 'change_username_card') {
                    if (\App\Models\UserMeta::query()->where('uid', $curUser['id'])->where('meta_key', \App\Models\UserMeta::META_KEY_CHANGE_USERNAME)->exists()) {
                        $content .= "<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"" . $lang['text_change_username_card_already_has'] . "\" disabled=\"disabled\"/></td>";
                    } else {
                        $content .= "<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"" . $lang['submit_exchange'] . "\" /></td>";
                    }
                } elseif ($bonusarray['art'] == 'rainbow_id') {
                    if (\App\Models\UserMeta::query()->where('uid', $curUser['id'])->where('meta_key', \App\Models\UserMeta::META_KEY_PERSONALIZED_USERNAME)->whereNull('deadline')->exists()) {
                        $content .= "<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"" . $lang['text_rainbow_id_already_valid_forever'] . "\" disabled=\"disabled\"/></td>";
                    } else {
                        $content .= "<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"" . $lang['submit_exchange'] . "\" /></td>";
                    }
                } else {
                    $content .= "<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"" . $lang['submit_exchange'] . "\" /></td>";
                }
            } else {
                $content .= "<td class=\"rowfollow\" align=\"center\"><input type=\"submit\" name=\"submit\" value=\"" . $lang['text_more_points_needed'] . "\" disabled=\"disabled\" /></td>";
            }
            $content .= "</form>";
            $content .= "</tr>";
        }

        $content .= "</table><br />";

        return $content;
    }

    /**
     * Render the "what is karma" explanation block. Mirrors the legacy second
     * table of public/mybonus.php.
     */
    private function buildKarmaInfoSection(array $curUser, array $lang): string
    {
        $perseeding_bonus = (float) get_setting('bonus.perseeding', 0);
        $maxseeding_bonus = (float) get_setting('bonus.maxseeding', 0);
        $tzero_bonus = (float) get_setting('bonus.tzero', 0);
        $nzero_bonus = (float) get_setting('bonus.nzero', 0);
        $bzero_bonus = (float) get_setting('bonus.bzero', 0);
        $l_bonus = (float) get_setting('bonus.l', 0);
        $donortimes_bonus = (float) get_setting('bonus.donortimes', 0);
        $uploadtorrent_bonus = (float) get_setting('bonus.uploadtorrent', 0);
        $uploadsubtitle_bonus = (float) get_setting('bonus.uploadsubtitle', 0);
        $starttopic_bonus = (float) get_setting('bonus.starttopic', 0);
        $makepost_bonus = (float) get_setting('bonus.makepost', 0);
        $addcomment_bonus = (float) get_setting('bonus.addcomment', 0);
        $pollvote_bonus = (float) get_setting('bonus.pollvote', 0);
        $offervote_bonus = (float) get_setting('bonus.offervote', 0);
        $funboxvote_bonus = (float) get_setting('bonus.funboxvote', 0);
        $saythanks_bonus = (float) get_setting('bonus.saythanks', 0);
        $receivethanks_bonus = (float) get_setting('bonus.receivethanks', 0);
        $funboxreward_bonus = (float) get_setting('bonus.funboxreward', 0);
        $adclickbonus_advertisement = (float) get_setting('advertisement.adclickbonus', 0);
        $prolinkpoint_bonus = (float) get_setting('bonus.prolinkpoint', 0);
        $ratiolimit_bonus = (float) get_setting('bonus.ratiolimit', 0);
        $dlamountlimit_bonus = (float) get_setting('bonus.dlamountlimit', 0);

        $content = "<table width=\"97%\" cellpadding=\"3\">";
        $content .= "<tr><td class=\"colhead\" align=\"center\"><font class=\"big\">" . $lang['text_what_is_karma'] . "</font></td></tr>";
        $content .= "<tr><td class=\"text\" align=\"left\">";

        $content .= "<h1>" . $lang['text_get_by_seeding'] . "</h1><ul>";
        if ($perseeding_bonus > 0) {
            $content .= "<li>" . $perseeding_bonus . $lang['text_point'] . add_s($perseeding_bonus) . $lang['text_for_seeding_torrent'] . $maxseeding_bonus . $lang['text_torrent'] . add_s($maxseeding_bonus) . ")</li>";
        }
        $content .= "<li>" . $lang['text_bonus_formula_one'] . $tzero_bonus . $lang['text_bonus_formula_two'] . $nzero_bonus . $lang['text_bonus_formula_wi'] . get_setting('bonus.zero_bonus_factor') . $lang['text_bonus_formula_three'] . $bzero_bonus . $lang['text_bonus_formula_four'] . $l_bonus . $lang['text_bonus_formula_five'] . "</li>";
        $minSize = get_setting('bonus.min_size');
        if ($minSize > 0) {
            $content .= "<li>" . sprintf($lang['text_bonus_mini_size'], mksize($minSize)) . "</li>";
        }
        if ($donortimes_bonus) {
            $content .= "<li>" . $lang['text_donors_always_get'] . $donortimes_bonus . $lang['text_times_of_bonus'] . "</li>";
        }
        $content .= "</ul>";

        $seedBonusResult = calculate_seed_bonus($curUser['id']);
        $A = $seedBonusResult['A'];
        $bonusTableResult = build_bonus_table($curUser, $seedBonusResult, ['table_style' => 'width: 50%']);

        $percent = $seedBonusResult['seed_bonus'] * 100 / ($bzero_bonus + $perseeding_bonus * $maxseeding_bonus);
        $content .= "<div align=\"center\">" . $lang['text_you_are_currently_getting'] . round($seedBonusResult['seed_bonus'], 3) . $lang['text_point'] . add_s($seedBonusResult['seed_bonus']) . $lang['text_per_hour'] . " (A = " . round($A, 1) . ")</div><table align=\"center\" border=\"0\" width=\"400\"><tr><td class=\"loadbarbg\" style='border: none; padding: 0px;'>";
        if ($percent <= 30) {
            $loadpic = "loadbarred";
        } elseif ($percent <= 60) {
            $loadpic = "loadbaryellow";
        } else {
            $loadpic = "loadbargreen";
        }
        $width = $percent * 4;
        $content .= "<img class=\"" . $loadpic . "\" src=\"pic/trans.gif\" style=\"width: " . $width . "px;\" alt=\"" . $percent . "%\" /></td></tr></table>";

        if ($bonusTableResult['has_medal_addition']) {
            $content .= "<h1>" . $lang['text_get_by_medal'] . "</h1><ul>";
            $content .= "<li>" . sprintf($lang['medal_additional_desc'], $curUser['id']) . "</li>";
            $content .= "<li>" . $lang['medal_additional_factor'] . $bonusTableResult['medal_addition_factor'] . "</li></ul>";
        }
        if ($bonusTableResult['has_official_addition']) {
            $content .= "<h1>" . $lang['text_get_by_seeding_official'] . "</h1><ul>";
            $content .= "<li>" . $lang['official_calculate_method'] . "</li>";
            $content .= "<li>" . $lang['official_tag_bonus_additional_factor'] . $bonusTableResult['official_addition_factor'] . "</li></ul>";
        }
        if ($bonusTableResult['has_harem_addition']) {
            $content .= "<h1>" . $lang['text_get_by_harem'] . "</h1><ul>";
            $content .= "<li>" . sprintf($lang['harem_additional_desc'], $curUser['id']) . "</li>";
            $content .= "<li>" . $lang['harem_additional_factor'] . $bonusTableResult['harem_addition_factor'] . "</li>";
            $content .= "<li>" . $lang['harem_additional_note'] . "</li></ul>";
        }

        $content .= "<h1>" . $lang['text_bonus_summary'] . "</h1>";
        $content .= '<div style="display: flex;justify-content: center;margin-top: 20px;">' . $bonusTableResult['table'] . '</div>';

        $content .= "<h1>" . $lang['text_other_things_get_bonus'] . "</h1><ul>";
        if ($uploadtorrent_bonus > 0) {
            $content .= "<li>" . $lang['text_upload_torrent'] . $uploadtorrent_bonus . $lang['text_point'] . add_s($uploadtorrent_bonus) . "</li>";
        }
        if ($uploadsubtitle_bonus > 0) {
            $content .= "<li>" . $lang['text_upload_subtitle'] . $uploadsubtitle_bonus . $lang['text_point'] . add_s($uploadsubtitle_bonus) . "</li>";
        }
        if ($starttopic_bonus > 0) {
            $content .= "<li>" . $lang['text_start_topic'] . $starttopic_bonus . $lang['text_point'] . add_s($starttopic_bonus) . "</li>";
        }
        if ($makepost_bonus > 0) {
            $content .= "<li>" . $lang['text_make_post'] . $makepost_bonus . $lang['text_point'] . add_s($makepost_bonus) . "</li>";
        }
        if ($addcomment_bonus > 0) {
            $content .= "<li>" . $lang['text_add_comment'] . $addcomment_bonus . $lang['text_point'] . add_s($addcomment_bonus) . "</li>";
        }
        if ($pollvote_bonus > 0) {
            $content .= "<li>" . $lang['text_poll_vote'] . $pollvote_bonus . $lang['text_point'] . add_s($pollvote_bonus) . "</li>";
        }
        if ($offervote_bonus > 0) {
            $content .= "<li>" . $lang['text_offer_vote'] . $offervote_bonus . $lang['text_point'] . add_s($offervote_bonus) . "</li>";
        }
        if ($funboxvote_bonus > 0) {
            $content .= "<li>" . $lang['text_funbox_vote'] . $funboxvote_bonus . $lang['text_point'] . add_s($funboxvote_bonus) . "</li>";
        }
        if ($saythanks_bonus > 0) {
            $content .= "<li>" . $lang['text_say_thanks'] . $saythanks_bonus . $lang['text_point'] . add_s($saythanks_bonus) . "</li>";
        }
        if ($receivethanks_bonus > 0) {
            $content .= "<li>" . $lang['text_receive_thanks'] . $receivethanks_bonus . $lang['text_point'] . add_s($receivethanks_bonus) . "</li>";
        }
        if ($adclickbonus_advertisement > 0) {
            $content .= "<li>" . $lang['text_click_on_ad'] . $adclickbonus_advertisement . $lang['text_point'] . add_s($adclickbonus_advertisement) . "</li>";
        }
        if ($prolinkpoint_bonus > 0) {
            $content .= "<li>" . $lang['text_promotion_link_clicked'] . $prolinkpoint_bonus . $lang['text_point'] . add_s($prolinkpoint_bonus) . "</li>";
        }
        if ($funboxreward_bonus > 0) {
            $content .= "<li>" . $lang['text_funbox_reward'] . "</li>";
        }
        $content .= $lang['text_howto_get_karma_four'];
        if ($ratiolimit_bonus > 0) {
            $content .= "<li>" . $lang['text_user_with_ratio_above'] . $ratiolimit_bonus . $lang['text_and_uploaded_amount_above'] . $dlamountlimit_bonus . $lang['text_cannot_exchange_uploading'] . "</li>";
        }
        $content .= $lang['text_howto_get_karma_five'] . $uploadtorrent_bonus . $lang['text_point'] . add_s($uploadtorrent_bonus) . $lang['text_howto_get_karma_six'];

        $content .= "</td></tr></table>";

        return $content;
    }
}