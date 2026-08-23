<?php

namespace App\Http\Controllers;

use App\Models\Sub;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DownloadSubsController extends Controller
{
    public function web(Request $request)
    {
        /** @var User|null $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        if (!$currentUser) {
            return redirect('/');
        }

        $filename = (int) ($request->query('subid', 0));
        $dirname = (int) ($request->query('torrentid', 0));

        if (!$filename || !$dirname) {
            return response("File name missing\n", 400);
        }

        $sub = Sub::query()->find($filename);
        if (!$sub) {
            return response("Not found\n", 404);
        }

        Sub::query()->where('id', $filename)->increment('hits');

        $file = ROOT_PATH . trim((string) get_setting('main.subspath', 'subs'), '/') . '/' . $dirname . '/' . $filename . '.' . $sub->ext;

        if (!is_file($file)) {
            return response("File not found\n", 404);
        }

        $f = fopen($file, 'rb');
        if (!$f) {
            return response("Cannot open file\n", 500);
        }

        $content = stream_get_contents($f);
        fclose($f);

        $headers = [
            'Content-Length' => filesize($file),
            'Content-Type' => 'application/octet-stream',
        ];

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (str_contains($ua, 'Gecko') || str_contains($ua, 'Firefox') || str_contains($ua, 'Opera')) {
            $headers['Content-Disposition'] = 'attachment; filename="' . $sub->filename . '" ; charset=utf-8';
        } else {
            $headers['Content-Disposition'] = 'attachment; filename=' . str_replace('+', '%20', rawurlencode($sub->filename));
        }

        return response($content, 200, $headers);
    }
}