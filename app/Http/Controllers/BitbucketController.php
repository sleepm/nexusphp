<?php

namespace App\Http\Controllers;

use App\Models\Bitbucket;
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

    /**
     * BitBucket avatar upload. Mirrors legacy public/bitbucket-upload.php so the
     * usercp "upload avatar" link keeps working under the Laravel router: a
     * logged-in (non-parked) user may upload a gif/jpg/png (max 256 KiB) which
     * is scaled down to the avatar bounds, stored in the bitbucket directory,
     * registered in the bitbucket table and set as the user's avatar.
     *
     * GET /bitbucket-upload.php renders the upload form; POST validates and
     * stores the image, then shows the resulting URL / success notice.
     */
    public function webUpload(Request $request)
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        if (! $currentUser) {
            return redirect()->guest('/login.php');
        }
        $curUser = $currentUser->toArray();
        if (($curUser['parked'] ?? '') == 'yes') {
            abort(403, 'Your account is parked.');
        }
        if (get_setting('main.enablebitbucket', 'no') != 'yes') {
            $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
            return $this->messagePage(
                $GLOBALS['lang_functions']['std_error'] ?? 'Error',
                $GLOBALS['lang_functions']['std_permission_denied'] ?? 'Permission denied.'
            );
        }
        $lang = $this->uploadLang();

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        if ($request->isMethod('POST')) {
            $result = $this->handleUpload($request, $curUser, $lang);
            if ($result !== null) {
                return $result;
            }
        }

        $bitbucket = (string) get_setting('main.bitbucket', 'bitbucket');
        $maxfilesize = 256 * 1024;

        return view('bitbucket-upload', [
            'pageTitle' => $lang['head_avatar_upload'] ?? 'AVATAR Upload',
            'lang' => $lang,
            'maxfilesize' => $maxfilesize,
            'scaleh' => 200,
            'scalew' => 150,
            'uploadDirWritable' => is_writable(ROOT_PATH . $bitbucket),
        ]);
    }

    /**
     * Validate and store the uploaded avatar image.
     *
     * @return \Illuminate\Contracts\View\View|null the rendered notice on
     *                                               success/error, null when
     *                                               nothing was posted
     */
    private function handleUpload(Request $request, array $curUser, array $lang)
    {
        $maxfilesize = 256 * 1024;
        $imgtypes = [null, 'gif', 'jpg', 'png'];
        $scaleh = 200; // desired height
        $scalew = 150; // desired width

        $file = $request->file('file');
        $filesize = $file ? (int) $file->getSize() : 0;
        $filename = $file ? (string) $file->getClientOriginalName() : '';

        if (! $file || $filesize < 1) {
            return $this->messagePage($lang['std_upload_failed'], $lang['std_nothing_received']);
        }
        if ($filesize > $maxfilesize) {
            return $this->messagePage($lang['std_upload_failed'], $lang['std_file_too_large']);
        }
        $pp = pathinfo($filename);
        if (($pp['basename'] ?? '') != $filename) {
            return $this->messagePage($lang['std_upload_failed'], $lang['std_bad_file_name']);
        }
        $bitbucket = (string) get_setting('main.bitbucket', 'bitbucket');
        $tgtfile = getFullDirectory("$bitbucket/$filename");
        if (file_exists($tgtfile)) {
            return $this->messagePage(
                $lang['std_upload_failed'],
                $lang['std_file_with_the_name'] . htmlspecialchars($filename) . $lang['std_already_exists']
            );
        }

        $size = @getimagesize($file->getPathname());
        if (! $size) {
            return $this->messagePage($lang['std_error'], $lang['std_invalid_image_format']);
        }
        $width = $size[0];
        $height = $size[1];
        $it = $size[2];
        if (! isset($imgtypes[$it]) || $imgtypes[$it] != strtolower((string) ($pp['extension'] ?? ''))) {
            return $this->messagePage($lang['std_error'], $lang['std_invalid_image_format']);
        }

        // Scale image to appropriate avatar dimensions
        $hscale = $height / $scaleh;
        $wscale = $width / $scalew;
        $scale = ($hscale < 1 && $wscale < 1) ? 1 : (($hscale > $wscale) ? $hscale : $wscale);
        $newwidth = floor($width / $scale);
        $newheight = floor($height / $scale);

        if ($it == 1) {
            $orig = @imagecreatefromgif($file->getPathname());
        } elseif ($it == 2) {
            $orig = @imagecreatefromjpeg($file->getPathname());
        } else {
            $orig = @imagecreatefrompng($file->getPathname());
        }
        if (! $orig) {
            return $this->messagePage(
                $lang['std_image_processing_failed'],
                $lang['std_sorry_the_uploaded'] . $imgtypes[$it] . $lang['std_failed_processing']
            );
        }
        $thumb = imagecreatetruecolor($newwidth, $newheight);
        imagecopyresampled($thumb, $orig, 0, 0, 0, 0, $newwidth, $newheight, $width, $height);
        switch ($it) {
            case 1:
                $ret = imagegif($thumb, $tgtfile);
                break;
            case 2:
                $ret = imagejpeg($thumb, $tgtfile);
                break;
            default:
                $ret = imagepng($thumb, $tgtfile);
        }
        imagedestroy($orig);
        imagedestroy($thumb);
        if (! $ret) {
            return $this->messagePage(
                $lang['std_image_processing_failed'],
                $lang['std_sorry_the_uploaded'] . $imgtypes[$it] . $lang['std_failed_processing']
            );
        }

        $url = str_replace(' ', '%20', htmlspecialchars(get_protocol_prefix() . $GLOBALS['BASEURL'] . "/bitbucket/$filename"));
        $public = ($request->input('public') == 'yes') ? '1' : '0';

        Bitbucket::query()->create([
            'owner' => $curUser['id'],
            'name' => $filename,
            'added' => now(),
            'public' => $public,
        ]);
        User::query()->where('id', $curUser['id'])->update(['avatar' => $url]);

        $rescaleText = $scale != 1
            ? $lang['std_rescaled_from'] . "$height x $width" . $lang['std_to'] . "$newheight x $newwidth"
            : $lang['std_need_not_rescaling'];

        $message = $lang['std_use_following_url']
            . "<br /><b><a href=\"$url\">$url</a></b><p><a href=bitbucket-upload.php>"
            . $lang['std_upload_another_file']
            . "</a>.<br /><br /><img src=\"$url\" border=0><br /><br />"
            . $lang['std_image'] . $rescaleText
            . $lang['std_profile_updated'];

        return $this->messagePage($lang['std_success'], $message);
    }

    /**
     * Load the legacy bitbucket-upload language file. The file path uses a
     * hyphen (lang_bitbucket-upload.php) while the array variable it defines
     * uses an underscore ($lang_bitbucketupload), so get_legacy_lang_file()
     * cannot resolve it; load it directly instead.
     */
    private function uploadLang(): array
    {
        $folder = get_langfolder_cookie();
        $file = ROOT_PATH . 'lang/' . $folder . '/lang_bitbucket-upload.php';
        if (! is_file($file)) {
            $file = ROOT_PATH . 'lang/en/lang_bitbucket-upload.php';
        }
        require $file;
        return $lang_bitbucketupload ?? [];
    }

    /**
     * Full-page message (mirrors the legacy stderr() box) rendered through
     * Blade. The message may contain HTML (links/images) and is kept raw.
     */
    private function messagePage(string $heading, string $message)
    {
        return view('error.notification', [
            'pageTitle' => htmlspecialchars($heading),
            'heading' => htmlspecialchars($heading),
            'message' => $message,
        ]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}
