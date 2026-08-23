<?php

namespace App\Http\Controllers;

use App\Models\AllowedEmail;
use App\Models\Invite;
use App\Models\Setting;
use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Nexus\Database\NexusLock;

/**
 * Invitation centre — replaces legacy public/invite.php.
 *
 * GET /invite.php?id=.. shows the invitee / sent / temporary invite lists for a
 * user (or a confirm form when ?type=new). The invite form posts to
 * takeinvite.php (handled by webTakeInvite()), and the "Confirm Users" form
 * posts to takeconfirm.php (handled by AuthenticateController::confirmUser).
 */
class InviteController extends Controller
{
    public function web(Request $request)
    {
        [$curUser, $lang] = $this->bootstrap($request);

        $id = (int) $request->query('id', 0);
        $type = (string) $request->query('type', '');
        $menuSelected = (string) $request->input('menu', 'invitee');
        $pageSize = 50;

        if (($curUser['id'] != $id && !user_can('viewinvite')) || !is_valid_id($id)) {
            return $this->messagePage($lang['std_sorry'], $lang['std_permission_denied'], $lang['head_invites'], false);
        }

        $user = User::query()->find($id);
        if (!$user) {
            return $this->messagePage($lang['std_sorry'], 'Invalid id', $lang['head_invites']);
        }

        if ($type == 'new') {
            return $this->newInvite($request, $curUser, $lang, $id, $user);
        }

        $content = $this->capture(function () use ($request, $curUser, $lang, $id, $user, $menuSelected, $pageSize) {
            $this->printPageHeader($request, $lang, $id, $user);
            $this->inviteMenu($curUser, $lang, $id, $menuSelected);
            if ($menuSelected == 'invitee') {
                $this->inviteeList($request, $curUser, $lang, $id, $menuSelected, $pageSize);
            } elseif (in_array($menuSelected, ['sent', 'tmp'], true)) {
                $this->sentTmpList($request, $curUser, $lang, $id, $menuSelected, $pageSize);
            }
        });

        return view('invite', compact('content') + ['pageTitle' => $lang['head_invites']]);
    }

    // ---------------------------------------------------------- type=new (form)

