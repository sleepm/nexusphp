<?php

namespace App\Http\Controllers;

use App\Http\Resources\BookmarkResource;
use App\Http\Resources\TorrentResource;
use App\Models\Torrent;
use App\Repositories\BookmarkRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BookmarkController extends Controller
{
    private $repository;

    public function __construct(BookmarkRepository $repository)
    {
        $this->repository = $repository;
    }

    public function index(Request $request)
    {
    }

    /**
     * Toggle a torrent bookmark. Mirrors legacy public/bookmark.php.
     * Returns a plain-text "added" / "deleted" / "failed" response used by the
     * legacy details page AJAX (see public/js/common.js bookmark toggle).
     */
    public function toggle(Request $request)
    {
        $torrentId = (int) $request->input('torrentid', 0);
        $user = Auth::guard('nexus')->user();
        if (!$user) {
            return response('failed');
        }
        $torrent = Torrent::query()->find($torrentId);
        if (!$torrent) {
            return response('failed');
        }
        try {
            $bookmarked = $user->bookmarks()->where('torrentid', $torrentId)->exists();
            if ($bookmarked) {
                $this->repository->remove($user, $torrentId);
                $this->clearBookmarkCache($user->id);
                return response('deleted');
            }
            $this->repository->add($user, $torrentId);
            $this->clearBookmarkCache($user->id);
            return response('added');
        } catch (\Throwable $exception) {
            do_log(sprintf("bookmark toggle fail, torrentId: %s, error: %s", $torrentId, $exception->getMessage()), 'error');
            return response('failed');
        }
    }

    private function clearBookmarkCache(int $userId)
    {
        if (!empty($GLOBALS['Cache'])) {
            $GLOBALS['Cache']->delete_value('user_' . $userId . '_bookmark_array');
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function store(Request $request)
    {
        $request->validate([
            'torrent_id' => 'required|integer',
        ]);
        $result = $this->repository->add(Auth::user(), $request->torrent_id);
        $resource = new BookmarkResource($result);
        return $this->success($resource, nexus_trans('bookmark.actions.store_success'));
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
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
     * @return array
     */
    public function destroy(Request $request)
    {
        $request->validate([
            'torrent_id' => 'required|integer',
        ]);
        $result = $this->repository->remove(Auth::user(), $request->torrent_id);
        return $this->success(true, nexus_trans('bookmark.actions.delete_success'));
    }

}
