<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Cache clearing page — replaces legacy public/clearcache.php.
 *
 * GET /clearcache.php renders a small form (cache name + "multi languages"
 * checkbox); POSTing it removes the given key from the legacy Nexus cache
 * ($GLOBALS['Cache'], the class_cache_redis instance) and from the Laravel
 * cache store, then reports "Cache cleared". Requires at least the moderator
 * class.
 */
class ClearCacheController extends Controller
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
            abort(403, 'Permission denied.');
        }

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $done = false;
        if ($request->isMethod('POST')) {
            $cachename = trim((string) $request->input('cachename', ''));
            if ($cachename === '') {
                $content = $this->capture(function () {
                    stderr('Error', 'You must fill in cache name.', true, false, false, false);
                });

                return view('clearcache', compact('content') + [
                    'pageTitle' => 'Clear cache',
                ]);
            }

            /** @var \class_cache_redis|\class_cache $cache */
            $cache = $GLOBALS['Cache'] ?? null;
            if ($cache && method_exists($cache, 'delete_value')) {
                $cache->delete_value($cachename, $request->input('multilang') === 'yes');
            }
            Cache::forget($cachename);
            $done = true;
        }

        $content = $this->capture(function () use ($done) {
            print('<h1>Clear cache</h1>');
            if ($done) {
                print('<p align=center><font class=striking>Cache cleared</font></p>');
            }
            print('<form method=post action=clearcache.php>');
            print('<table border=1 cellspacing=0 cellpadding=5>');
            print('<tr><td class=rowhead>Cache name</td><td><input type=text name=cachename size=40></td></tr>');
            print('<tr><td class=rowhead>Multi languages</td><td><input type=checkbox name=multilang>Yes</td></tr>');
            print('<tr><td colspan=2 align=center><input type=submit value="Okay" class=btn></td></tr>');
            print('</table>');
            print('</form>');
        });

        return view('clearcache', compact('content') + [
            'pageTitle' => 'Clear cache',
        ]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}
