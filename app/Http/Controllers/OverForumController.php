<?php

namespace App\Http\Controllers;

use App\Http\Resources\OverForumResource;
use App\Models\OverForum;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OverForumController extends Controller
{
    /**
     * Web entry point. Mirrors the legacy public/moforums.php action dispatch so
     * the over-forum (forum category) management page is served by the router.
     */
    public function web(Request $request)
    {
        [$curUser, $lang] = $this->bootstrap($request);

        $act = (string) $request->query('action', '');
        if ($act === '') {
            $act = 'forum';
        }

        // DELETE OVERFORUM ACTION
        if ($act === 'del') {
            return $this->webDelete($request, $curUser, $lang);
        }

        // EDIT OVERFORUM ACTION (POST)
        if ($request->isMethod('post') && (string) $request->input('action', '') === 'editforum') {
            return $this->webUpdate($request, $curUser, $lang);
        }

        // ADD OVERFORUM ACTION (POST)
        if ($request->isMethod('post') && (string) $request->input('action', '') === 'addforum') {
            return $this->webStore($request, $curUser, $lang);
        }

        if ($act === 'editforum') {
            return $this->webEditForum($request, $curUser, $lang);
        }

        return $this->webForumList($request, $curUser, $lang);
    }

    /**
     * Authenticate, set the legacy globals used by shared helpers and return
     * the current user array + moforums lang file.
     */
    private function bootstrap(Request $request): array
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        if (!$currentUser) {
            abort(401);
        }
        if (($currentUser->parked ?? '') == 'yes') {
            abort(403, 'Your account is parked.');
        }
        $curUser = $currentUser->toArray();

        assert_has_permission(user_can('forummanage'));

        $lang = get_legacy_lang_file('moforums');

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_moforums'] = $lang;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        return [$curUser, $lang];
    }

    /**
     * Delete over-forum (legacy action=del).
     */
    private function webDelete(Request $request, array $curUser, array $lang)
    {
        $id = intval($request->query('id', 0));
        if (!$id) {
            return redirect('moforums.php?action=forum');
        }

        OverForum::query()->whereKey($id)->delete();
        $this->clearOverforumsCache();

        return redirect('moforums.php?action=forum');
    }

    /**
     * Update over-forum (legacy POST action=editforum).
     */
    private function webUpdate(Request $request, array $curUser, array $lang)
    {
        $id = intval($request->input('id', 0));
        $name = trim((string) $request->input('name', ''));
        $desc = trim((string) $request->input('desc', ''));

        if (!$name && !$desc && !$id) {
            return redirect('moforums.php?action=forum');
        }

        OverForum::query()->whereKey($id)->update([
            'sort' => intval($request->input('sort', 0)),
            'name' => $request->input('name'),
            'description' => $request->input('desc'),
            'minclassview' => intval($request->input('viewclass', 0)),
        ]);
        $this->clearOverforumsCache();

        return redirect('moforums.php?action=forum');
    }

    /**
     * Insert over-forum (legacy POST action=addforum).
     */
    private function webStore(Request $request, array $curUser, array $lang)
    {
        $name = trim((string) $request->input('name', ''));
        $desc = trim((string) $request->input('desc', ''));

        if (!$name && !$desc) {
            return redirect('moforums.php?action=forum');
        }

        OverForum::query()->forceCreate([
            'sort' => intval($request->input('sort', 0)),
            'name' => $name,
            'description' => $desc,
            'minclassview' => intval($request->input('viewclass', 0)),
        ]);
        $this->clearOverforumsCache();

        return redirect('moforums.php?action=forum');
    }

    /**
     * Over-forum list + new over-forum form (legacy action=forum).
     */
    private function webForumList(Request $request, array $curUser, array $lang)
    {
        $content = $this->capture(function () use ($lang, $curUser) {
            print('<h2 class=transparentbg align=center><a class=faqlink href=forummanage.php>' . $lang['text_forum_management'] . '</a><b>--></b>' . $lang['text_overforum_management'] . '</h2>');
            print('<br />');
            print('<table width="100%" border="0" align="center" cellpadding="2" cellspacing="0">');
            print('<tr><td class=colhead align=left>' . $lang['col_name'] . '</td><td class=colhead>' . $lang['col_viewed_by'] . '</td><td class=colhead>' . $lang['col_modify'] . '</td></tr>');

            $overforums = OverForum::query()->orderBy('sort', 'asc')->get();
            if ($overforums->isNotEmpty()) {
                foreach ($overforums as $row) {
                    print('<tr><td><a href="forums.php?action=forumview&amp;forid=' . $row->id . '"><b>' . htmlspecialchars($row->name) . '</b></a><br />' . htmlspecialchars($row->description) . '</td>');
                    print('<td>' . get_user_class_name($row->minclassview, false, true, true) . '</td><td><b><a href="' . htmlspecialchars('moforums.php?action=editforum&id=' . $row->id) . '">' . $lang['text_edit'] . '</a>&nbsp;|&nbsp;<a href="javascript:confirm_delete(\'' . $row->id . '\', \'' . $lang['js_sure_to_delete_overforum'] . '\', \'\');"><font color=red>' . $lang['text_delete'] . '</font></a></b></td></tr>');
                }
            } else {
                print('<tr><td colspan=3>' . $lang['text_no_records_found'] . '</td></tr>');
            }
            print('</table>');
            print('<br /><br />');

            print('<form method="post" action="moforums.php">');
            print('<table width="100%" border="0" cellspacing="0" cellpadding="3" align="center">');
            print('<tr align="center"><td colspan="2" class=colhead>' . $lang['text_new_overforum'] . '</td></tr>');
            print('<tr><td><b>' . $lang['text_overforum_name'] . '</td><td><input name="name" type="text" style="width: 200px" maxlength="60"></td></tr>');
            print('<tr><td><b>' . $lang['text_overforum_description'] . '</td><td><input name="desc" type="text" style="width: 400px" maxlength="200"></td></tr>');
            print('<tr><td><b>' . $lang['text_minimum_view_permission'] . '</td><td><select name="viewclass">');
            $maxclass = get_user_class();
            for ($i = 0; $i <= $maxclass; ++$i) {
                print('<option value="' . $i . '"' . ($curUser['class'] == $i ? ' selected' : '') . '>' . get_user_class_name($i, false, true, true) . '</option>');
            }
            print('</select></td></tr>');
            print('<tr><td><b>' . $lang['text_overforum_order'] . '</td><td><select name="sort">');
            $nr = OverForum::query()->count();
            $maxclass = $nr + 1;
            for ($i = 0; $i <= $maxclass; ++$i) {
                print('<option value="' . $i . '">' . $i . '</option>');
            }
            print('</select>&nbsp;' . $lang['text_overforum_order_note'] . '</td></tr>');
            print('<tr align="center"><td colspan="2"><input type="hidden" name="action" value="addforum"><input type="submit" name="Submit" value="' . $lang['submit_make_overforum'] . '"></td></tr>');
            print('</table>');
            print('</form>');
        });

        $pageTitle = $lang['head_overforum_management'] ?? 'Overforum Management';
        return view('moforums', compact('content') + ['pageTitle' => $pageTitle, 'lang' => $lang]);
    }

    /**
     * Edit over-forum form (legacy action=editforum, GET).
     */
    private function webEditForum(Request $request, array $curUser, array $lang)
    {
        $id = intval($request->query('id', 0));

        $content = $this->capture(function () use ($id, $lang, $curUser) {
            $row = OverForum::query()->whereKey($id)->first();
            if (!$row) {
                print($lang['text_no_records_found']);
                return;
            }

            print('<h2 class=transparentbg align=center><a class=faqlink href=forummanage.php>' . $lang['text_forum_management'] . '</a><b>--></b><a class=faqlink href=moforums.php>' . $lang['text_overforum_management'] . '</a><b>--></b>' . $lang['text_edit_overforum'] . '</h2><br />');

            print('<form method="post" action="moforums.php">');
            print('<table width="100%" border="0" cellspacing="0" cellpadding="3" align="center">');
            print('<tr align="center"><td colspan="2" class=colhead>' . $lang['text_edit_overforum'] . ' -- ' . htmlspecialchars($row->name) . '</td></tr>');
            print('<tr><td><b>' . $lang['text_overforum_name'] . '</td><td><input name="name" type="text" style="width: 200px" maxlength="60" value="' . htmlspecialchars($row->name) . '"></td></tr>');
            print('<tr><td><b>' . $lang['text_overforum_description'] . '</td><td><input name="desc" type="text" style="width: 400px" maxlength="200" value="' . htmlspecialchars($row->description) . '"></td></tr>');
            print('<tr><td><b>' . $lang['text_minimum_view_permission'] . '</td><td><select name="viewclass">');
            $maxclass = get_user_class();
            for ($i = 0; $i <= $maxclass; ++$i) {
                print('<option value="' . $i . '"' . ($row->minclassview == $i ? ' selected' : '') . '>' . get_user_class_name($i, false, true, true) . '</option>');
            }
            print('</select></td></tr>');
            print('<tr><td><b>' . $lang['text_overforum_order'] . '</td><td><select name="sort">');
            $nr = OverForum::query()->count();
            $maxclass = $nr + 1;
            for ($i = 0; $i <= $maxclass; ++$i) {
                print('<option value="' . $i . '"' . ($row->sort == $i ? ' selected' : '') . '>' . $i . '</option>');
            }
            print('</select>&nbsp;' . $lang['text_overforum_order_note'] . '</td></tr>');
            print('<tr align="center"><td colspan="2"><input type="hidden" name="action" value="editforum"><input type="hidden" name="id" value="' . $row->id . '"><input type="submit" name="Submit" value="' . $lang['submit_edit_overforum'] . '"></td></tr>');
            print('</table>');
            print('</form>');
        });

        $pageTitle = $lang['head_overforum_management'] ?? 'Overforum Management';
        return view('moforums', compact('content') + ['pageTitle' => $pageTitle, 'lang' => $lang]);
    }

    private function clearOverforumsCache(): void
    {
        if (!empty($GLOBALS['Cache'])) {
            $GLOBALS['Cache']->delete_value('overforums_list');
        }
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }

    /**
     * Display a listing of the resource.
     *
     * @return array
     */
    public function index()
    {
        $list = OverForum::query()->orderBy("sort", "asc")->get();
        $resource = OverForumResource::collection($list);
        return $this->success($resource);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
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
     * @param  \App\Models\OverForum  $overForum
     * @return \Illuminate\Http\Response
     */
    public function show(OverForum $overForum)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\OverForum  $overForum
     * @return \Illuminate\Http\Response
     */
    public function edit(OverForum $overForum)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\OverForum  $overForum
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, OverForum $overForum)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\OverForum  $overForum
     * @return \Illuminate\Http\Response
     */
    public function destroy(OverForum $overForum)
    {
        //
    }
}