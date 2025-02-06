<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

defined('LARAVEL_START') || define('LARAVEL_START', microtime(true));
defined('IN_NEXUS') || define('IN_NEXUS', false);
require dirname(__DIR__) . '/include/constants.php';
require dirname(__DIR__) . '/include/globalfunctions.php';
require dirname(__DIR__) . '/include/functions.php';
if (!RUNNING_IN_OCTANE) {
    \Nexus\Nexus::boot();
}
$GLOBALS['hook'] = $hook = new \Nexus\Plugin\Hook();
$GLOBALS['plugin'] = $plugin = new \Nexus\Plugin\Plugin();

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