    private function newInvite(Request $request, array $curUser, array $lang, int $id, User $user)
    {
        if ($curUser['id'] != $id) {
            return $this->messagePage($lang['std_sorry'], $lang['std_permission_denied'], $lang['head_invites'], false);
        }

        $userRep = app(UserRepository::class);
        try {
            $sendBtnText = $userRep->getInviteBtnText($curUser['id']);
        } catch (\Exception $exception) {
            return $this->messagePage(
                $lang['std_sorry'],
                $exception->getMessage() . '  <a class=altlink href=invite.php?id=' . $curUser['id'] . '>' . $lang['here_to_go_back'],
                $lang['head_invites'],
                false
            );
        }
        unset($sendBtnText);

        $langFunctions = $GLOBALS['lang_functions'] ?? get_legacy_lang_file('functions');
        if (get_setting('main.invitesystem') == 'no') {
            return $this->messagePage($langFunctions['std_oops'] ?? 'Oops!', $langFunctions['std_invite_system_disabled'] ?? '', $lang['head_invites']);
        }
        $maxusers = (int) get_setting('main.maxusers', 50000);
        if (User::query()->count() >= $maxusers) {
            return $this->messagePage($langFunctions['std_sorry'] ?? 'Sorry', $langFunctions['std_account_limit_reached'] ?? '', $lang['head_invites']);
        }

        $temporaryInvites = Invite::query()->where('inviter', $curUser['id'])
            ->where('invitee', '')
            ->where('expired_at', '>', now())
            ->orderBy('expired_at', 'asc')
            ->get();

        $invitationBody = sprintf($lang['text_invitation_body'], Setting::getSiteName()) . $curUser['username'];
        $inviteSelectOptions = '';
        if ($user->invites > 0) {
            $inviteSelectOptions = '<option value="permanent">' . $lang['text_permanent'] . '</option>';
        }
        foreach ($temporaryInvites as $tmp) {
            $inviteSelectOptions .= sprintf('<option value="%s">%s (%s: %s)</option>', $tmp->hash, $tmp->hash, $lang['text_expired_at'], $tmp->expired_at);
        }

        $preUsernameTr = '';
        if (get_setting('system.is_invite_pre_email_and_username') == 'yes') {
            $preUsernameTr = '<tr><td class="rowhead nowrap" valign="top" align="right">' . nexus_trans('invite.pre_register_username') . '</td><td align=left><input type=text size=40 name=pre_register_username><br /><font align=left class=small>' . nexus_trans('invite.pre_register_username_help') . '</font></td></tr>';
        }

        $suffix = $user->invites != 1 ? $lang['text_s'] : '';
        $restrictemaildomain = get_setting('main.restrictemail') == 'yes';
        $allowedEmailsText = $restrictemaildomain
            ? '<br />' . $lang['text_email_restriction_note'] . (AllowedEmail::query()->value('value') ?? '')
            : '';

        $content = $this->capture(function () use ($request, $lang, $id, $user, $invitationBody, $inviteSelectOptions, $preUsernameTr, $suffix, $temporaryInvites, $restrictemaildomain, $allowedEmailsText) {
            $this->printPageHeader($request, $lang, $id, $user);

            print('<form method=post action=takeinvite.php?id=' . htmlspecialchars($id) . '>'
                . '<table border=1 width=100% cellspacing=0 cellpadding=5>'
                . '<tr align=center><td colspan=2><b>' . $lang['text_invite_someone'] . Setting::getSiteName() . ' (' . $user->invites . $lang['text_invitation'] . $suffix . $lang['text_left'] . ' + ' . sprintf($lang['text_temporary_left'], $temporaryInvites->count()) . ")</b></td></tr>"
                . '<tr><td class="rowhead nowrap" valign="top" align="right">' . $lang['text_email_address'] . '</td><td align=left><input type=text size=40 name=email><br /><font align=left class=small>' . $lang['text_email_address_note'] . '</font>' . ($restrictemaildomain ? $allowedEmailsText : '') . '</td></tr>'
                . $preUsernameTr
                . '<tr><td class="rowhead nowrap" valign="top" align="right">' . $lang['text_consume_invite'] . '</td><td align=left><select name=\'hash\'>' . $inviteSelectOptions . '</select></td></tr>'
                . '<tr><td class="rowhead nowrap" valign="top" align="right">' . $lang['text_message'] . '</td><td align=left><textarea name=body rows=10 style=\'width: 100%\'>' . $invitationBody . '</textarea></td></tr>'
                . '<tr><td align=center colspan=2><input type=submit value=\'' . $lang['submit_invite'] . '\'></td></tr>'
                . '</form></table>');
        });

        return view('invite', compact('content') + ['pageTitle' => $lang['head_invites']]);
    }

    // ------------------------------------------------------------------ header

    private function printPageHeader(Request $request, array $lang, int $id, User $user): void
    {
        print('<table width=100% class=main border=0 cellspacing=0 cellpadding=0><tr><td class=embedded>');
        print('<h1 align=center><a href="invite.php?id=' . $id . '">' . htmlspecialchars($user->username) . $lang['text_invite_system'] . '</a></h1>');
        $sent = htmlspecialchars((string) $request->query('sent', ''));
        if ($sent == 1) {
            print('<p align=center><font color=red>' . $lang['text_invite_code_sent'] . '</font></p>');
        }
    }

    // -------------------------------------------------------------------- menu

