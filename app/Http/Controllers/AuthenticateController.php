<?php

namespace App\Http\Controllers;

use App\Exceptions\NexusException;
use App\Http\Resources\ExamResource;
use App\Http\Resources\UserResource;
use App\Models\Invite;
use App\Models\Language;
use App\Models\LoginLog;
use App\Models\PersonalAccessTokenPlain;
use App\Models\Setting;
use App\Models\User;
use App\Repositories\AuthenticateRepository;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\UserRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;
use Nexus\Database\NexusDB;

class AuthenticateController extends Controller
{
    private $repository;

    private $loginAttemptRepository;

    public function __construct(
        AuthenticateRepository $repository,
        LoginAttemptRepository $loginAttemptRepository
    ) {
        $this->repository = $repository;
        $this->loginAttemptRepository = $loginAttemptRepository;
    }

    public function showLoginForm(Request $request)
    {
        $this->loginAttemptRepository->checkAndThrow('Login');
        if (Auth::check()) {
            return redirect('/');
        }
        $switched = $this->switchSiteLanguage($request);
        if ($switched) {
            return $switched;
        }
        return view('auth/login', ['request' => $request]);
    }

    public function webLogin(Request $request)
    {
        $this->loginAttemptRepository->checkAndThrow('Login');
        $langLogin = get_legacy_lang_file('takelogin');
        $langFunctions = get_legacy_lang_file('functions');

        if (Auth::check()) {
            return redirect('/');
        }

        try {
            verify_captcha(
                ['imagehash' => $request->input('imagehash'), 'imagestring' => $request->input('imagestring')],
                'login.php',
                true
            );
        } catch (NexusException $exception) {
            $this->loginAttemptRepository->recordFailure('login');
            return $this->loginFailedRedirect($langLogin['std_login_fail'], $exception->getMessage());
        }

        $username = $request->input('username');
        $password = $request->input('password');
        $ip = getip();

        $useChallengeResponse = Setting::getIsUseChallengeResponseAuthentication();
        if ($useChallengeResponse) {
            if (empty($request->input('response'))) {
                $this->loginAttemptRepository->recordFailure('login');
                return $this->loginFailedRedirect($langLogin['std_login_fail'], $langLogin['std_login_fail_note']);
            }
        } else {
            if (empty($password)) {
                $this->loginAttemptRepository->recordFailure('login');
                return $this->loginFailedRedirect($langLogin['std_login_fail'], $langLogin['std_login_fail_note']);
            }
        }

        $user = User::query()
            ->where('username', $username)
            ->first(['id', 'passhash', 'secret', 'auth_key', 'enabled', 'status', 'two_step_secret', 'lang', 'username']);

        if (!$user) {
            $this->loginAttemptRepository->recordFailure('login');
            return $this->loginFailedRedirect($langLogin['std_login_fail'], $langLogin['std_login_fail_note']);
        }
        if ($user->status == User::STATUS_PENDING) {
            $this->loginAttemptRepository->recordFailure('login');
            return $this->loginFailedRedirect($langLogin['std_login_fail'], $langLogin['std_user_account_unconfirmed']);
        }
        if ($user->enabled == User::ENABLED_NO && Setting::getSelfEnableBonus() <= 0) {
            return $this->loginFailedRedirect($langLogin['std_login_fail'], $langLogin['std_account_disabled']);
        }

        if (!empty($user->two_step_secret)) {
            $twoStepCode = $request->input('two_step_code');
            if (empty($twoStepCode)) {
                $this->loginAttemptRepository->recordFailure('login');
                return $this->loginFailedRedirect($langLogin['std_login_fail'], $langLogin['std_require_two_step_code']);
            }
            $ga = new \PHPGangsta_GoogleAuthenticator();
            if (!$ga->verifyCode($user->two_step_secret, $twoStepCode)) {
                $this->loginAttemptRepository->recordFailure('login');
                return $this->loginFailedRedirect($langLogin['std_login_fail'], $langLogin['std_invalid_two_step_code']);
            }
        }

        $update = [];
        $log = "user: {$user->id}, ip: $ip";
        if ($useChallengeResponse) {
            $challenge = NexusDB::cache_get(get_challenge_key($username));
            if (empty($challenge)) {
                $this->loginAttemptRepository->recordFailure('login');
                return $this->loginFailedRedirect($langLogin['std_login_fail'], $langLogin['std_login_fail_note']);
            }
            $response = $request->input('response');
            $log .= ", useChallengeResponse, client response: $response";
        } else {
            $passwordHash = hash('sha256', $user->secret . hash('sha256', $password));
            $log .= ", !useChallengeResponse, passwordHash: $passwordHash";
            if (empty($user->auth_key)) {
                //legacy md5 verification, upgrade to challenge response
                if ($user->passhash != md5($user->secret . $password . $user->secret)) {
                    do_log("$log, md5 not equal");
                    $this->loginAttemptRepository->recordFailure('login');
                    return $this->loginFailedRedirect($langLogin['std_login_fail'], $langLogin['std_login_fail_note']);
                }
                $log .= ", no auth_key, upgrade to challenge response";
                $update['passhash'] = $user->passhash = $passwordHash;
            }
            $challenge = mksecret();
            $response = hash_hmac('sha256', $passwordHash, $challenge);
            $log .= ", server generate response: $response";
        }
        $expectedResponse = hash_hmac('sha256', $user->passhash, $challenge);
        $log .= ", expectedResponse: $expectedResponse";
        if (!hash_equals($expectedResponse, $response)) {
            do_log("$log, !hash_equals");
            $this->loginAttemptRepository->recordFailure('login');
            return $this->loginFailedRedirect($langLogin['std_login_fail'], $langLogin['std_login_fail_note']);
        }
        NexusDB::cache_del(get_challenge_key($username));
        do_log("$log, login successful");

        $userRep = new UserRepository();
        $userRep->saveLoginLog($user->id, $ip, 'Web', true);

        //update user lang
        $language = Language::query()->where("site_lang_folder", get_langfolder_cookie())->first();
        if ($language && $language->id != $user->lang) {
            do_log(sprintf("update user: %s lang: %s => %s", $user->id, $user->lang, $language->id));
            $update["lang"] = $language->id;
        }
        if (empty($user->auth_key)) {
            $user->auth_key = $update['auth_key'] = hash('sha256', mksecret(32));
        }
        if (!empty($update)) {
            User::query()->where("id", $user->id)->update($update);
            clear_user_cache($user->id);
        }

        if ($request->input('logout') == 'yes') {
            logincookie($user->id, $user->auth_key, 900);
        } else {
            logincookie($user->id, $user->auth_key);
        }

        $returnto = $request->input('returnto');
        if (!empty($returnto)) {
            return redirect($returnto);
        }
        return redirect('/index.php');
    }

