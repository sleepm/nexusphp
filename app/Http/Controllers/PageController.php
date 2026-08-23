<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Nexus\Plugin\Plugin;

class PageController extends Controller
{
    public function web(Request $request)
    {
        $view = (string) $request->input('view', '');
        $pluginId = (string) $request->input('plugin', '');

        if ($view !== '') {
            $view = trim($view, '/.');
            $view = str_replace('.', '/', $view);
            if ($pluginId !== '') {
                $plugin = Plugin::getById($pluginId);
                if (!$plugin) {
                    $msg = 'plugin: ' . $pluginId . ' not found, _REQUEST: ' . json_encode($request->all());
                    do_log($msg, 'error');
                    abort(404, $msg);
                }
                $viewFile = $plugin->getNexusView($view);
            } else {
                $viewFile = ROOT_PATH . 'resources/views/' . $view;
            }

            if (!str_ends_with($viewFile, '.php')) {
                $viewFile .= '.php';
            }
            if (file_exists($viewFile)) {
                ob_start();
                require $viewFile;
                return response((string) ob_get_clean());
            }
            $msg = 'viewFile: ' . $viewFile . ' not exists, _REQUEST: ' . json_encode($request->all());
            do_log($msg, 'error');
            abort(404, $msg);
        }
        $msg = 'require view parameter, _REQUEST: ' . json_encode($request->all());
        do_log($msg, 'error');
        abort(400, $msg);
    }
}