    private function inviteMenu(array $curUser, array $lang, int $id, string $selected): void
    {
        $userRep = app(UserRepository::class);
        begin_main_frame('', false, '100%');
        print('<div id="invitenav" style=\'position: relative\'><ul id="invitemenu" class="menu">');
        print('<li' . ($selected == 'invitee' ? ' class=selected' : '') . '><a href="?id=' . $id . '&menu=invitee">' . $lang['text_invite_status'] . '</a></li>');
        print('<li' . ($selected == 'sent' ? ' class=selected' : '') . '><a href="?id=' . $id . '&menu=sent">' . $lang['text_sent_invites_status'] . '</a></li>');
        print('<li' . ($selected == 'tmp' ? ' class=selected' : '') . '><a href="?id=' . $id . '&menu=tmp">' . $lang['text_tmp_status'] . '</a></li>');
        try {
            $sendBtnText = $userRep->getInviteBtnText($curUser['id']);
            $disabled = '';
        } catch (\Exception $exception) {
            $sendBtnText = $exception->getMessage();
            $disabled = ' disabled';
        }
        if ($curUser['id'] == $id) {
            print('</ul><form style=\'position: absolute;top:0;right:0\' method=post action=invite.php?id=' . htmlspecialchars($id) . '&type=new><input type=submit ' . $disabled . ' value=\'' . $sendBtnText . '\'></form></div>');
        }
        end_main_frame();
    }

    // -------------------------------------------------------------- menu=invitee

