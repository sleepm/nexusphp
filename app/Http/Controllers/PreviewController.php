<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PreviewController extends Controller
{
    /**
     * BB-code / plain-text preview fragment. Mirrors legacy public/preview.php:
     * a POST endpoint (body=...) rendered through format_comment() that the
     * front-end textbbcode editor injects into the preview pane. Requires a
     * logged-in session (legacy loggedinorreturn()). Returns a bare HTML
     * fragment, no layout.
     */
    public function web(Request $request)
    {
        $body = (string) $request->post('body', '');
        $content = format_comment($body);

        return view('preview', compact('content'));
    }
}