    public function webLogout(Request $request)
    {
        logoutcookie();
        return redirect('/');
    }

    public function showSignupForm(Request $request)
    {
        $type = $request->query('type', '');
        if (Auth::check()) {
            return redirect('/');
        }
        $switched = $this->switchSiteLanguage($request);
        if ($switched) {
            return $switched;
        }
        try {
            if ($type == 'invite') {
                registration_check();
                $this->loginAttemptRepository->checkAndThrow('Invite signup');
                $code = $request->query('invitenumber', '');
                if (empty($code)) {
                    return $this->signupBarkRedirect(get_legacy_lang_file('signup'), 'Require invitenumber');
                }
                $inv = Invite::query()->where('valid', Invite::VALID_YES)->where('hash', $code)->first();
                if (!$inv) {
                    return $this->signupBarkRedirect(get_legacy_lang_file('signup'), get_legacy_lang_file('signup')['std_uninvited']);
                }
                return view('auth/signup', ['request' => $request, 'type' => $type, 'inv' => $inv]);
            }
            registration_check('normal');
            $this->loginAttemptRepository->checkAndThrow('Signup');
            return view('auth/signup', ['request' => $request, 'type' => '', 'inv' => null]);
        } catch (NexusException $exception) {
            return $this->signupBarkRedirect(get_legacy_lang_file('signup'), $exception->getMessage());
        }
    }

