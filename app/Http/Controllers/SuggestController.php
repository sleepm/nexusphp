<?php

namespace App\Http\Controllers;

use App\Models\Suggest;
use Illuminate\Http\Request;

class SuggestController extends Controller
{
    public function web(Request $request)
    {
        $response = response('')
            ->header('Content-Type', 'text/xml; charset=utf-8')
            ->header('Expires', 'Mon, 26 Jul 1997 05:00:00 GMT')
            ->header('Last-Modified', gmdate('D, d M Y H:i:s') . 'GMT')
            ->header('Cache-Control', 'no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');

        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return $response;
        }

        $rows = Suggest::query()
            ->selectRaw('keywords AS suggest, COUNT(*) AS count')
            ->where('keywords', 'like', $q . '%')
            ->groupBy('keywords')
            ->orderByDesc('count')
            ->orderByDesc('keywords')
            ->limit(10)
            ->get();

        $result = '';
        $i = 0;
        foreach ($rows as $row) {
            if (strlen($row->suggest) > 25) {
                continue;
            }
            $result .= ($result === '' ? '' : "\r\n") . $row->suggest . "\r\n" . $row->count;
            $i++;
            if ($i >= 5) {
                break;
            }
        }

        return $response->setContent($result);
    }
}