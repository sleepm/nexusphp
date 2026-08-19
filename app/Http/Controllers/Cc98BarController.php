<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\RendersUserBar;
use Illuminate\Http\Request;

class Cc98BarController extends Controller
{
    use RendersUserBar;

    /**
     * External-forum userbar. Mirrors legacy public/cc98bar.php which encodes
     * every option into the request path, e.g.
     * /cc98bar.php/nn1nr255ng255nb255ns3nx10ny3nn...id10001.png
     * (nn/nu/nd toggle name/upload/download, nr..ny / ur..uy / dr..dy are the
     * per-segment colour-size-position overrides, bg picks the background).
     */
    private const PATTERN = "/.*cc98bar\.php\/(nn([0,1]{1}))?(nr([0-9]+))?(ng([0-9]+))?(nb([0-9]+))?(ns([1-5]{1}))?(nx([0-9]+))?(ny([0-9]+))?(nu([0,1]{1}))?(ur([0-9]+))?(ug([0-9]+))?(ub([0-9]+))?(us([1-5]{1}))?(ux([0-9]+))?(uy([0-9]+))?(nd([0,1]{1}))?(dr([0-9]+))?(dg([0-9]+))?(db([0-9]+))?(ds([1-5]{1}))?(dx([0-9]+))?(dy([0-9]+))?(bg([0-9]+))?id([0-9]+)\.png$/i";

    public function web(Request $request, ?string $tail = null)
    {
        if ($tail === null) {
            abort(404);
        }
        $uri = 'cc98bar.php/' . $tail;
        if (! preg_match(self::PATTERN, $uri, $m)) {
            abort(404);
        }

        $userId = (int) $m[45];
        $bgpic = ($m[44] ?? '') !== '' ? (int) $m[44] : 0;

        $cache = $GLOBALS['Cache'] ?? null;
        $cacheKey = 'userbar_' . $request->getRequestUri();
        $cached = $cache ? $cache->get_value($cacheKey) : null;
        if ($cached === false || $cached === null) {
            $segments = [
                'name' => [
                    'draw' => empty($m[2] ?? ''),
                    'red' => $this->boundParam($m[4] ?? '', 0, 255, 255),
                    'green' => $this->boundParam($m[6] ?? '', 0, 255, 255),
                    'blue' => $this->boundParam($m[8] ?? '', 0, 255, 255),
                    'size' => $this->boundParam($m[10] ?? '', 1, 5, 3),
                    'x' => $this->boundParam($m[12] ?? '', 0, 350, 10),
                    'y' => $this->boundParam($m[14] ?? '', 0, 19, 3),
                ],
                'upload' => [
                    'draw' => empty($m[16] ?? ''),
                    'red' => $this->boundParam($m[18] ?? '', 0, 255, 0),
                    'green' => $this->boundParam($m[20] ?? '', 0, 255, 255),
                    'blue' => $this->boundParam($m[22] ?? '', 0, 255, 0),
                    'size' => $this->boundParam($m[24] ?? '', 1, 5, 3),
                    'x' => $this->boundParam($m[26] ?? '', 0, 350, 100),
                    'y' => $this->boundParam($m[28] ?? '', 0, 19, 3),
                ],
                'download' => [
                    'draw' => empty($m[30] ?? ''),
                    'red' => $this->boundParam($m[32] ?? '', 0, 255, 255),
                    'green' => $this->boundParam($m[34] ?? '', 0, 255, 0),
                    'blue' => $this->boundParam($m[36] ?? '', 0, 255, 0),
                    'size' => $this->boundParam($m[38] ?? '', 1, 5, 3),
                    'x' => $this->boundParam($m[40] ?? '', 0, 350, 180),
                    'y' => $this->boundParam($m[42] ?? '', 0, 19, 3),
                ],
            ];
            $png = $this->renderUserBar($userId, $bgpic, $segments);
            if ($png === null) {
                abort(404);
            }
            $cached = gzdeflate($png);
            if ($cache) {
                $cache->cache_value($cacheKey, $cached, 300);
            }
        }

        return response(gzinflate($cached), 200, ['Content-Type' => 'image/png']);
    }
}