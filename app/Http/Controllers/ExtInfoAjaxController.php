<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Nexus\Database\NexusDB;

/**
 * IMDb info tooltip AJAX fragment, replaces legacy public/getextinfoajax.php
 * (Phase 2 P3).
 *
 * Loaded synchronously via ajax.gets() from the torrent list tooltips; returns
 * the rendered IMDb block as a text/xml fragment. No authentication required,
 * output is cached per imdb id + display mode.
 */
class ExtInfoAjaxController extends Controller
{
    public function show(Request $request)
    {
        $imdbLink = trim((string) $request->get('url', ''));
        $mode = (string) $request->get('type', 'minor');
        if (!in_array($mode, ['minor', 'median'], true)) {
            $mode = 'minor';
        }
        $cacheStamp = trim((string) $request->get('cache', ''));

        $infoblock = '';
        $imdbId = parse_imdb_id($imdbLink);
        if ($imdbId) {
            $GLOBALS['lang_functions'] = $GLOBALS['lang_functions'] ?? get_legacy_lang_file('functions');
            $cacheKey = 'nexus_ext_info:' . $imdbId . ':' . $mode;
            $infoblock = NexusDB::cache_get($cacheKey);
            if ($infoblock === false || $infoblock === null) {
                $infoblock = getimdb($imdbId, $cacheStamp, $mode);
                if ($infoblock) {
                    NexusDB::cache_put($cacheKey, $infoblock, 86400);
                }
            }
        }

        return response($infoblock)
            ->header('Content-Type', 'text/xml; charset=utf-8')
            ->header('Cache-Control', 'no-cache, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', 'Mon, 26 Jul 1997 05:00:00 GMT');
    }
}