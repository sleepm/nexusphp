<?php

namespace App\Http\Controllers;

use App\Models\Suggest;
use Illuminate\Http\Request;

/**
 * Search suggestions JSON endpoint, replaces legacy public/searchsuggest.php
 * (Phase 2 P3).
 *
 * Consumed as the OpenSearch suggestions source and by the search box
 * autocomplete. Returns the legacy array format
 * [query, [keyword, ...], ["N times", ...]] grouped by keyword, most
 * searched first.
 */
class SearchSuggestController extends Controller
{
    public function index(Request $request)
    {
        $query = trim((string) $request->get('q', ''));
        if ($query === '') {
            return response()->json([$query, [], []]);
        }

        $rows = Suggest::query()
            ->selectRaw('keywords AS suggest, COUNT(*) AS count')
            ->where('keywords', 'like', $query . '%')
            ->groupBy('keywords')
            ->orderByDesc('count')
            ->orderByDesc('keywords')
            ->limit(10)
            ->get();

        $result = [htmlspecialchars($query), [], []];
        $i = 0;
        foreach ($rows as $row) {
            if (strlen($row->suggest) > 25) {
                continue;
            }
            $result[1][] = $row->suggest;
            $result[2][] = $row->count . ' times';
            $i++;
            if ($i >= 5) {
                break;
            }
        }

        return response()->json($result);
    }
}