    public function signup(Request $request)
    {
        $langSignup = get_legacy_lang_file('takesignup');
        $langFunctions = get_legacy_lang_file('functions');

        $type = $request->input('type', '');
        try {
            if ($type == 'invite') {
                registration_check();
                $this->loginAttemptRepository->checkAndThrow('Invite Signup');
            } else {
                registration_check('normal');
                $this->loginAttemptRepository->checkAndThrow('Signup');
            }
            $where = $type == 'invite'
                ? 'signup.php?type=invite&invitenumber=' . htmlspecialchars($request->input('hash', ''))
                : 'signup.php';
            verify_captcha(
                ['imagehash' => $request->input('imagehash'), 'imagestring' => $request->input('imagestring')],
                $where
            );
        } catch (NexusException $exception) {
            return $this->signupBarkRedirect($langSignup, $exception->getMessage());
        }

        $isPreRegisterEmailAndUsername = get_setting("system.is_invite_pre_email_and_username") == "yes";

        $inviter = null;
        $inv = null;
        if ($type == 'invite') {
            $inviter = (int) $request->input('inviter', 0);
            $code = $request->input('hash', '');
            $inv = \App\Models\Invite::query()->where('valid', \App\Models\Invite::VALID_YES)->where('hash', $code)->first();
            if (!$inv) {
                return $this->signupBarkRedirect($langSignup, 'invalid invite code');
            }
            if ($inv->inviter != $inviter) {
                \App\Models\Invite::query()->where('id', $inv->id)->update(['valid' => \App\Models\Invite::VALID_NO]);
                return $this->signupBarkRedirect($langSignup, nexus_trans('invite.invalid_inviter'));
            }
        }

        $wantusername = $request->input('wantusername');
        $wantpassword = $request->input('wantpassword');
        $email = $request->input('email');

        if ($isPreRegisterEmailAndUsername && $type == 'invite' && $inv && !empty($inv->pre_register_username) && !empty($inv->pre_register_email)) {
            $wantusername = $inv->pre_register_username;
            $email = $inv->pre_register_email;
        }

        $email = trim($email);
        $email = safe_email($email);
        if (!check_email($email)) {
            return $this->signupBarkRedirect($langSignup, $langSignup['std_invalid_email_address']);
        }
        if (EmailBanned($email)) {
            return $this->signupBarkRedirect($langSignup, $langSignup['std_email_address_banned']);
        }
        if (!EmailAllowed($email)) {
            return $this->signupBarkRedirect($langSignup, $langSignup['std_wrong_email_address_domains'] . allowedemails());
        }

        $country = (int) $request->input('country', 0);
        $school = null;
        if (get_setting('main.enableschool') == 'yes') {
            $school = (int) $request->input('school', 0);
        }

        $gender = trim($request->input('gender', ''));
        $allowedGenders = ["Male", "Female", "male", "female"];
        if (!in_array($gender, $allowedGenders, true)) {
            return $this->signupBarkRedirect($langSignup, $langSignup['std_invalid_gender']);
        }

        if (empty($wantusername) || empty($wantpassword) || empty($email) || empty($country) || empty($gender)) {
            return $this->signupBarkRedirect($langSignup, $langSignup['std_blank_field']);
        }
        if (strlen($wantusername) > 12) {
            return $this->signupBarkRedirect($langSignup, $langSignup['std_username_too_long']);
        }
        if (!validemail($email)) {
            return $this->signupBarkRedirect($langSignup, $langSignup['std_wrong_email_address_format']);
        }
        if (!validusername($wantusername)) {
            return $this->signupBarkRedirect($langSignup, $langSignup['std_invalid_username']);
        }
        if ($request->input('rulesverify') != 'yes' || $request->input('faqverify') != 'yes' || $request->input('ageverify') != 'yes') {
            return $this->signupBarkRedirect($langSignup, $langSignup['std_unqualified']);
        }

        $emailExists = User::query()->whereRaw('BINARY email = ?', [$email])->count();
        if ($emailExists != 0) {
            return $this->signupBarkRedirect($langSignup, $langSignup['std_email_address'] . $email . $langSignup['std_in_use']);
        }

        $usernameExists = User::query()->where('username', $wantusername)->count();
        if ($usernameExists == 1) {
            return $this->signupBarkRedirect($langSignup, $langSignup['std_username_exists']);
        }

        $secret = mksecret();
        $wantpasshash = hash('sha256', $secret . $wantpassword);
        $verification = get_setting('main.verification');
        $editsecret = ($verification == 'admin' ? '' : $secret);
        $inviteCount = (int) get_setting('main.invite_count', 0);
        $passkey = md5($wantusername . date("Y-m-d H:i:s") . $wantpasshash);
        $siteLangId = get_langid_from_langcookie();
        $authKey = mksecret();
        $showschool = get_setting('main.enableschool') == 'yes';
        $defaultClass = get_setting('authority.defaultclass', User::CLASS_USER);
        $defCss = get_setting('main.defstylesheet', 3);
        $iniUpload = (int) get_setting('main.iniupload', 0);

        $userData = [
            'username' => $wantusername,
            'passhash' => $wantpasshash,
            'passkey' => $passkey,
            'secret' => $secret,
            'auth_key' => $authKey,
            'editsecret' => $editsecret,
            'email' => $email,
            'country' => $country,
            'gender' => $gender,
            'status' => User::STATUS_PENDING,
            'class' => $defaultClass,
            'invites' => $inviteCount,
            'added' => date("Y-m-d H:i:s"),
            'last_access' => date("Y-m-d H:i:s"),
            'lang' => $siteLangId,
            'stylesheet' => $defCss,
            'uploaded' => $iniUpload,
        ];
        if ($showschool) {
            $userData['school'] = $school;
        }
        if ($type == 'invite' && $inviter) {
            $userData['invited_by'] = $inviter;
        }

        $user = User::query()->create($userData);
        $userInfo = User::query()->find($user->id, User::$commonFields);
        fire_event("user_created", $userInfo);

        $tmpInviteCount = get_setting('main.tmp_invite_count');
        if ($tmpInviteCount > 0) {
            $userRep = new UserRepository();
            $userRep->addTemporaryInvite(null, $user->id, 'increment', (int) $tmpInviteCount, 7);
        }

        $siteName = Setting::getSiteName();
        $subject = $langSignup['msg_subject'] . $siteName . "!";
        $msg = \App\Models\MessageTemplate::forRegisterWelcome($userInfo->lang, ['username' => $userInfo->username]);
        if (empty($msg)) {
            $msg = $langSignup['msg_congratulations'] . $wantusername . sprintf($langSignup['msg_you_are_a_member'], $siteName, $siteName);
        }
        \App\Models\Message::add([
            'sender' => 0,
            'receiver' => $user->id,
            'subject' => $subject,
            'added' => date("Y-m-d H:i:s"),
            'msg' => $msg,
        ]);

        $row = User::query()->where('id', $user->id)->first(['passhash', 'secret', 'editsecret', 'status']);
        $psecret = md5($row->secret);
        $ip = getip();
        $baseUrl = getSchemeAndHttpHost();
        $confirmUrl = $baseUrl . "/confirm.php?id={$user->id}&secret=$psecret";
        $confirmResendUrl = $baseUrl . "/confirm_resend.php";
        $reportMail = get_setting('main.reportemail');
        $mailTwo = sprintf($langSignup['mail_two'], $siteName);
        $mailFive = sprintf($langSignup['mail_five'], $siteName, $siteName, $reportMail, $siteName);
        $body = <<<EOD
{$langSignup['mail_one']}{$userInfo->username}{$mailTwo}($email){$langSignup['mail_three']}$ip{$langSignup['mail_four']}
<b><a href="javascript:void(null)" onclick="window.open($confirmUrl)">
{$langSignup['mail_this_link']} </a></b><br />
$confirmUrl
{$langSignup['mail_four_1']}
<b><a href="javascript:void(null)" onclick="window.open($confirmResendUrl)">{$langSignup['mail_here']}</a></b><br />
$confirmResendUrl
<br />
{$mailFive}
EOD;

        if ($type == 'invite' && $inv && $inviter) {
            \App\Models\Invite::query()->where('id', $inv->id)->update([
                'valid' => \App\Models\Invite::VALID_NO,
                'invitee_register_uid' => $user->id,
                'invitee_register_email' => $email,
                'invitee_register_username' => $wantusername,
            ]);

            $locale = get_user_locale($inviter);
            $inviteSubject = nexus_trans("user.msg_invited_user_has_registered", [], $locale);
            $inviteMsg = nexus_trans("user.msg_user_you_invited", [], $locale) . $wantusername . nexus_trans("user.msg_has_registered", [], $locale);
            \App\Models\Message::add([
                'sender' => 0,
                'receiver' => $inviter,
                'subject' => $inviteSubject,
                'added' => date("Y-m-d H:i:s"),
                'msg' => $inviteMsg,
            ]);
        }

        $smtpType = get_setting('smtp.smtptype');
        if ($verification == 'admin') {
            if ($type == 'invite') {
                return redirect('/ok.php?type=inviter');
            }
            return redirect('/ok.php?type=adminactivate');
        }
        if ($verification == 'automatic' || $smtpType == 'none') {
            return redirect("/confirm.php?id={$user->id}&secret=$psecret");
        }
        sent_mail($email, $siteName, get_setting('main.SITEEMAIL'), $subject, $body, "signup", false, false, '');
        return redirect('/ok.php?type=signup&email=' . rawurlencode($email));
    }

