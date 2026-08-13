<?php

namespace App\Http\Controllers;

use App\Http\Resources\FileResource;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FileController extends Controller
{
    /**
     * File list fragment, replaces legacy public/viewfilelist.php (Phase 2 P3).
     *
     * Loaded synchronously via ajax.gets() into the details page (#filelist).
     */
    public function web(Request $request)
    {
        $torrentId = (int) $request->get('id', 0);
        if ($torrentId <= 0 || !Auth::guard('nexus')->check()) {
            return response('');
        }
        $lang = get_legacy_lang_file('viewfilelist');
        $files = File::query()->where('torrent', $torrentId)->orderBy('id')->get();

        $rows = $files->map(function (File $file) {
            return [
                'filename' => $file->filename,
                'size' => mksize($file->size),
            ];
        });

        return response(view('viewfilelist', [
            'lang' => $lang,
            'rows' => $rows,
        ]))->header('Content-Type', 'text/xml; charset=utf-8')
            ->header('Cache-Control', 'no-cache, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', 'Mon, 26 Jul 1997 05:00:00 GMT');
    }

    /**
     * torrent file list
     *
     * @param Request $request
     * @return array
     */
    public function index(Request $request)
    {
        $torrentId = $request->torrent_id;
        $files = File::query()->where('torrent', $torrentId)->get();
        $resource = FileResource::collection($files);
//        $resource->additional([
//            'page_title' => nexus_trans('file.index.page_title'),
//        ]);

        return $this->success($resource);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
