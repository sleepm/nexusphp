<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ImageController extends Controller
{
    public function web(Request $request)
    {
        $action = $request->query('action', '');
        $imagehash = (string) $request->query('imagehash', '');

        if ($action !== 'regimage') {
            return response('Invalid captcha action', 404);
        }

        $driver = captcha_manager()->driver('image');

        if (!method_exists($driver, 'outputImage')) {
            return response('Captcha driver does not support image rendering', 404);
        }

        ob_start();
        $driver->outputImage($imagehash);
        $content = ob_get_clean();

        if ($content === '' || $content === false) {
            return response('', 404);
        }

        return response($content, 200, ['Content-Type' => 'image/png']);
    }
}