    public function showRecoverForm(Request $request)
    {
        $this->loginAttemptRepository->checkAndThrow('Recover');
        $switched = $this->switchSiteLanguage($request);
        if ($switched) {
            return $switched;
        }
        return view('auth/recover', ['request' => $request]);
    }

    public function recover(Request $request)
    {
        $langRecover = get_legacy_lang_file('recover');
        $baseUrl = getSchemeAndHttpHost();
        $siteName = Setting::getSiteName();

        if ($request->has('id') && $request->has('secret')) {
            return $this->recoverReset($request, $langRecover, $siteName, $baseUrl);
        }

        try {
            $this->loginAttemptRepository->checkAndThrow('Recover');
            verify_captcha(
                ['imagehash' => $request->input('imagehash'), 'imagestring' => $request->input('imagestring')],
                'recover.php',
                true
            );
        } catch (NexusException $exception) {
            $this->loginAttemptRepository->recordFailure('recover', true);
            return back()->with('error', $exception->getMessage());
        }

        $email = trim($request->input('email', ''));
        $email = safe_email($email);
        if (!$email) {
            $this->loginAttemptRepository->recordFailure('recover', true);
            return back()->with('error', $langRecover['std_missing_email_address']);
        }
        if (!check_email($email)) {
            $this->loginAttemptRepository->recordFailure('recover', true);
            return back()->with('error', $langRecover['std_invalid_email_address']);
        }
        $user = User::query()->whereRaw('BINARY email = ?', [$email])->first();
        if (!$user) {
            $this->loginAttemptRepository->recordFailure('recover', true);
            return back()->with('error', $langRecover['std_email_not_in_database']);
        }
        if ($user->status == User::STATUS_PENDING) {
            $this->loginAttemptRepository->recordFailure('recover', true);
            return back()->with('error', $langRecover['std_user_account_unconfirmed']);
        }

        $sec = mksecret();
        User::query()->where('id', $user->id)->update(['editsecret' => $sec]);
        $hash = md5($sec . $email . $user->passhash . $sec);
        do_log("hash: $hash = md5(sec: $sec . email: $email . passhash: {$user->passhash} . sec: $sec)");
        $ip = getip();
        $title = $siteName . $langRecover['mail_title'];
        $mailOne = sprintf($langRecover['mail_one'], $siteName);
        $mailFour = sprintf($langRecover['mail_four'], $siteName);
        NexusDB::cache_put("recover:$hash", now()->toDateTimeString());

        $body = <<<EOD
{$mailOne}($email){$langRecover['mail_two']}$ip{$langRecover['mail_three']}
<b><a href="$baseUrl/recover.php?id={$user->id}&secret=$hash" target="_blank"> {$langRecover['mail_this_link']} </a></b><br />
$baseUrl/recover.php?id={$user->id}&secret=$hash
{$mailFour}
EOD;

        sent_mail($email, $siteName, get_setting('main.SITEEMAIL'), $title, $body, "confirmation", true, false, '');
        return back()->with('notice', nexus_trans('functions.std_confirmation_email_sent') . $email);
    }

