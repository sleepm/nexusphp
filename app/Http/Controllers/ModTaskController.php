<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\User;
use App\Models\UserBanLog;
use App\Models\UserModifyLog;
use App\Models\UsernameChangeLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ModTaskController extends Controller
{
    /**
     * Web entry point. Mirrors the legacy public/modtask.php action dispatch
     * (confirmuser / edituser) so the moderator actions are served by the router.
     */
    public function web(Request $request)
    {
        [$curUser] = $this->bootstrap($request);

        if (!user_can('prfmanage')) {
            return $this->puke($curUser);
        }

        $action = (string) $request->input('action', '');

        if ($action === 'confirmuser') {
            return $this->confirmUser($request, $curUser);
        }

        if ($action === 'edituser') {
            return $this->editUser($request, $curUser);
        }

        return $this->puke($curUser);
    }

    /**
     * Authenticate, set the legacy globals/constants used by shared helpers and
     * return the current user array (mirrors public/modtask.php bootstrap).
     */
    private function bootstrap(Request $request): array
    {
        /** @var User $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        if (!$currentUser) {
            abort(401);
        }
        if (($currentUser->parked ?? '') == 'yes') {
            abort(403, 'Your account is parked.');
        }
        $curUser = $currentUser->toArray();

        // legacy user-class constants are defined in include/core.php (not loaded
        // in the Laravel bootstrap); modtask.php compares against UC_* values
        foreach ([
            'UC_PEASANT' => 0, 'UC_USER' => 1, 'UC_POWER_USER' => 2, 'UC_ELITE_USER' => 3,
            'UC_CRAZY_USER' => 4, 'UC_INSANE_USER' => 5, 'UC_VETERAN_USER' => 6,
            'UC_EXTREME_USER' => 7, 'UC_ULTIMATE_USER' => 8, 'UC_NEXUS_MASTER' => 9,
            'UC_VIP' => 10, 'UC_RETIREE' => 11, 'UC_UPLOADER' => 12, 'UC_MODERATOR' => 13,
            'UC_ADMINISTRATOR' => 14, 'UC_SYSOP' => 15, 'UC_STAFFLEADER' => 16,
        ] as $constant => $value) {
            defined($constant) || define($constant, $value);
        }

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');

        return [$curUser];
    }

    /**
     * Confirm or un-confirm a pending account (legacy action=confirmuser,
     * posted from unco.php).
     */
    private function confirmUser(Request $request, array $curUser)
    {
        $userid = $request->input('userid');
        $confirm = (string) $request->input('confirm', '');

        if (!is_valid_id($userid) || !in_array($confirm, ['pending', 'confirmed'], true)) {
            return $this->puke($curUser);
        }

        User::query()->whereKey((int) $userid)->update([
            'status' => $confirm,
            'info' => null,
        ]);

        return redirect('unco.php?status=1');
    }

    /**
     * Apply the user-profile edits posted by userdetails.php (legacy
     * action=edituser).
     */
    private function editUser(Request $request, array $curUser)
    {
        $userid = (int) $request->input('userid', 0);
        $userInfo = User::query()->findOrFail($userid);

        // class/VIP handling has been migrated to the admin panel; keep the
        // legacy values untouched
        $class = $userInfo->class;
        $locale = get_user_locale($userid);
        $vip_added = $userInfo->vip_added;
        $vip_until = $userInfo->vip_until;

        $warned = (string) $request->input('warned', '');
        $warnlength = (int) $request->input('warnlength', 0);
        $warnpm = (string) $request->input('warnpm', '');
        $title = (string) $request->input('title', '');
        $avatar = (string) $request->input('avatar', '');
        $signature = (string) $request->input('signature', '');

        $enabled = (string) $request->input('enabled', '');
        $uploadpos = (string) $request->input('uploadpos', '');
        $downloadpos = (string) $request->input('downloadpos', '');
        $noad = (string) $request->input('noad', '');
        $noaduntil = (string) $request->input('noaduntil', '');
        $privacy = (string) $request->input('privacy', '');
        $forumpost = (string) $request->input('forumpost', '');
        $chpassword = (string) $request->input('chpassword', '');
        $passagain = (string) $request->input('passagain', '');

        $supportlang = (string) $request->input('supportlang', '');
        $support = (string) $request->input('support', '');
        $supportfor = (string) $request->input('supportfor', '');

        $moviepicker = (string) $request->input('moviepicker', '');
        $pickfor = (string) $request->input('pickfor', '');
        $stafffor = (string) $request->input('staffduties', '');

        if (!is_valid_id($userid) || !is_valid_user_class($class)) {
            abort(403, 'Bad user ID or class ID.');
        }
        if (get_user_class() <= $class) {
            abort(403, 'You have no permission to change user\'s class to ' . get_user_class_name($class, false, false, true) . '. BTW, how do you get here?');
        }

        $arr = User::query()->find($userid);
        if (!$arr) {
            return $this->puke($curUser);
        }

        $curenabled = $arr->enabled;
        $curparked = $arr->parked;
        $curuploadpos = $arr->uploadpos;
        $curdownloadpos = $arr->downloadpos;
        $curforumpost = $arr->forumpost;
        $curclass = $arr->class;
        $curwarned = $arr->warned;

        $updateset = [
            'stafffor' => $stafffor,
            'pickfor' => $pickfor,
            'picker' => $moviepicker,
            // 'enabled' has been migrated to the management panel
            'uploadpos' => $uploadpos,
            'downloadpos' => $downloadpos,
            'forumpost' => $forumpost,
            'avatar' => $avatar,
            'signature' => $signature,
            'title' => $title,
            'support' => $support,
            'supportfor' => $supportfor,
            'supportlang' => $supportlang,
        ];
        $banLog = [];
        $userModifyLogs = [];

        if (user_can('cruprfmanage')) {
            $email = (string) $request->input('email', '');
            $username = (string) $request->input('username', '');
            $modcomment = (string) $request->input('modcomment', '');
            $downloaded = $request->input('downloaded');
            $ori_downloaded = $request->input('ori_downloaded');
            $uploaded = $request->input('uploaded');
            $ori_uploaded = $request->input('ori_uploaded');
            $bonus = $request->input('bonus');
            $ori_bonus = $request->input('ori_bonus');
            $invites = $request->input('invites');
            $added = date('Y-m-d H:i:s');

            if ($arr->email != $email) {
                $updateset['email'] = $email;
                $modifyLog = "Email changed from {$arr->email} to $email by {$curUser['username']}.";
                do_log($modifyLog, 'alert');
                $userModifyLogs[] = $modifyLog;
                $locale = get_user_locale($userid);
                $subject = nexus_trans('user.msg_email_change', [], $locale);
                $msg = nexus_trans('user.msg_your_email_changed_from', [], $locale) . $arr->email . nexus_trans('user.msg_to_new', [], $locale) . $email . nexus_trans('user.msg_by', [], $locale) . $curUser['username'];

                Message::add([
                    'sender' => 0,
                    'receiver' => $userid,
                    'subject' => $subject,
                    'msg' => $msg,
                    'added' => now(),
                ]);
            }
            if ($arr->username != $username) {
                $updateset['username'] = $username;
                $userModifyLogs[] = "Username changed from {$arr->username} to $username by {$curUser['username']}";

                $subject = nexus_trans('user.msg_username_change', [], $locale);
                $msg = nexus_trans('user.msg_your_username_changed_from', [], $locale) . $arr->username . nexus_trans('user.msg_to_new', [], $locale) . $username . nexus_trans('user.msg_by', [], $locale) . $curUser['username'];

                Message::add([
                    'sender' => 0,
                    'receiver' => $userid,
                    'subject' => $subject,
                    'msg' => $msg,
                    'added' => now(),
                ]);

                UsernameChangeLog::query()->create([
                    'uid' => $arr->id,
                    'operator' => $curUser['username'],
                    'change_type' => UsernameChangeLog::CHANGE_TYPE_ADMIN,
                    'username_old' => $arr->username,
                    'username_new' => $username,
                ]);
            }
            // downloaded/uploaded/bonus/invites edits have been migrated to the
            // management panel
        }

        if (get_user_class() == UC_STAFFLEADER) {
            $donor = (string) $request->input('donor', '');
            $donoruntil = !empty($request->input('donoruntil')) ? (string) $request->input('donoruntil') : null;
            $donated = (int) $request->input('donated', 0);
            $donated_cny = (int) $request->input('donated_cny', 0);
            $this_donated_usd = $donated - (int) $arr->donated;
            $this_donated_cny = $donated_cny - (int) $arr->donated_cny;
            $memo = htmlspecialchars((string) $request->input('donation_memo', ''));

            if ($donated != $arr->donated || $donated_cny != $arr->donated_cny) {
                $added = date('Y-m-d H:i:s');
                DB::table('funds')->insert([
                    'usd' => $this_donated_usd,
                    'cny' => $this_donated_cny,
                    'user' => $userid,
                    'added' => $added,
                    'memo' => $memo,
                ]);
                $updateset['donated'] = $donated;
                $updateset['donated_cny'] = $donated_cny;
            }
            $updateset['donor'] = $donor;
            $updateset['donoruntil'] = $donoruntil;

            if (($donor != $arr->donor) && (($donor == 'yes' && $donoruntil && $donoruntil >= date('Y-m-d H:i:s')) || ($donor == 'no'))) {
                $subject = nexus_trans('user.msg_your_donor_status_changed', [], $locale);
                $msg = nexus_trans('user.msg_donor_status_changed_by', [], $locale) . $curUser['username'];
                $added = date('Y-m-d H:i:s');

                Message::add([
                    'sender' => 0,
                    'receiver' => $userid,
                    'subject' => $subject,
                    'msg' => $msg,
                    'added' => now(),
                ]);

                $userModifyLogs[] = "donor status changed by {$curUser['username']}. Current donor status: $donor";
            }
        }

        // password change has been migrated to the management panel

        if ($curclass >= get_user_class()) {
            return $this->puke($curUser);
        }

        if ($warned && $curwarned != $warned) {
            $updateset['warned'] = $warned;
            $updateset['warneduntil'] = null;
            $subject = null;
            $msg = null;

            if ($warned == 'no') {
                $userModifyLogs[] = "Warning removed by {$curUser['username']}";
                $subject = nexus_trans('user.msg_warn_removed', [], $locale);
                $msg = nexus_trans('user.msg_your_warning_removed_by', [], $locale) . $curUser['username'] . '.';
            }

            $added = date('Y-m-d H:i:s');
            Message::add([
                'sender' => 0,
                'receiver' => $userid,
                'subject' => $subject,
                'msg' => $msg,
                'added' => now(),
            ]);
        } elseif ($warnlength) {
            if ($warnlength == 255) {
                $userModifyLogs[] = "Warned by " . $curUser['username'] . ".\nReason: $warnpm.";

                $msg = nexus_trans('user.msg_you_are_warned_by', [], $locale) . $curUser['username'] . '.' . ($warnpm ? nexus_trans('user.msg_reason', [], $locale) . $warnpm : '');
                $updateset['warneduntil'] = null;
            } else {
                $warneduntil = date('Y-m-d H:i:s', strtotime(date('Y-m-d H:i:s')) + $warnlength * 604800);
                $dur = $warnlength . nexus_trans('user.msg_week', [], $locale) . ($warnlength > 1 ? nexus_trans('user.msg_s', [], $locale) : '');
                $msg = nexus_trans('user.msg_you_are_warned_for', [], $locale) . $dur . nexus_trans('user.msg_by', [], $locale) . $curUser['username'] . '.' . ($warnpm ? nexus_trans('user.msg_reason', [], $locale) . $warnpm : '');
                $userModifyLogs[] = "Warned for $dur by " . $curUser['username'] . '.Reason: ' . $warnpm;
                $updateset['warneduntil'] = $warneduntil;
            }
            $subject = nexus_trans('user.msg_you_are_warned', [], $locale);
            $added = date('Y-m-d H:i:s');

            Message::add([
                'sender' => 0,
                'receiver' => $userid,
                'subject' => $subject,
                'msg' => $msg,
                'added' => now(),
            ]);

            $updateset['warned'] = 'yes';
            $updateset['timeswarned'] = $arr->timeswarned + 1;
            $updateset['lastwarned'] = $added;
            $updateset['warnedby'] = $curUser['id'];
        }

        if ($arr->noad != $noad) {
            $updateset['noad'] = $noad;
            $userModifyLogs[] = 'No Ad set to ' . $noad . ' by ' . $curUser['username'];
        }
        if ($arr->noaduntil != $noaduntil) {
            $updateset['noaduntil'] = $noaduntil;
            $userModifyLogs[] = 'No Ad Until set to ' . $noaduntil . ' by ' . $curUser['username'];
        }
        if (in_array($privacy, ['low', 'normal', 'strong'], true)) {
            $updateset['privacy'] = $privacy;
        }

        if ($request->input('resetkey') == 'yes') {
            $newpasskey = md5($arr->username . date('Y-m-d H:i:s') . $arr->passhash);
            $updateset['passkey'] = $newpasskey;
        }

        if ($forumpost != $curforumpost) {
            if ($forumpost == 'yes') {
                $userModifyLogs[] = 'Posting enabled by ' . $curUser['username'];
                $subject = nexus_trans('user.msg_posting_rights_restored', [], $locale);
                $msg = nexus_trans('user.msg_your_posting_rights_restored', [], $locale) . $curUser['username'] . nexus_trans('user.msg_you_can_post', [], $locale);
                $added = date('Y-m-d H:i:s');
                Message::add([
                    'sender' => 0,
                    'receiver' => $userid,
                    'subject' => $subject,
                    'msg' => $msg,
                    'added' => now(),
                ]);
            } else {
                $userModifyLogs[] = 'Posting disabled by ' . $curUser['username'];
                $subject = nexus_trans('user.msg_posting_rights_removed', [], $locale);
                $msg = nexus_trans('user.msg_your_posting_rights_removed', [], $locale) . $curUser['username'] . nexus_trans('user.msg_probable_reason', [], $locale);
                $added = date('Y-m-d H:i:s');
                Message::add([
                    'sender' => 0,
                    'receiver' => $userid,
                    'subject' => $subject,
                    'msg' => $msg,
                    'added' => now(),
                ]);
            }
        }

        if ($uploadpos != $curuploadpos) {
            if ($uploadpos == 'yes') {
                $userModifyLogs[] = 'Upload enabled by ' . $curUser['username'];
                $subject = nexus_trans('user.msg_upload_rights_restored', [], $locale);
                $msg = nexus_trans('user.msg_your_upload_rights_restored', [], $locale) . $curUser['username'] . nexus_trans('user.msg_you_upload_can_upload', [], $locale);
                $added = date('Y-m-d H:i:s');
                Message::add([
                    'sender' => 0,
                    'receiver' => $userid,
                    'subject' => $subject,
                    'msg' => $msg,
                    'added' => now(),
                ]);
            } else {
                $userModifyLogs[] = 'Upload disabled by ' . $curUser['username'];
                $subject = nexus_trans('user.msg_upload_rights_removed', [], $locale);
                $msg = nexus_trans('user.msg_your_upload_rights_removed', [], $locale) . $curUser['username'] . nexus_trans('user.msg_probably_reason_two', [], $locale);
                $added = date('Y-m-d H:i:s');
                Message::add([
                    'sender' => 0,
                    'receiver' => $userid,
                    'subject' => $subject,
                    'msg' => $msg,
                    'added' => now(),
                ]);
            }
        }

        if ($downloadpos != $curdownloadpos) {
            if ($downloadpos == 'yes') {
                $userModifyLogs[] = 'Download enabled by ' . $curUser['username'];
                $subject = nexus_trans('user.msg_download_rights_restored', [], $locale);
                $msg = nexus_trans('user.msg_your_download_rights_restored', [], $locale) . $curUser['username'] . nexus_trans('user.msg_you_can_download', [], $locale);
                $added = date('Y-m-d H:i:s');

                Message::add([
                    'sender' => 0,
                    'receiver' => $userid,
                    'subject' => $subject,
                    'msg' => $msg,
                    'added' => now(),
                ]);
            } else {
                $userModifyLogs[] = 'Download disabled by ' . $curUser['username'];
                $subject = nexus_trans('user.msg_download_rights_removed', [], $locale);
                $msg = nexus_trans('user.msg_your_download_rights_removed', [], $locale) . $curUser['username'] . nexus_trans('user.msg_probably_reason_three', [], $locale);
                $added = date('Y-m-d H:i:s');

                Message::add([
                    'sender' => 0,
                    'receiver' => $userid,
                    'subject' => $subject,
                    'msg' => $msg,
                    'added' => now(),
                ]);
            }
        }

        $arr->forceFill($updateset)->save();

        if (!empty($banLog)) {
            UserBanLog::query()->insert($banLog);
        }
        if (!empty($userModifyLogs)) {
            $userModifyLogsInsert = [];
            foreach ($userModifyLogs as $userModifyLog) {
                $userModifyLogsInsert[] = [
                    'user_id' => $userid,
                    'content' => $userModifyLog,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
            }
            UserModifyLog::query()->insert($userModifyLogsInsert);
        }
        clear_user_cache($userid, $userInfo->passkey);

        $returnto = (string) $request->input('returnto', '');
        return redirect($returnto ?: 'userdetails.php?id=' . $userid);
    }

    /**
     * Log the intrusion attempt and bail out (mirrors legacy puke()).
     */
    private function puke(array $curUser)
    {
        $msg = 'User ' . $curUser['username'] . ' (id: ' . $curUser['id'] . ") is hacking user's profile. IP : " . getip();
        write_log($msg, 'mod');
        abort(403, 'Permission denied. For security reason, we logged this action');
    }
}