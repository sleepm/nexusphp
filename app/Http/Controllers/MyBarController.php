<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\RendersUserBar;
use Illuminate\Http\Request;

class MyBarController extends Controller
{
    use RendersUserBar;

    /**
     * Personal userbar image. Mirrors legacy public/mybar.php: a PNG generated
     * from public/pic/userbar/ with the owner's username / upload / download
     * drawn over it. The URL mirrors the legacy format
     * /mybar.php?userid=ID.png (optionally ?bgpic=, colour/size/position and
     * noname/noup/nodown overrides). Rendered output is cached for 300s.
     */
    public function web(Request $request)
    {
        $useridParam = (string) $request->query('userid', '');
        if (! preg_match('/^([0-9]+)\.png$/', $useridParam, $m)) {
            abort(404);
        }
        $userId = (int) $m[1];
        $bgpic = (int) $request->query('bgpic', 0);
        if ($userId <= 0) {
            abort(404);
        }

        $cache = $GLOBALS['Cache'] ?? null;
        $cacheKey = 'userbar_' . $request->getRequestUri();
        $cached = $cache ? $cache->get_value($cacheKey) : null;
        if ($cached === false || $cached === null) {
            $segments = [
                'name' => [
                    'draw' => empty($request->query('noname')),
                    'red' => $this->boundParam($request->query('namered'), 0, 255, 255),
                    'green' => $this->boundParam($request->query('namegreen'), 0, 255, 255),
                    'blue' => $this->boundParam($request->query('nameblue'), 0, 255, 255),
                    'size' => $this->boundParam($request->query('namesize'), 1, 5, 3),
                    'x' => $this->boundParam($request->query('namex'), 0, 350, 10),
                    'y' => $this->boundParam($request->query('namey'), 0, 19, 3),
                ],
                'upload' => [
                    'draw' => empty($request->query('noup')),
                    'red' => $this->boundParam($request->query('upred'), 0, 255, 0),
                    'green' => $this->boundParam($request->query('upgreen'), 0, 255, 255),
                    'blue' => $this->boundParam($request->query('upblue'), 0, 255, 0),
                    'size' => $this->boundParam($request->query('upsize'), 1, 5, 3),
                    'x' => $this->boundParam($request->query('upx'), 0, 350, 100),
                    'y' => $this->boundParam($request->query('upy'), 0, 19, 3),
                ],
                'download' => [
                    'draw' => empty($request->query('nodown')),
                    'red' => $this->boundParam($request->query('downred'), 0, 255, 255),
                    'green' => $this->boundParam($request->query('downgreen'), 0, 255, 0),
                    'blue' => $this->boundParam($request->query('downblue'), 0, 255, 0),
                    'size' => $this->boundParam($request->query('downsize'), 1, 5, 3),
                    'x' => $this->boundParam($request->query('downx'), 0, 350, 180),
                    'y' => $this->boundParam($request->query('downy'), 0, 19, 3),
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