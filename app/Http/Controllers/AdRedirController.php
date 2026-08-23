<?php

namespace App\Http\Controllers;

use App\Models\AdClick;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdRedirController extends Controller
{
    public function web(Request $request)
    {
        $lang = get_legacy_lang_file('adredir');
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();

        /** @var User|null $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        if (!$currentUser) {
            return redirect()->guest('/login.php');
        }
        if ($currentUser->parked == 'yes') {
            abort(403);
        }
        $curUserId = $currentUser->id;

        $enablead = get_setting('advertisement.enablead', 'no');
        if ($enablead != 'yes') {
            abort(403, $lang['std_ad_system_disabled'] ?? 'Ad system disabled.');
        }

        $id = (int) ($request->query('id', 0));
        if (!$id) {
            abort(400, $lang['std_invalid_ad_id'] ?? 'Invalid ad id');
        }

        $redir = htmlspecialchars_decode(urldecode((string) $request->query('url', '')));
        if (!$redir) {
            abort(400, $lang['std_no_redirect_url'] ?? 'No redirect URL.');
        }

        $adCount = DB::table('advertisements')->where('id', $id)->count();
        if (!$adCount) {
            abort(400, $lang['std_invalid_ad_id'] ?? 'Invalid ad id');
        }

        $adclickbonus = (float) get_setting('advertisement.adclickbonus', 0);
        if ($adclickbonus > 0 && $curUserId) {
            $clickCount = DB::table('adclicks')
                ->where('adid', $id)
                ->where('userid', $curUserId)
                ->count();
            if (!$clickCount) {
                $bonusTweak = (string) get_setting('tweak.bonus', 'enable');
                if (in_array($bonusTweak, ['enable', 'disablesave'], true)) {
                    DB::table('users')->where('id', $curUserId)->increment('seedbonus', $adclickbonus);
                }
            }
        }

        AdClick::query()->create([
            'adid' => $id,
            'userid' => $curUserId,
            'added' => now(),
        ]);

        return redirect($redir);
    }
}