    private function inviteeList(Request $request, array $curUser, array $lang, int $id, string $menuSelected, int $pageSize): void
    {
        $query = User::query()->where('invited_by', $id);
        if (!empty($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }
        if (!empty($request->query('enabled'))) {
            $query->where('enabled', $request->query('enabled'));
        }
        $number = (clone $query)->count();

        $textSelectOnePlease = nexus_trans('nexus.select_one_please');
        $enabledOptions = $statusOptions = '';
        foreach (['yes', 'no'] as $item) {
            $enabledOptions .= sprintf(
                '<option value="%s"%s>%s</option>',
                $item, $request->query('enabled') == $item ? ' selected' : '', strtoupper($item)
            );
        }
        foreach (['pending' => $lang['text_pending'], 'confirmed' => $lang['text_confirmed']] as $name => $text) {
            $statusOptions .= sprintf(
                '<option value="%s"%s>%s</option>',
                $name, $request->query('status') == $name ? ' selected' : '', $text
            );
        }

        $resetText = nexus_trans('label.reset');
        $submitText = nexus_trans('label.submit');
        $requestUri = $request->getRequestUri();
        $filterForm = <<<FORM
<div>
    <form id="filterForm" action="{$requestUri}" method="get">
        <input type="hidden" name="menu" value="{$menuSelected}" />
        <input type="hidden" name="id" value="{$id}" />
        <span>{$lang['text_enabled']}:</span>
        <select name="enabled">
            <option value="">-{$textSelectOnePlease}-</option>
            {$enabledOptions}
        </select>
        &nbsp;&nbsp;
        <span>{$lang['text_status']}:</span>
        <select name="status">
            <option value="">-{$textSelectOnePlease}-</option>
            {$statusOptions}
        </select>
        &nbsp;&nbsp;
        <input type="submit" value="{$submitText}">
        <input type="button" id="reset" value="{$resetText}">
    </form>
</div>
<script type="text/javascript">
jQuery("#reset").on('click', function () {
    jQuery("select[name=status]").val('')
    jQuery("select[name=enabled]").val('')
})
</script>
FORM;
        print($filterForm . '<table border=1 width=100% cellspacing=0 cellpadding=5>'
            . '<form method=post action=takeconfirm.php?id=' . htmlspecialchars($id) . '>');

        if (!$number) {
            print('<tr><td colspan=7 align=center>' . $lang['text_no_invites'] . '</tr>');
        } else {
            list($pagertop, $pagerbottom, $limit, $start, $rpp) = pager($pageSize, $number, '?id=' . $id . '&menu=' . $menuSelected . '&');
            $haremAdditionFactor = (float) get_setting('bonus.harem_addition');
            $rows = (clone $query)
                ->withCount(['torrents as torrent_count'])
                ->orderBy('id')
                ->skip($start)
                ->take($rpp)
                ->get();

            print('<tr>'
                . '<td class=colhead><b>' . $lang['text_username'] . '</b></td>'
                . '<td class=colhead><b>' . $lang['text_email'] . '</b></td>'
                . '<td class=colhead><b>' . $lang['text_enabled'] . '</b></td>'
                . '<td class=colhead><b>' . $lang['text_uploaded_count'] . '</b></td>'
                . '<td class=colhead><b>' . $lang['text_uploaded'] . '</b></td>'
                . '<td class=colhead><b>' . $lang['text_downloaded'] . '</b></td>'
                . '<td class=colhead><b>' . $lang['text_ratio'] . '</b></td>'
                . '<td class=colhead><b>' . $lang['text_seed_torrent_count'] . '</b></td>'
                . '<td class=colhead><b>' . $lang['text_seed_torrent_size'] . '</b></td>'
                . '<td class=colhead title=' . $lang['text_seed_torrent_bonus_per_hour_help'] . '><b>' . $lang['text_seed_torrent_bonus_per_hour'] . '</b></td>'
            );
            if ($haremAdditionFactor > 0) {
                print('<td class="colhead">' . $lang['harem_addition'] . '</td>');
            }
            print('<td class=colhead><b>' . $lang['text_seed_torrent_last_announce_at'] . '</b></td>');
            print('<td class=colhead><b>' . $lang['text_status'] . '</b></td>');
            if ($curUser['id'] == $id || get_user_class() >= UC_SYSOP) {
                print('<td class=colhead><b>' . $lang['text_confirm'] . '</b></td>');
            }
            print('</tr>');

            foreach ($rows as $arr) {
                if ($arr->downloaded > 0) {
                    $ratio = number_format($arr->uploaded / $arr->downloaded, 3);
                    $ratio = '<font color="' . get_ratio_color($ratio) . '">' . $ratio . '</font>';
                } else {
                    $ratio = $arr->uploaded > 0 ? 'Inf.' : '---';
                }
                if ($arr->status == 'confirmed') {
                    $status = '<a href=userdetails.php?id=' . $arr->id . '><font color=#1f7309>' . $lang['text_confirmed'] . '</font></a>';
                } else {
                    $status = '<a href=checkuser.php?id=' . $arr->id . '><font color=#ca0226>' . $lang['text_pending'] . '</font></a>';
                }
                print('<tr class=rowfollow>'
                    . '<td class=rowfollow>' . get_username($arr->id) . '</td>'
                    . '<td class=rowfollow>' . $arr->email . '</td>'
                    . '<td class=rowfollow>' . $arr->enabled . '</td>'
                    . '<td class=rowfollow>' . $arr->torrent_count . '</td>'
                    . '<td class=rowfollow>' . mksize($arr->uploaded) . '</td>'
                    . '<td class=rowfollow>' . mksize($arr->downloaded) . '</td>'
                    . '<td class=rowfollow>' . $ratio . '</td>'
                    . '<td class=rowfollow>' . number_format($arr->seeding_torrent_count) . '</td>'
                    . '<td class=rowfollow>' . mksize($arr->seeding_torrent_size) . '</td>'
                    . '<td class=rowfollow>' . number_format($arr->seed_points_per_hour, 3) . '</td>'
                );
                if ($haremAdditionFactor > 0) {
                    print('<td class=rowfollow>' . number_format((float) $arr->seed_points_per_hour * $haremAdditionFactor, 3) . '</td>');
                }
                print('<td class=rowfollow>' . $arr->last_announce_at . '</td>');
                print('<td class=rowfollow>' . $status . '</td>');
                if ($curUser['id'] == $id || get_user_class() >= UC_SYSOP) {
                    print('<td class=rowfollow>');
                    if ($arr->status == 'pending') {
                        print('<input type="checkbox" name="conusr[]" value="' . $arr->id . '" />');
                    }
                    print('</td>');
                }
                print('</tr>');
            }
        }

        if ($curUser['id'] == $id || get_user_class() >= UC_SYSOP) {
            $pendingcount = User::query()->where('status', 'pending')->where('invited_by', $curUser['id'])->count();
            $colSpan = 12;
            if ((float) get_setting('bonus.harem_addition') > 0) {
                $colSpan += 1;
            }
            if ($pendingcount) {
                print('<tr><td colspan=' . $colSpan . ' align=right><input type=submit style=\'height: 20px\' value=' . $lang['submit_confirm_users'] . '></td></tr>');
            }
            print('</form>');
        }
        print('</table>');
        print('</td></tr></table>' . ($pagertop ?? ''));
    }

    // ---------------------------------------------------------- menu=sent / tmp

    private function sentTmpList(Request $request, array $curUser, array $lang, int $id, string $menuSelected, int $pageSize): void
    {
        $query = Invite::query()->where('inviter', $id);
        if ($menuSelected == 'sent') {
            $query->where('invitee', '!=', '');
        } elseif ($menuSelected == 'tmp') {
            $query->where('invitee', '')->whereNotNull('expired_at');
        }
        $number1 = (clone $query)->count();

        print('<table border=1 width=100% cellspacing=0 cellpadding=5>');

        if (!$number1) {
            print('<tr align=center><td colspan=6>' . ($GLOBALS['lang_functions']['text_none'] ?? 'None') . '</tr>');
        } else {
            list($pagertop, $pagerbottom, $limit, $start, $rpp) = pager($pageSize, $number1, '?id=' . $id . '&menu=' . $menuSelected . '&');
            $rows = (clone $query)->orderBy('id')->skip($start)->take($rpp)->get();

            print('<tr>'
                . '<td class=colhead>' . $lang['text_email'] . '</td>'
                . '<td class=colhead>' . $lang['text_hash'] . '</td>'
                . '<td class=colhead>' . $lang['text_send_date'] . '</td>'
            );
            if ($menuSelected == 'sent') {
                print('<td class=\'colhead\'>' . $lang['text_hash_status'] . '</td>');
            }
            print('<td class=\'colhead\'>' . $lang['text_invitee_user'] . '</td>');
            if ($menuSelected == 'tmp') {
                print('<td class=\'colhead\'>' . $lang['text_expired_at'] . '</td>');
                print('<td class=\'colhead\'>' . nexus_trans('label.created_at') . '</td>');
            }
            print('</tr>');

            foreach ($rows as $arr1) {
                $isHashValid = $arr1->valid == Invite::VALID_YES;
                $registerLink = '';
                if ($isHashValid) {
                    $registerLink = sprintf('&nbsp;<a href="signup.php?type=invite&invitenumber=%s" title="%s" target="_blank"><small>[%s]</small></a>', $arr1->hash, $lang['signup_link_help'], $lang['signup_link']);
                }
                print('<tr>');
                print('<td class=rowfollow>' . $arr1->invitee . '</td>');
                print(sprintf('<td class="rowfollow">%s%s</td>', $arr1->hash, $registerLink));
                print('<td class=rowfollow>' . $arr1->time_invited . '</td>');
                if ($menuSelected == 'sent') {
                    print('<td class=rowfollow>' . Invite::$validInfo[$arr1->valid]['text'] . '</td>');
                }
                if (!$isHashValid) {
                    print('<td class=rowfollow><a href=userdetails.php?id=' . $arr1->invitee_register_uid . '><font color=#1f7309>' . $arr1->invitee_register_username . '</font></a></td>');
                } else {
                    print('<td class=\'rowfollow\'></td>');
                }
                if ($menuSelected == 'tmp') {
                    print('<td class=rowfollow>' . $arr1->expired_at . '</td>');
                    print('<td class=rowfollow>' . $arr1->created_at . '</td>');
                }
                print('</tr>');
            }
        }
        print('</table>');
        print('</td></tr></table>' . ($pagertop ?? ''));
    }

    // -------------------------------------------------------------- bootstrap

    /**
     * Invite submission. Mirrors legacy public/takeinvite.php so the invite
     * form rendered by web() (which posts to takeinvite.php) keeps working
     * under the Laravel router instead of the procedural script.
     *
     * POST /takeinvite.php
     */
    public function webTakeInvite(Request $request)
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
        $id = (int) $curUser['id'];
        $lang = get_legacy_lang_file('takeinvite');

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_takeinvite'] = $lang;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();
        $GLOBALS['SITEEMAIL'] = (string) get_setting('main.SITEEMAIL', '');
        $GLOBALS['REPORTMAIL'] = (string) get_setting('main.reportemail', '');
        $GLOBALS['invite_timeout'] = (string) get_setting('main.invite_timeout', '7');
        $GLOBALS['smtptype'] = get_setting('smtp.smtptype', '');
        $GLOBALS['restrictemaildomain'] = get_setting('main.restrictemail', 'no');

        $lockName = sprintf('takeinvite:%s', $id);
        $lock = new NexusLock($lockName, 10);
        if (! $lock->get()) {
            return $this->inviteFailed(nexus_trans('nexus.do_not_repeat'), nexus_trans('nexus.do_not_repeat'));
        }
        try {
            $sendText = (new UserRepository())->getInviteBtnText($id);
        } catch (\Exception $exception) {
            $lock->release();
            return $this->inviteFailed($lang['std_error'], $exception->getMessage());
        }
        unset($sendText);

        $bark = function (string $message) use ($lock) {
            $lock->release();
            return $this->inviteFailed($GLOBALS['lang_takeinvite']['head_invitation_failed'] ?? 'Invitation failed!', $message);
        };

        if (get_setting('main.invitesystem') == 'no') {
            return $bark($GLOBALS['lang_functions']['std_invite_system_disabled'] ?? '');
        }
        $maxusers = (int) get_setting('main.maxusers', 50000);
        if (User::query()->count() >= $maxusers) {
            return $bark($GLOBALS['lang_functions']['std_account_limit_reached'] ?? '');
        }

        $email = unesc(htmlspecialchars(trim((string) $request->input('email', ''))));
        $email = safe_email($email);
        $preRegisterUsername = (string) $request->input('pre_register_username', '');
        $isPreRegisterEmailAndUsername = get_setting('system.is_invite_pre_email_and_username') == 'yes';

        if (strlen($preRegisterUsername) > 12) {
            return $bark($lang['std_username_too_long']);
        }
        if (! $email) {
            return $bark($lang['std_must_enter_email']);
        }
        if (! check_email($email)) {
            return $bark($lang['std_invalid_email_address']);
        }
        if (EmailBanned($email)) {
            return $bark($lang['std_email_address_banned']);
        }
        if (! EmailAllowed($email)) {
            return $bark($lang['std_wrong_email_address_domains'] . allowedemails());
        }

        $body = str_replace('<br />', '<br />', nl2br(trim(strip_tags((string) $request->input('body', '')))));
        if (! $body) {
            return $bark($lang['std_must_enter_personal_message']);
        }

        if ($isPreRegisterEmailAndUsername) {
            if (empty($preRegisterUsername)) {
                return $bark(nexus_trans('invite.require_pre_register_username'));
            }
            if (! validusername($preRegisterUsername)) {
                return $bark(nexus_trans('user.username_invalid', ['username' => $preRegisterUsername]));
            }
            $exists = User::query()->where('username', $preRegisterUsername)->exists();
            if ($exists) {
                return $bark(nexus_trans('user.username_already_exists', ['username' => $preRegisterUsername]));
            }
        }

        // check if email addy is already in use
        if (User::query()->where('email', $email)->exists()) {
            return $bark($lang['std_email_address'] . htmlspecialchars($email) . $lang['std_is_in_use']);
        }
        if (Invite::query()->where('invitee', $email)->exists()) {
            return $bark($lang['std_invitation_already_sent_to'] . htmlspecialchars($email) . $lang['std_await_user_registeration']);
        }

        $username = User::query()->where('id', $id)->value('username');

        $hashRecord = null;
        if (empty($request->input('hash'))) {
            return $bark($lang['std_must_select_invite'] ?? 'Please select an invite to use.');
        }
        if ($request->input('hash') == 'permanent') {
            $passhash = (string) User::query()->where('id', $id)->value('passhash');
            $hash = md5(mt_rand(1, 10000) . $curUser['username'] . TIMENOW . $passhash);
        } else {
            $hashRecord = Invite::query()->where('inviter', $id)->where('hash', $request->input('hash'))->first();
            if (! $hashRecord) {
                return $bark($lang['hash_not_exists'] ?? 'hash not exists');
            }
            if ($hashRecord->invitee != '') {
                return $bark('hash ' . $lang['std_is_in_use']);
            }
            if ($hashRecord->expired_at->lt(now())) {
                return $bark($lang['hash_expired'] ?? 'hash expired');
            }
            $hash = (string) $request->input('hash');
        }

        $title = $GLOBALS['SITENAME'] . $lang['mail_tilte'];

        $signupUrl = getSchemeAndHttpHost() . '/signup.php?type=invite&invitenumber=' . $hash;
        $siteName = Setting::getSiteName();
        $mailTwo = sprintf($lang['mail_two'], $siteName, $siteName);
        $mailFour = sprintf($lang['mail_four'], $siteName);
        $mailSix = sprintf($lang['mail_six'], $GLOBALS['REPORTMAIL'], $siteName);
        $inviteTimeout = $GLOBALS['invite_timeout'];
        $message = <<<EOD
{$lang['mail_one']}{$username}{$mailTwo}
<b><a href="javascript:void(null)" onclick="window.open($signupUrl)">{$lang['mail_here']}</a></b><br />
$signupUrl
<br />{$lang['mail_three']}$inviteTimeout{$mailFour}{$username}{$lang['mail_five']}<br />
$body
<br /><br />{$mailSix}
EOD;

        $sendResult = sent_mail($email, $GLOBALS['SITENAME'], $GLOBALS['SITEEMAIL'], $title, $message, 'invitesignup', false, false, '');
        if ($sendResult === true) {
            if (isset($hashRecord)) {
                $update = [
                    'invitee' => $email,
                    'time_invited' => now(),
                    'valid' => Invite::VALID_YES,
                ];
                if ($isPreRegisterEmailAndUsername) {
                    $update['pre_register_email'] = $email;
                    $update['pre_register_username'] = $preRegisterUsername;
                }
                $hashRecord->update($update);
            } else {
                $insert = [
                    'inviter' => $id,
                    'invitee' => $email,
                    'hash' => $hash,
                    'time_invited' => now()->toDateTimeString(),
                ];
                if ($isPreRegisterEmailAndUsername) {
                    $insert['pre_register_email'] = $email;
                    $insert['pre_register_username'] = $preRegisterUsername;
                }
                Invite::query()->insert($insert);
                User::query()->where('id', $id)->decrement('invites');
            }
        }
        $lock->release();

        return redirect('invite.php?id=' . htmlspecialchars((string) $id) . '&sent=1');
    }

    private function inviteFailed(string $heading, string $message)
    {
        return view('error.notification', [
            'pageTitle' => $heading,
            'heading' => htmlspecialchars($heading),
            'message' => htmlspecialchars($message),
        ]);
    }

    private function bootstrap(Request $request): array
    {
        /** @var User $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        if (!$currentUser) {
            abort(401);
        }
        $curUser = $currentUser->toArray();
        if (($curUser['parked'] ?? '') == 'yes') {
            abort(403, 'Your account is parked.');
        }

        $lang = get_legacy_lang_file('invite');

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_invite'] = $lang;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        return [$curUser, $lang];
    }

    private function messagePage(string $heading, string $text, string $pageTitle = 'Error', bool $htmlstrip = true)
    {
        $content = $this->capture(function () use ($heading, $text, $htmlstrip) {
            stderr($heading, $text, $htmlstrip, false, false, false);
        });

        return view('invite', compact('content') + ['pageTitle' => $pageTitle]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();

        return (string) ob_get_clean();
    }
}
