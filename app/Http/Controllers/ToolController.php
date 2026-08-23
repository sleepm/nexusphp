<?php

namespace App\Http\Controllers;

use App\Jobs\SettleClaim;
use App\Models\PluginStore;
use App\Models\Setting;
use App\Repositories\TokenRepository;
use App\Repositories\ToolRepository;
use App\Repositories\UploadRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Process\Process;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Telegram\Bot\Api;
use Telegram\Bot\Commands\HelpCommand;

class ToolController extends Controller
{
    private $repository;

    public function __construct(ToolRepository $repository)
    {
        $this->repository = $repository;
    }

    public function notifications(): array
    {
        $user = Auth::user();
        $result = $this->repository->getNotificationCount($user);
        return $this->success($result);
    }

    public function error(Request $request)
    {
        return view('error', ['error' => $request->query('error')]);
    }

    /**
     * Generic notification page. Mirrors legacy public/ok.php: a shared
     * "signup / confirmation / activation" notice rendered through the Blade
     * error/notification view. Open to guests (types like signup/confirm are
     * reached right after an unauthenticated signup), the current session is
     * only used to pick the auto-login vs cookies-disabled wording.
     */
    public function notification(Request $request)
    {
        $type = (string) $request->query('type', '');
        $lang = get_legacy_lang_file('ok');
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $currentUser = Auth::guard('nexus')->user();
        $loggedIn = !is_null($currentUser);

        switch ($type) {
            case 'adminactivate':
                return $this->notificationResponse(
                    $lang['head_user_signup'],
                    $lang['std_account_activated'],
                    $lang['account_activated_note'],
                    false
                );
            case 'inviter':
                return $this->notificationResponse(
                    $lang['head_user_signup'],
                    $lang['std_account_activated'],
                    $lang['account_activated_note_two'],
                    false
                );
            case 'signup':
                $email = (string) $request->query('email', '');
                if ($email === '') {
                    abort(404);
                }
                return $this->notificationResponse(
                    $lang['head_user_signup'],
                    $lang['std_signup_successful'],
                    $lang['std_confirmation_email_note'] . htmlspecialchars($email) . $lang['std_confirmation_email_note_end'],
                    false
                );
            case 'sysop':
                return $this->notificationResponse(
                    $lang['head_sysop_activation'],
                    $lang['head_sysop_activation'],
                    $lang['std_sysop_activation_note']
                        . ($loggedIn ? $lang['std_auto_logged_in_note'] : $lang['std_cookies_disabled_note']),
                    false
                );
            case 'confirmed':
                return $this->notificationResponse(
                    $lang['head_already_confirmed'],
                    $lang['head_already_confirmed'],
                    $lang['std_already_confirmed'] . $lang['std_already_confirmed_note'],
                    false
                );
            case 'confirm':
                return $this->notificationResponse(
                    $lang['head_signup_confirmation'],
                    $lang['head_signup_confirmation'],
                    $lang['std_account_confirmed']
                        . ($loggedIn
                            ? $lang['std_auto_logged_in_note'] . sprintf($lang['std_read_rules_faq'], Setting::getSiteName())
                            : $lang['std_cookies_disabled_note']),
                    false
                );
            default:
                abort(404);
        }
    }

    private function notificationResponse(string $pageTitle, string $heading, string $message, bool $htmlstrip)
    {
        if ($htmlstrip) {
            $heading = htmlspecialchars(trim($heading));
            $message = htmlspecialchars(trim($message));
        }

        return view('error.notification', compact('pageTitle', 'heading', 'message'));
    }

}
