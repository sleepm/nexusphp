<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * NFO viewer, replaces legacy public/viewnfo.php (Phase 2 P3).
 */
class ViewNfoController extends Controller
{
    public function show(Request $request)
    {
        $currentUser = Auth::user();
        if ($currentUser && $currentUser->parked == 'yes') {
            abort(403, 'Your account is parked.');
        }
        $id = (int) $request->get('id', 0);
        if (!user_can('viewnfo') || !is_valid_id($id) || get_setting('main.enablenfo') != 'yes') {
            abort(403);
        }
        $lang = get_legacy_lang_file('viewnfo');

        $row = DB::table('torrents')
            ->leftJoin('torrent_extras', 'torrents.id', '=', 'torrent_extras.torrent_id')
            ->where('torrents.id', $id)
            ->select(['torrents.name', 'torrent_extras.nfo'])
            ->first();
        if (!$row) {
            abort(404, $lang['std_puke'] ?? 'Puke');
        }

        $view = (string) $request->get('view', 'magic');
        if (!in_array($view, ['magic', 'latin-1', 'fonthack'], true)) {
            $view = 'magic';
        }
        $nfo = code_new($row->nfo ?? '', $view);

        return view('viewnfo', [
            'request' => $request,
            'lang' => $lang,
            'pageTitle' => $lang['head_view_nfo'],
            'torrentId' => $id,
            'torrentName' => $row->name,
            'view' => $view,
            'nfo' => $nfo,
        ]);
    }
}
