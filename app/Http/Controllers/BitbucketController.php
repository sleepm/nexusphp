<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BitbucketController extends Controller
{
    /**
     * BitBucket image log. Mirrors legacy public/bitbucketlog.php so the admin
     * sysoppanel link keeps working under the Laravel router: administrators
     * can review every uploaded attachment image and delete (row + file).
     *
     * GET /bitbucketlog.php renders the paginated image list; GET ?delete=ID
     * removes the matching bitbucket row and unlinks the stored file.
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
        if (get_user_class() < User::CLASS_ADMINISTRATOR) {
            abort(403, 'Access denied.');
        }

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $bitbucket = (string) get_setting('main.bitbucket', 'bitbucket');

        // delete action
        $delete = (int) $request->query('delete', 0);
        if (is_valid_id($delete)) {
            $row = DB::table('bitbucket')->where('id', $delete)->first(['name', 'owner']);
            if ($row && (get_user_class() >= User::CLASS_MODERATOR || $row->owner == $curUser['id'])) {
                DB::table('bitbucket')->where('id', $delete)->delete();
                $file = getFullDirectory("$bitbucket/{$row->name}");
                if (is_file($file) && ! @unlink($file)) {
                    do_log("unable to unlink file: $file", 'error');
                }
            }
        }

        $content = $this->capture(function () use ($bitbucket, $request) {
            $count = DB::table('bitbucket')->count();
            $perpage = 10;
            $href = url('bitbucketlog.php') . '?out=' . (string) $request->query('out', '') . '&';
            list($pagertop, $pagerbottom, $limit) = pager($perpage, $count, $href);
            preg_match('/limit (\d+) offset (\d+)/', $limit, $limitMatches);
            $rows = DB::table('bitbucket')
                ->orderByDesc('added')
                ->limit((int) ($limitMatches[1] ?? $perpage))
                ->offset((int) ($limitMatches[2] ?? 0))
                ->get();

            print "<h1>BitBucket Log</h1>\n";
            print "Total Images Stored: $count";
            echo $pagertop;

            if ($rows->isEmpty()) {
                print "<b>BitBucket Log is empty</b>\n";
            } else {
                print "<table align='center' border='0' cellspacing='0' cellpadding='5'>\n";
                foreach ($rows as $arr) {
                    $date = strpos($arr->added, ' ') !== false ? substr($arr->added, 0, strpos($arr->added, ' ')) : $arr->added;
                    $time = strpos($arr->added, ' ') !== false ? substr($arr->added, strpos($arr->added, ' ') + 1) : '';
                    $name = $arr->name;
                    list($width, $height) = @getimagesize(getFullDirectory("$bitbucket/$name")) ?: [0, 0];
                    $url = str_replace(' ', '%20', htmlspecialchars("$bitbucket/$name"));
                    print "<tr>";
                    print "<td><center><a href=$url><img src=\"$url\" border=0 onLoad='SetSize(this, 400)'></a></center>";
                    print 'Uploaded by:  ' . get_username($arr->owner) . "<br />";
                    print "(#{$arr->id}) Filename: $name ($width&nbsp;x&nbsp;$height)";
                    if (get_user_class() >= User::CLASS_MODERATOR) {
                        print " <b><a href=?delete={$arr->id}>[Delete]</a></b><br />";
                    }
                    print "Added: $date $time";
                    print "</tr>";
                }
                print "</table>";
            }
            echo $pagerbottom;
        });

        return view('bitbucketlog', compact('content') + [
            'pageTitle' => 'BitBucket Log',
        ]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}
