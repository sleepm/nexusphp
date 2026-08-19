<?php

namespace App\Http\Controllers;

use App\Models\Forum;
use App\Models\ForumMod;
use App\Models\OverForum;
use App\Models\Post;
use App\Models\Setting;
use App\Models\Topic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ForumManageController extends Controller
{
    /**
     * Web entry point. Mirrors the legacy public/forummanage.php action dispatch
     * so the forum management page is served by the router.
     */
    public function web(Request $request)
    {
        [$curUser, $lang] = $this->bootstrap($request);

        $act = (string) $request->query('action', '');

        // DELETE FORUM ACTION (GET)
        if ($act === 'del') {
            return $this->webDelete($request, $curUser, $lang);
        }

        // EDIT FORUM ACTION (POST)
        if ($request->isMethod('post') && (string) $request->input('action', '') === 'editforum') {
            return $this->webUpdate($request, $curUser, $lang);
        }

        // ADD FORUM ACTION (POST)
        if ($request->isMethod('post') && (string) $request->input('action', '') === 'addforum') {
            return $this->webStore($request, $curUser, $lang);
        }

        if ($act === 'editforum') {
            return $this->webEditForum($request, $curUser, $lang);
        }

        if ($act === 'newforum') {
            return $this->webNewForum($request, $curUser, $lang);
        }

        return $this->webForumList($request, $curUser, $lang);
    }

    /**
     * Authenticate, set the legacy globals used by shared helpers and return
     * the current user array + forummanage lang file.
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

        $lang = get_legacy_lang_file('forummanage');

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_forummanage'] = $lang;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        return [$curUser, $lang];
    }

    /**
     * Delete forum together with its topics/posts/mods (legacy action=del).
     */
    private function webDelete(Request $request, array $curUser, array $lang)
    {
        $id = intval($request->query('id', 0));
        if (!$id) {
            return redirect('forummanage.php');
        }

        $topicIds = Topic::query()->where('forumid', $id)->pluck('id');
        if ($topicIds->isNotEmpty()) {
            Post::query()->whereIn('topicid', $topicIds)->delete();
        }
        Topic::query()->where('forumid', $id)->delete();
        Forum::query()->whereKey($id)->delete();
        ForumMod::query()->where('forumid', $id)->delete();
        $this->clearForumCache();

        return redirect('forummanage.php');
    }

    /**
     * Update forum (legacy POST action=editforum).
     */
    private function webUpdate(Request $request, array $curUser, array $lang)
    {
        $name = trim((string) $request->input('name', ''));
        $desc = trim((string) $request->input('desc', ''));
        $id = intval($request->input('id', 0));

        if (!$name && !$desc && !$id) {
            return redirect('forummanage.php');
        }

        $moderator = trim((string) $request->input('moderator', ''));
        if ($moderator !== '') {
            set_forum_moderators($moderator, $id);
        } else {
            ForumMod::query()->where('forumid', $id)->delete();
        }

        Forum::query()->whereKey($id)->update([
            'sort' => intval($request->input('sort', 0)),
            'name' => $request->input('name'),
            'description' => $request->input('desc'),
            'forid' => intval($request->input('overforums', 0)),
            'minclassread' => intval($request->input('readclass', 0)),
            'minclasswrite' => intval($request->input('writeclass', 0)),
            'minclasscreate' => intval($request->input('createclass', 0)),
        ]);
        $this->clearForumCache();

        return redirect('forummanage.php');
    }

    /**
     * Insert forum (legacy POST action=addforum).
     */
    private function webStore(Request $request, array $curUser, array $lang)
    {
        $name = trim((string) $request->input('name', ''));
        $desc = trim((string) $request->input('desc', ''));

        if (!$name && !$desc) {
            return redirect('forummanage.php');
        }

        $forum = Forum::query()->create([
            'sort' => intval($request->input('sort', 0)),
            'name' => $name,
            'description' => $desc,
            'forid' => intval($request->input('overforums', 0)),
            'minclassread' => intval($request->input('readclass', 0)),
            'minclasswrite' => intval($request->input('writeclass', 0)),
            'minclasscreate' => intval($request->input('createclass', 0)),
        ]);
        $this->clearForumCache();

        $moderator = trim((string) $request->input('moderator', ''));
        if ($moderator !== '') {
            set_forum_moderators($moderator, $forum->id);
        }

        return redirect('forummanage.php');
    }

    /**
     * Forum list (default view).
     */
    private function webForumList(Request $request, array $curUser, array $lang)
    {
        $content = $this->capture(function () use ($lang) {
            print('<h2 class=transparentbg align=center>' . $lang['text_forum_management'] . '</h2>');
            print('<table border=0 class=main cellspacing=0 cellpadding=5 width=1%><tr>');
            print('<td class=embedded align=left><form method="get" action="moforums.php"><input type="submit" value="' . $lang['submit_overforum_management'] . '" class="btn"></form></td><td class=embedded align=left><form method="get" action="forummanage.php"><input type=hidden name="action" value="newforum"><input type="submit" value="' . $lang['submit_add_forum'] . '" class="btn"></form></td>');
            print('</tr></table>');

            print('<table width="100%" border="0" align="center" cellpadding="2" cellspacing="0">');
            print('<tr><td class=colhead align=left>' . $lang['col_name'] . '</td><td class=colhead>' . $lang['col_overforum'] . '</td><td class=colhead>' . $lang['col_read'] . '</td><td class=colhead>' . $lang['col_write'] . '</td><td class=colhead>' . $lang['col_create_topic'] . '</td><td class=colhead>' . $lang['col_moderator'] . '</td><td class=colhead>' . $lang['col_modify'] . '</td></tr>');

            $forums = Forum::query()
                ->leftJoin('overforums', 'forums.forid', '=', 'overforums.id')
                ->orderBy('forums.sort', 'asc')
                ->select('forums.*', 'overforums.name AS of_name')
                ->get();

            if ($forums->isNotEmpty()) {
                foreach ($forums as $row) {
                    $name = $row->of_name;
                    $moderators = get_forum_moderators($row->id, false);
                    if (!$moderators) {
                        $moderators = $lang['text_not_available'];
                    }
                    print('<tr><td><a href="forums.php?action=viewforum&forumid=' . $row->id . '"><b>' . htmlspecialchars($row->name) . '</b></a><br />' . htmlspecialchars($row->description) . '</td>');
                    print('<td>' . htmlspecialchars($name) . '</td><td>' . get_user_class_name($row->minclassread, false, true, true) . '</td><td>' . get_user_class_name($row->minclasswrite, false, true, true) . '</td><td>' . get_user_class_name($row->minclasscreate, false, true, true) . '</td><td>' . $moderators . '</td><td><b><a href="' . htmlspecialchars('forummanage.php?action=editforum&id=' . $row->id) . '">' . $lang['text_edit'] . '</a>&nbsp;|&nbsp;<a href="javascript:confirm_delete(\'' . $row->id . '\', \'' . $lang['js_sure_to_delete_forum'] . '\', \'\');"><font color=red>' . $lang['text_delete'] . '</font></a></b></td></tr>');
                }
            } else {
                print('<tr><td colspan=6>' . $lang['text_no_records_found'] . '</td></tr>');
            }
            print('</table>');
        });

        $pageTitle = $lang['head_forum_management'] ?? 'Forum Management';
        return view('forummanage', compact('content') + ['pageTitle' => $pageTitle, 'lang' => $lang]);
    }

    /**
     * Edit forum form (legacy action=editforum, GET).
     */
    private function webEditForum(Request $request, array $curUser, array $lang)
    {
        $id = intval($request->query('id', 0));

        $content = $this->capture(function () use ($id, $lang, $curUser) {
            $row = Forum::query()->whereKey($id)->first();
            if (!$row) {
                print($lang['text_no_records_found']);
                return;
            }

            print('<h2 class=transparentbg align=center><a class=faqlink href=forummanage.php>' . $lang['text_forum_management'] . '</a><b>--></b>' . $lang['text_edit_forum'] . '</h2>');
            print('<br />');
            print('<form method=post action="forummanage.php">');
            print('<table width="100%" border="0" cellspacing="0" cellpadding="3" align="center">');
            print('<tr align="center"><td colspan="2" class=colhead>' . $lang['text_edit_forum'] . ' -- ' . htmlspecialchars($row->name) . '</td></tr>');
            print('<tr><td><b>' . $lang['row_forum_name'] . '</td><td><input name="name" type="text" style="width: 200px" maxlength="60" value="' . htmlspecialchars($row->name) . '"></td></tr>');
            print('<tr><td><b>' . $lang['row_forum_description'] . '</td><td><input name="desc" type="text" style="width: 400px" maxlength="200" value="' . htmlspecialchars($row->description) . '"></td></tr>');

            print('<tr><td><b>' . $lang['row_overforum'] . '</td><td><select name="overforums">');
            foreach (OverForum::query()->get() as $arr) {
                print('<option value="' . $arr->id . '"' . ($row->forid == $arr->id ? ' selected' : '') . '>' . htmlspecialchars($arr->name) . '</option>');
            }
            print('</select></td></tr>');

            $username = get_forum_moderators($row->id, true);
            print('<tr><td><b>' . $lang['row_moderator'] . '</b></td><td><input name="moderator" type="text" style="width: 200px" maxlength="200" value="' . htmlspecialchars($username) . '">&nbsp;' . $lang['text_moderator_note'] . '</td></tr>');

            print('<tr><td><b>' . $lang['row_minimum_read_permission'] . '</td><td><select name="readclass">');
            $maxclass = get_user_class();
            for ($i = 0; $i <= $maxclass; ++$i) {
                print('<option value="' . $i . '"' . ($row->minclassread == $i ? ' selected' : '') . '>' . get_user_class_name($i, false, true, true) . '</option>');
            }
            print('</select></td></tr>');

            print('<tr><td><b>' . $lang['row_minimum_write_permission'] . '</td><td><select name="writeclass">');
            $maxclass = get_user_class();
            for ($i = 0; $i <= $maxclass; ++$i) {
                print('<option value="' . $i . '"' . ($row->minclasswrite == $i ? ' selected' : '') . '>' . get_user_class_name($i, false, true, true) . '</option>');
            }
            print('</select></td></tr>');

            print('<tr><td><b>' . $lang['row_minimum_create_topic_permission'] . '</td><td><select name="createclass">');
            $maxclass = get_user_class();
            for ($i = 0; $i <= $maxclass; ++$i) {
                print('<option value="' . $i . '"' . ($row->minclasscreate == $i ? ' selected' : '') . '>' . get_user_class_name($i, false, true, true) . '</option>');
            }
            print('</select></td></tr>');

            print('<tr><td><b>' . $lang['row_forum_order'] . '</td><td><select name="sort">');
            $nr = Forum::query()->count();
            $maxclass = $nr + 1;
            for ($i = 0; $i <= $maxclass; ++$i) {
                print('<option value="' . $i . '"' . ($row->sort == $i ? ' selected' : '') . '>' . $i . '</option>');
            }
            print('</select>&nbsp;' . $lang['text_forum_order_note'] . '</td></tr>');

            print('<tr align="center"><td colspan="2"><input type="hidden" name="action" value="editforum"><input type="hidden" name="id" value="' . $row->id . '"><input type="submit" name="Submit" value="' . $lang['submit_edit_forum'] . '" class="btn"></td></tr>');
            print('</table>');
            print('</form>');
        });

        $pageTitle = $lang['head_forum_management'] ?? 'Forum Management';
        return view('forummanage', compact('content') + ['pageTitle' => $pageTitle, 'lang' => $lang]);
    }

    /**
     * Add forum form (legacy action=newforum, GET).
     */
    private function webNewForum(Request $request, array $curUser, array $lang)
    {
        $content = $this->capture(function () use ($lang, $curUser) {
            print('<h2 class=transparentbg align=center><a class=faqlink href=forummanage.php>' . $lang['text_forum_management'] . '</a><b>--></b>' . $lang['text_add_forum'] . '</h2>');
            print('<br />');
            print('<form method=post action="forummanage.php">');
            print('<table width="100%" border="0" cellspacing="0" cellpadding="3" align="center">');
            print('<tr align="center"><td colspan="2" class=colhead>' . $lang['text_make_new_forum'] . '</td></tr>');
            print('<tr><td><b>' . $lang['row_forum_name'] . '</td><td><input name="name" type="text" style="width: 200px" maxlength="60"></td></tr>');
            print('<tr><td><b>' . $lang['row_forum_description'] . '</td><td><input name="desc" type="text" style="width: 400px" maxlength="200"></td></tr>');

            print('<tr><td><b>' . $lang['row_overforum'] . '</td><td><select name="overforums">');
            $forid = 0;
            foreach (OverForum::query()->get() as $arr) {
                print('<option value="' . $arr->id . '"' . ($forid == $arr->id ? ' selected' : '') . '>' . htmlspecialchars($arr->name) . '</option>');
            }
            print('</select></td></tr>');

            print('<tr><td><b>' . $lang['row_moderator'] . '</b></td><td><input name="moderator" type="text" style="width: 200px" maxlength="200">&nbsp;' . $lang['text_moderator_note'] . '</td></tr>');

            print('<tr><td><b>' . $lang['row_minimum_read_permission'] . '</td><td><select name="readclass">');
            $maxclass = get_user_class();
            for ($i = 0; $i <= $maxclass; ++$i) {
                print('<option value="' . $i . '"' . ($curUser['class'] == $i ? ' selected' : '') . '>' . get_user_class_name($i, false, true, true) . '</option>');
            }
            print('</select></td></tr>');

            print('<tr><td><b>' . $lang['row_minimum_write_permission'] . '</td><td><select name="writeclass">');
            $maxclass = get_user_class();
            for ($i = 0; $i <= $maxclass; ++$i) {
                print('<option value="' . $i . '"' . ($curUser['class'] == $i ? ' selected' : '') . '>' . get_user_class_name($i, false, true, true) . '</option>');
            }
            print('</select></td></tr>');

            print('<tr><td><b>' . $lang['row_minimum_create_topic_permission'] . '</td><td><select name="createclass">');
            $maxclass = get_user_class();
            for ($i = 0; $i <= $maxclass; ++$i) {
                print('<option value="' . $i . '"' . ($curUser['class'] == $i ? ' selected' : '') . '>' . get_user_class_name($i, false, true, true) . '</option>');
            }
            print('</select></td></tr>');

            print('<tr><td><b>' . $lang['row_forum_order'] . '</td><td><select name="sort">');
            $nr = Forum::query()->count();
            $maxclass = $nr + 1;
            for ($i = 0; $i <= $maxclass; ++$i) {
                print('<option value="' . $i . '">' . $i . '</option>');
            }
            print('</select>&nbsp;' . $lang['text_forum_order_note'] . '</td></tr>');

            print('<tr align="center"><td colspan="2"><input type="hidden" name="action" value="addforum"><input type="submit" name="Submit" value="' . $lang['submit_make_forum'] . '" class=btn></td></tr>');
            print('</table>');
            print('</form>');
        });

        $pageTitle = $lang['head_forum_management'] ?? 'Forum Management';
        return view('forummanage', compact('content') + ['pageTitle' => $pageTitle, 'lang' => $lang]);
    }

    private function clearForumCache(): void
    {
        if (!empty($GLOBALS['Cache'])) {
            $GLOBALS['Cache']->delete_value('forums_list');
            $GLOBALS['Cache']->delete_value('forum_moderator_array');
        }
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}
