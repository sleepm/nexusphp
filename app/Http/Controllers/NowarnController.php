<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NowarnController extends Controller
{
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
        if (get_user_class() < User::CLASS_MODERATOR) {
            abort(403, 'Access denied.');
        }

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        if ($request->input('nowarned') !== 'nowarned') {
            return redirect('warned.php');
        }

        $usernw = (array) $request->input('usernw', []);
        $desact = (array) $request->input('desact', []);
        $delete = (array) $request->input('delete', []);

        if (empty($usernw) && empty($desact) && empty($delete)) {
            $content = $this->capture(function () {
                stdmsg('Update Has Failed !', 'You Must Select A User To Edit.');
            });

            return view('nowarn', compact('content') + [
                'pageTitle' => 'Warned Users',
            ]);
        }

        if (! empty($usernw)) {
            $usernw = array_values(array_filter(array_map('intval', $usernw)));
            User::query()->whereIn('id', $usernw)->update([
                'warned' => 'no',
                'warneduntil' => null,
            ]);
        }

        if (! empty($desact)) {
            $desact = array_values(array_filter(array_map('intval', $desact)));
            User::query()->whereIn('id', $desact)->update([
                'enabled' => 'no',
            ]);
        }

        return redirect('warned.php');
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}