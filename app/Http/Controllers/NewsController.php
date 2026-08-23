<?php

namespace App\Http\Controllers;

use App\Http\Resources\NewsResource;
use App\Models\News;
use App\Models\Setting;
use App\Repositories\NewsRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Nexus\Database\NexusDB;

class NewsController extends Controller
{
    private $repository;

    public function __construct(NewsRepository $repository)
    {
        $this->repository = $repository;
    }

    private function getRules(): array
    {
        return [
            'family_id' => 'required|numeric',
            'name' => 'required|string',
            'peer_id' => 'required|string',
            'agent' => 'required|string',
            'comment' => 'required|string',

        ];
    }
    /**
     * Display a listing of the resource.
     *
     * @return array
     */
    public function index(Request $request)
    {
        $result = $this->repository->getList($request->all());
        $resource = NewsResource::collection($result);
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
        $request->validate($this->getRules());
        $result = $this->repository->store($request->all());
        $resource = new NewsResource($result);
        return $this->success($resource);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return array
     */
    public function show($id)
    {
        $result = News::query()->findOrFail($id);
        $resource = new NewsResource($result);
        return $this->success($resource);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return array
     */
    public function update(Request $request, $id)
    {
        $request->validate($this->getRules());
        $result = $this->repository->update($request->all(), $id);
        $resource = new NewsResource($result);
        return $this->success($resource);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return array
     */
    public function destroy($id)
    {
        $result = $this->repository->delete($id);
        return $this->success($result);
    }

    /**
     * @return array
     */
    public function latest()
    {
        $user = Auth::user();
        $result = News::query()->orderBy('id', 'desc')->first();
        if ($result) {
            $resource = new NewsResource($result);
        } else {
            $resource = new JsonResource(null);
        }
        $resource->additional([
            'site_info' => site_info(),
        ]);

        /**
         * Visiting the home page is the same as viewing the latest news
         * @see functions.php line 2590
         */
        $user->update(['last_home' => Carbon::now()]);
        return $this->success($resource);
    }

    /**
     * News management page. Mirrors legacy public/news.php: add/edit/delete
     * news items using the compose editor. Requires newsmanage permission.
     *
     * Actions:
     *   GET  ?action=delete&newsid=N&sure=1   — delete news item
     *   POST ?action=add                       — add news item
     *   GET  ?action=edit&newsid=N             — show edit form
     *   POST ?action=edit&newsid=N             — update news item
     *   GET  (default)                         — show submit form
     */
    public function web(Request $request)
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        if (! $currentUser) {
            abort(401);
        }
        $curUser = $currentUser->toArray();
        if (($curUser['parked'] ?? '') == 'yes') {
            abort(403, 'Your account is parked.');
        }
        if (! user_can('newsmanage', false, $currentUser->id)) {
            abort(403, 'Access denied.');
        }

        $lang = get_legacy_lang_file('news');
        $GLOBALS['lang_news'] = $lang;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();
        $GLOBALS['CURUSER'] = $curUser;

        $action = $request->query('action', '');

        if ($action == 'delete') {
            return $this->handleDelete($request, $curUser, $lang);
        }
        if ($action == 'add') {
            return $this->handleAdd($request, $curUser, $lang);
        }
        if ($action == 'edit') {
            return $this->handleEdit($request, $curUser, $lang);
        }

        // Default: show submit news form
        $content = $this->capture(function () use ($lang) {
            begin_main_frame();
            $title = $lang['text_submit_news_item'];
            print('<form id="compose" method="post" name="compose" action="?action=add">' . "\n");
            begin_compose($title, 'new');
            print('<tr><td class="toolbox" align="center" colspan="2"><input type="checkbox" name="notify" value="yes" />' . $lang['text_notify_users_of_this'] . '</td></tr>' . "\n");
            end_compose();
            print('</form>');
            end_main_frame();
        });

        return view('news', compact('content') + [
            'pageTitle' => $lang['head_site_news'],
        ]);
    }

    private function handleDelete(Request $request, array $curUser, array $lang)
    {
        $newsId = (int) $request->query('newsid', 0);
        if ($newsId < 1) {
            abort(400, 'Invalid news ID');
        }

        $returnto = $request->query('returnto', '');
        if (! $returnto) {
            $returnto = $request->headers->get('referer', '');
        }

        $sure = (int) $request->query('sure', 0);
        if (! $sure) {
            $content = $this->capture(function () use ($lang, $newsId, $returnto) {
                stdmsg(
                    $lang['std_delete_news_item'],
                    $lang['std_are_you_sure']
                    . '<a class=altlink href=?action=delete&newsid=' . $newsId . '&returnto=' . urlencode($returnto) . '&sure=1>'
                    . $lang['std_here'] . '</a>' . $lang['std_if_sure']
                );
            });

            return view('news', compact('content') + [
                'pageTitle' => $lang['std_delete_news_item'],
            ]);
        }

        News::query()->where('id', $newsId)->delete();
        Cache::forget('recent_news');
        NexusDB::cache_del('recent_news');

        if ($returnto) {
            return redirect($returnto);
        }

        return redirect(get_protocol_prefix() . Setting::getBaseUrl() . '/index.php');
    }

    private function handleAdd(Request $request, array $curUser, array $lang)
    {
        $body = $request->input('body', '');
        if (! $body) {
            abort(400, $lang['std_news_body_empty']);
        }

        $title = $request->input('subject', '');
        if (! $title) {
            abort(400, $lang['std_news_title_empty']);
        }

        $added = $request->input('added', '');
        $addedDate = $added ?: date('Y-m-d H:i:s');
        $notify = $request->input('notify', 'no') == 'yes' ? 'yes' : 'no';

        $news = News::query()->create([
            'userid' => $curUser['id'],
            'added' => $addedDate,
            'body' => $body,
            'title' => $title,
            'notify' => $notify,
        ]);

        Cache::forget('recent_news');
        NexusDB::cache_del('recent_news');

        fire_event('news_created', $news);

        return redirect(get_protocol_prefix() . Setting::getBaseUrl() . '/index.php');
    }

    private function handleEdit(Request $request, array $curUser, array $lang)
    {
        $newsId = (int) $request->query('newsid', 0);
        if ($newsId < 1) {
            abort(400, 'Invalid news ID');
        }

        $news = News::query()->find($newsId);
        if (! $news) {
            abort(404, $lang['std_invalid_news_id'] . $newsId);
        }

        if ($request->isMethod('POST')) {
            $body = $request->input('body', '');
            if (! $body) {
                abort(400, $lang['std_news_body_empty']);
            }

            $title = $request->input('subject', '');
            if (! $title) {
                abort(400, $lang['std_news_title_empty']);
            }

            $notify = $request->input('notify', 'no') == 'yes' ? 'yes' : 'no';

            $news->update([
                'body' => $body,
                'title' => $title,
                'notify' => $notify,
            ]);

            Cache::forget('recent_news');
            NexusDB::cache_del('recent_news');

            return redirect(get_protocol_prefix() . Setting::getBaseUrl() . '/index.php');
        }

        // GET: show edit form
        $content = $this->capture(function () use ($lang, $news, $newsId) {
            begin_main_frame();
            $body = $news->body;
            $subject = htmlspecialchars($news->title);
            $title = $lang['text_edit_site_news'];
            print('<form id="compose" name="compose" method="post" action="' . htmlspecialchars('?action=edit&newsid=' . $newsId) . '">' . "\n");
            print('<input type="hidden" name="returnto" value="" />' . "\n");
            begin_compose($title, 'edit', $body, true, $subject);
            print('<tr><td class="toolbox" align="center" colspan="2"><input type="checkbox" name="notify" value="yes" ' . ($news->notify == 'yes' ? ' checked="checked"' : '') . ' />' . $lang['text_notify_users_of_this'] . '</td></tr>' . "\n");
            end_compose();
            print('</form>');
            end_main_frame();
        });

        return view('news', compact('content') + [
            'pageTitle' => $lang['head_edit_site_news'],
        ]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();

        return (string) ob_get_clean();
    }
}