    private function recoverReset(Request $request, array $langRecover, string $siteName, string $baseUrl)
    {
        $id = (int) $request->query('id', 0);
        $md5 = $request->query('secret', '');
        if (!$id) {
            abort(404);
        }
        if (!NexusDB::cache_get("recover:$md5")) {
            do_log("secret: $md5 is expired", "error");
            abort(404);
        }
        $user = User::query()->where('id', $id)->first(['username', 'email', 'passhash', 'editsecret']);
        if (!$user) {
            abort(404);
        }
        $email = $user->email;
        $sec = hash_pad($user->editsecret);
        if ($md5 != md5($sec . $email . $user->passhash . $sec)) {
            do_log("secret: $md5 != md5(sec: $sec . email: $email . passhash: {$user->passhash} . sec: $sec)", "error");
            abort(404);
        }

        $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
        $newpassword = "";
        for ($i = 0; $i < 10; $i++) {
            $newpassword .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        $sec = mksecret();
        $newpasshash = hash('sha256', $sec . hash('sha256', $newpassword));
        $authKey = mksecret();

        $affected = User::query()
            ->where('id', $id)
            ->where('editsecret', $user->editsecret)
            ->update([
                'secret' => $sec,
                'editsecret' => '',
                'passhash' => $newpasshash,
                'auth_key' => $authKey,
            ]);
        if (!$affected) {
            return back()->with('error', $langRecover['std_unable_updating_user_data']);
        }
        $title = $siteName . $langRecover['mail_two_title'];
        $mailTwoFour = sprintf($langRecover['mail_two_four'], $siteName);
        $body = <<<EOD
{$langRecover['mail_two_one']}{$user->username}
{$langRecover['mail_two_two']}$newpassword
{$langRecover['mail_two_three']}
<b><a href="$baseUrl/login.php">{$langRecover['mail_here']}</a></b>
{$mailTwoFour}
EOD;
        sent_mail($email, $siteName, get_setting('main.SITEEMAIL'), $title, $body, "details", true, false, '');
        return redirect('/login.php');
    }

    public function showResetForm(Request $request)
    {
        if (Auth::check() && Auth::user()->class < User::CLASS_ADMINISTRATOR) {
            abort(403, nexus_trans('label.permission_denied'));
        }
        return view('auth/reset');
    }

    public function reset(Request $request)
    {
        if (Auth::check() && Auth::user()->class < User::CLASS_ADMINISTRATOR) {
            abort(403, nexus_trans('label.permission_denied'));
        }
        $username = trim($request->input('username', ''));
        $newpassword = trim($request->input('newpassword', ''));
        $newpasswordagain = trim($request->input('newpasswordagain', ''));

        if (empty($username) || empty($newpassword) || empty($newpasswordagain)) {
            return back()->with('error', 'Don\'t leave any fields blank.');
        }
        if ($newpassword != $newpasswordagain) {
            return back()->with('error', 'The passwords didn\'t match! Must\'ve typoed. Try again.');
        }
        if (strlen($newpassword) < 6) {
            return back()->with('error', 'Sorry, password is too short (min is 6 chars)');
        }
        $target = User::query()->where('username', $username)->first(['id', 'username', 'class']);
        if (!$target) {
            return back()->with('error', 'Sorry, that username doesn\'t exist.');
        }
        if (Auth::check() && Auth::user()->class <= $target->class) {
            $log = "Password Reset For $username by " . Auth::user()->username . " denied: operator class => " . Auth::user()->class . " is not greater than target user => {$target->class}";
            write_log($log);
            do_log($log, 'alert');
            return back()->with('error', 'Sorry, you don\'t have enough permission to reset this user\'s password.');
        }
        $userRep = new UserRepository();
        try {
            $userRep->resetPassword($target->id, $newpassword, $newpasswordagain);
        } catch (\Exception $exception) {
            return back()->with('error', $exception->getMessage());
        }
        write_log("Password Reset For $username by " . (Auth::check() ? Auth::user()->username : ''));
        return back()->with('notice', "The password of account <b>$username</b> is reset, please inform user of this change.");
    }

    private function loginFailedRedirect(string $heading, string $message)
    {
        if ($message === '') {
            $message = $heading;
        }
        return redirect()->route('nexus.login')->withErrors(['login' => $message])->withInput();
    }

    private function signupBarkRedirect(array $langSignup, string $message): \Illuminate\Http\RedirectResponse
    {
        return redirect()->route('nexus.signup')->with('error', $message);
    }

    private function switchSiteLanguage(Request $request): ?RedirectResponse
    {
        $langid = intval($request->query('sitelanguage', 0));
        if (!$langid) {
            return null;
        }
        $langFolder = validlang($langid);
        $enabled = Language::listEnabled();
        if (!in_array($langFolder, $enabled)) {
            return redirect(getBaseUrl());
        }
        if (get_langfolder_cookie() != $langFolder) {
            set_langfolder_cookie($langFolder);
            return redirect($request->fullUrl());
        }
        return null;
    }

    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required',
            'password' => 'required',
        ]);
        $result = $this->repository->login($request->username, $request->password);
        $includes = explode(',', $request->get('include', ''));
        if (in_array('site_info', $includes)) {
            $basic = Setting::get('basic');
            $result['site_info'] = [
                'site_name' => $basic['SITENAME'],
            ];
        }
        return $this->success($result);
    }

    public function logout(Request $request)
    {
        $result = $this->repository->logout(Auth::id());
        return $this->success($result);
    }

    public function passkeyLogin($passkey)
    {
        $deadline = Setting::get('security.login_secret_deadline');
        if ($deadline && $deadline > now()->toDateTimeString()) {
            $user = User::query()->where('passkey', $passkey)->first(['id', 'passhash', 'secret', 'auth_key']);
            if ($user) {
                $ip = getip();
                logincookie($user->id, $user->auth_key);
                $user->last_login = now();
                $user->save();
                $userRep = new UserRepository();
                $userRep->saveLoginLog($user->id, $ip, 'Passkey', false);
            }
        }
        return redirect('index.php');
    }

    public function nasToolsApprove(Request $request)
    {
        $request->validate([
            'data' => 'required|string'
        ]);
        try {
            $user = $this->repository->nasToolsApprove($request->data);
            $resource = new UserResource($user);
            //temporarily compatible
            return $this->success($this->polyfillArray($resource, $request), "Please use data.data");
        } catch (\Exception $exception) {
            $msg = $exception->getMessage();
            $params = $request->all();
            do_log(sprintf("nasToolsApprove fail: %s, params: %s", $msg, nexus_json_encode($params)));
            return $this->fail($params, $msg);
        }
    }

    private function polyfillArray(JsonResource $resource, Request $request)
    {
        $data = $resource->response($request)->getData(true)['data'];
        $result = $data;
        $result['data'] = $data;
        return $result;
    }

    public function iyuuApprove(Request $request)
    {
        try {
            $request->validate([
                'token' => 'required|string',
                'id' => 'required|integer',
                'verity' => 'required|string',
                'provider' => ["required", "string", Rule::in("iyuu")],
            ]);
            $this->repository->iyuuApprove($request->token, $request->id, $request->verity);
            return response()->json(["success" => true]);
        } catch (\Exception $exception) {
            return response()->json(["success" => false, "msg" => $exception->getMessage()]);
        }
    }

    public function ammdsApprove(Request $request)
    {
        try {
            $request->validate([
                'uid' => 'required|integer',
                'timestamp' => 'required|integer',
                'nonce' => 'required|string',
                'signature' => 'required|string',
            ]);
            $user = $this->repository->ammdsApprove($request);
            $resource = new UserResource($user);
            //temporarily compatible
            return $this->success($this->polyfillArray($resource, $request), "Please use data.data");
        } catch (\Exception $exception) {
            $msg = $exception->getMessage();
            $params = $request->all();
            do_log(sprintf("ammdsApprove fail: %s, params: %s", $msg, nexus_json_encode($params)));
            return $this->fail($params, $msg);
        }
    }

    public function challenge(Request $request)
    {
        try {
            $request->validate([
                'username' => 'required|string',
            ]);
            $username = $request->username;
            $challenge = mksecret();
            NexusDB::cache_put(get_challenge_key($username), $challenge,300);
            $user = User::query()->where("username", $username)->first(['secret']);
            return $this->success([
                "challenge" => $challenge,
                'secret' => $user->secret ?? mksecret(),
            ]);
        } catch (\Exception $exception) {
            $msg = $exception->getMessage();
            $params = $request->all();
            do_log(sprintf("challenge fail: %s, params: %s", $msg, nexus_json_encode($params)));
            return $this->fail($params, $msg);
        }
    }





}
