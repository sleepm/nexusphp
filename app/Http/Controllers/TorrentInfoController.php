<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Torrent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Rhilip\Bencode\Bencode;

class TorrentInfoController extends Controller
{
    public function web(Request $request)
    {
        $currentUser = Auth::guard('nexus')->user();
        if (! $currentUser) {
            abort(401);
        }
        $curUser = $currentUser->toArray();
        if (($curUser['parked'] ?? '') == 'yes') {
            abort(403, 'Your account is parked.');
        }

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        if (! user_can('torrentstructure', false, $currentUser->id)) {
            $langFunctions = $GLOBALS['lang_functions'];
            return view('error.notification', [
                'pageTitle' => $langFunctions['std_sorry'] ?? 'Sorry',
                'heading' => $langFunctions['std_sorry'] ?? 'Sorry',
                'message' => $langFunctions['std_permission_denied'] ?? 'Permission denied.',
            ]);
        }

        $id = (int) $request->query('id', 0);
        if ($id <= 0) {
            abort(404);
        }

        $torrent = Torrent::query()->find($id, ['id', 'name']);
        if (! $torrent) {
            abort(404);
        }

        $torrentDir = get_setting('main.torrent_dir');
        $fn = getFullDirectory("$torrentDir/$id.torrent");
        if (! is_file($fn) || ! is_readable($fn)) {
            abort(404);
        }

        $dict = Bencode::load($fn);
        $structure = $this->torrentStructureBuilder(['root' => $dict]);

        return view('torrent_info', [
            'pageTitle' => 'Torrent Info',
            'torrentName' => $torrent->name,
            'structure' => $structure,
        ]);
    }

    private function torrentStructureBuilder($array, $parent = "")
    {
        $ret = '';
        foreach ($array as $item => $value) {
            $valueLength = strlen(Bencode::encode($value));
            if (is_iterable($value)) {
                $type = $this->isIndexedArray($value) ? 'list' : 'dictionary';
                $ret .= "<li><div align='left' class='" . $type . "'><a href='javascript:void(0);' onclick='jQuery(this).parent().next(\"ul\").toggle()'> + <span class=title>[" . $item . "]</span> <span class='icon'>(" . ucfirst($type) . ")</span> <span class=length>[" . $valueLength . "]</span></a></div>";
                $ret .= "<ul style='display:none'>" . $this->torrentStructureBuilder($value, $item) . "</ul></li>";
            } else {
                $type = is_integer($value) ? 'integer' : 'string';
                $display = ($parent == 'info' && $item == 'pieces') ? "0x" . bin2hex(substr($value, 0, 25)) . "..." : $value;
                $ret .= "<li><div align=left class=" . $type . "> - <span class=title>[" . $item . "]</span> <span class=icon>(" . ucfirst($type) . ")</span> <span class=length>[" . $valueLength . "]</span>: <span class=value>" . $display . "</span></div></li>";
            }
        }
        return $ret;
    }

    private function isIndexedArray(array $arr): bool
    {
        if (is_array($arr)) {
            return count(array_filter(array_keys($arr), 'is_string')) === 0;
        }
        return false;
    }
}