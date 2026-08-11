<?php

namespace App\Http\Middleware;

use App\Repositories\IpLogRepository;
use Closure;
use Illuminate\Http\Request;
use Nexus\Nexus;

class BootNexus
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        Nexus::boot();
        if (!defined('TIMENOW')) {
            define('TIMENOW', time());
        }
        if (empty($GLOBALS['Cache'])) {
            require_once ROOT_PATH . 'classes/class_cache_redis.php';
            $GLOBALS['Cache'] = new \class_cache_redis();
        }
        if (empty($GLOBALS['defcss'])) {
            $GLOBALS['defcss'] = get_setting('main.defstylesheet', 3);
        }
        if (empty($GLOBALS['CURLANGDIR'])) {
            $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        }
        if (empty($GLOBALS['maxloginattempts'])) {
            $GLOBALS['maxloginattempts'] = (int) get_setting('security.maxloginattempts', 10);
        }
//        do_log(sprintf(
//            "Nexus booted. request.server: %s, request.header: %s, request.query: %s, request.input: %s",
//            nexus_json_encode($request->server()), nexus_json_encode($request->header()), nexus_json_encode($request->query()), nexus_json_encode($request->input())
//        ));
        return $next($request);
    }


}
