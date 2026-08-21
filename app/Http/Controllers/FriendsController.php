<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Legacy public/friends.php migration — personal friend / blocked-user lists.
 *
 * GET /friends.php renders the current user's friend list and blocked list;
 * ?action=add|delete manipulates the friends / blocks pivot rows (mirrors the
 * legacy add/delete flow incl. the "sure" confirmation step).
 */
class FriendsController extends Controller
{
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
        $lang = get_legacy_lang_file('friends');

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_friends'] = $lang;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $userid = (int) $curUser['id'];
        if (! is_valid_id($userid)) {
            abort(400, $lang['std_invalid_id'].$userid);
        }

        $action = (string) $request->query('action', '');
        if ($action === 'add') {
            return $this->add($request, $curUser, $lang, $userid);
        }
        if ($action === 'delete') {
            return $this->delete($request, $curUser, $lang, $userid);
        }

        return $this->main($request, $curUser, $lang, $userid);
    }

    // ---------------------------------------------------------------- action: add

    private function add(Request $request, array $curUser, array $lang, int $userid)
    {
        $targetid = (int) $request->query('targetid', 0);
        if (! is_valid_id($targetid)) {
            return $this->messagePage($lang['std_error'], $lang['std_invalid_id'].$targetid);
        }

        $type = (string) $request->query('type', '');
        if ($type == 'friend') {
            $table = 'friends';
            $field = 'friendid';
            $frag = 'friends';
        } elseif ($type == 'block') {
            $table = 'blocks';
            $field = 'blockid';
            $frag = 'blocks';
        } else {
            return $this->messagePage($lang['std_error'], $lang['std_unknown_type'].$type);
        }

        if (DB::table($table)->where('userid', $userid)->where($field, $targetid)->exists()) {
            return $this->messagePage(
                $lang['std_error'],
                $lang['std_user_id'].$targetid.$lang['std_already_in'].$table.$lang['std_list']
            );
        }

        DB::table($table)->insert(['userid' => $userid, $field => $targetid]);
        $this->purgeNeighborsCache($userid);

        return redirect(get_protocol_prefix().$GLOBALS['BASEURL']."/friends.php?id=$userid#$frag");
    }

    // --------------------------------------------------------------- action: delete

    private function delete(Request $request, array $curUser, array $lang, int $userid)
    {
        $targetid = (int) $request->query('targetid', 0);
        $sure = (int) $request->query('sure', 0);
        $type = htmlspecialchars((string) $request->query('type', ''));
        $typename = $type == 'friend' ? $lang['text_friend'] : $lang['text_block'];

        if (! is_valid_id($targetid)) {
            return $this->messagePage($lang['std_error'], $lang['std_invalid_id'].$targetid);
        }

        if (! $sure) {
            $link = "friends.php?id=$userid&action=delete&type=$type&targetid=$targetid&sure=1";
            $text = $lang['std_delete_note'].$typename.$lang['std_click']
                ."<a href=\"$link\">".$lang['std_here_if_sure'];
            return $this->messagePage($lang['std_delete'].$type, $text, $lang['std_error'], false);
        }

        if ($type == 'friend') {
            $deleted = DB::table('friends')->where('userid', $userid)->where('friendid', $targetid)->delete();
            if ($deleted == 0) {
                return $this->messagePage($lang['std_error'], $lang['std_no_friend_found'].$targetid);
            }
            $frag = 'friends';
        } elseif ($type == 'block') {
            $deleted = DB::table('blocks')->where('userid', $userid)->where('blockid', $targetid)->delete();
            if ($deleted == 0) {
                return $this->messagePage($lang['std_error'], $lang['std_no_block_found'].$targetid);
            }
            $frag = 'blocks';
        } else {
            return $this->messagePage($lang['std_error'], $lang['std_unknown_type'].$type);
        }

        $this->purgeNeighborsCache($userid);

        return redirect(get_protocol_prefix().$GLOBALS['BASEURL']."/friends.php?id=$userid#$frag");
    }

    // ------------------------------------------------------------- main body

    private function main(Request $request, array $curUser, array $lang, int $userid)
    {
        $ownerName = get_username($userid, true, false);

        $friendRows = DB::table('friends as f')
            ->leftJoin('users as u', 'f.friendid', '=', 'u.id')
            ->where('f.userid', $userid)
            ->orderBy('f.id')
            ->get(['f.friendid as id', 'u.last_access', 'u.class', 'u.avatar', 'u.title']);

        $showAvatars = ($curUser['avatars'] ?? '') == 'yes';
        $friends = [];
        foreach ($friendRows as $friend) {
            $title = $friend->title;
            if (! $title) {
                $title = get_user_class_name($friend->class, false, true, true);
            }
            $avatar = $showAvatars ? htmlspecialchars((string) $friend->avatar) : '';
            if (! $avatar) {
                $avatar = 'pic/default_avatar.png';
            }
            $friends[] = [
                'body1' => get_username($friend->id)." ($title)<br /><br />"
                    .$lang['text_last_seen_on'].gettime($friend->last_access, true, false),
                'body2' => '<a href="friends.php?action=delete&amp;type=friend&amp;targetid='.$friend->id.'">'
                    .$lang['text_remove_from_friends'].'</a>'
                    .'<br /><br /><a href="sendmessage.php?receiver='.$friend->id.'">'
                    .$lang['text_send_pm'].'</a>',
                'avatar' => $avatar,
            ];
        }

        $blockRows = DB::table('blocks')
            ->where('userid', $userid)
            ->orderBy('id')
            ->pluck('blockid');

        $blocks = [];
        foreach ($blockRows as $blockid) {
            $blocks[] = [
                'id' => (int) $blockid,
                'username' => get_username((int) $blockid),
            ];
        }

        return view('friends', [
            'pageTitle' => $lang['head_personal_lists_for'].$curUser['username'],
            'lang' => $lang,
            'ownerName' => $ownerName,
            'friends' => $friends,
            'blocks' => $blocks,
            'canViewUserList' => user_can('viewuserlist'),
        ]);
    }

    // ------------------------------------------------------------------- helpers

    /**
     * Full-page legacy stderr() equivalent rendered through Blade.
     */
    private function messagePage(string $heading, string $text, string $pageTitle = 'Error', bool $htmlstrip = true)
    {
        $content = $this->capture(function () use ($heading, $text, $htmlstrip) {
            stderr($heading, $text, $htmlstrip, false, false, false);
        });

        return view('friends', compact('content') + ['pageTitle' => $pageTitle]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();

        return (string) ob_get_clean();
    }

    /**
     * Legacy public/friends.php deleted the per-user neighbors HTML cache when a
     * friend/block row changed; keep the same behavior for consistency.
     */
    private function purgeNeighborsCache(int $userid): void
    {
        $cachefile = public_path('cache/'.get_langfolder_cookie()."/neighbors/{$userid}.html");
        if (is_file($cachefile)) {
            @unlink($cachefile);
        }
    }
}