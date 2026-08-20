<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Shoutbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Nexus\Database\NexusLock;

/**
 * Legacy public/shoutbox.php migration — the shoutbox / helpbox iframe
 * fragment embedded by the home page (and the login page helpbox).
 *
 * GET /shoutbox.php?type=shoutbox|helpbox renders the message list fragment;
 * GET /shoutbox.php?del=ID removes one message (sbmanage);
 * GET /shoutbox.php?type=..&sent=yes&shbox_text=.. posts a message (helpbox
 * is guest accessible, shoutbox requires a logged-in user).
 */
class ShoutboxController extends Controller
{
    public function web(Request $request)
    {
        /** @var \App\Models\User|null $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        $curUser = $currentUser ? $currentUser->toArray() : [];
        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();

        $lang = get_legacy_lang_file('shoutbox');
        $showHelpbox = (string) get_setting('main.showhelpbox', 'no') === 'yes';
        $canManage = $currentUser && user_can('sbmanage', false, $currentUser->id);

        // Delete a single message when the caller may manage the shoutbox.
        $delId = (int) $request->query('del', 0);
        if ($delId > 0 && $canManage) {
            Shoutbox::query()->where('id', $delId)->delete();
        }

        $where = (string) $request->query('type', '');
        $sentScript = '';

        // Post a message (the legacy form is a GET form targeting the iframe).
        if ($request->query('sent') === 'yes') {
            $text = trim((string) $request->query('shbox_text', ''));
            if ($text === '') {
                // no-op, nothing to shout
            } elseif ($where === 'helpbox') {
                if (! $showHelpbox) {
                    do_log('Someone is hacking shoutbox. helpbox_disabled - IP : ' . getip());
                    return response($lang['text_helpbox_disabled'], 403);
                }
                $type = Shoutbox::TYPE_HELPBOX;
                $userid = 0;
            } elseif ($where === 'shoutbox') {
                $userid = (int) ($curUser['id'] ?? 0);
                if (! $userid) {
                    do_log('Someone is hacking shoutbox. no_permission_to_shoutbox - IP : ' . getip());
                    return response($lang['text_no_permission_to_shoutbox'], 403);
                }
                $type = $request->has('toguest') || ! empty($request->input('toguest'))
                    ? Shoutbox::TYPE_HELPBOX
                    : Shoutbox::TYPE_SHOUTBOX;
            } else {
                // unexpected type: silently ignore
                $type = Shoutbox::TYPE_SHOUTBOX;
                $userid = (int) ($curUser['id'] ?? 0);
            }

            if (! empty($text)) {
                $lockName = $userid > 0 ? "shoutbox:$userid" : 'shoutbox:' . getip();
                $lock = new NexusLock($lockName, 60);
                if (! $lock->acquire()) {
                    return response($lang['speaking_too_often'], 403);
                }
                Shoutbox::query()->create([
                    'userid' => $userid,
                    'date' => time(),
                    'text' => $text,
                    'type' => $type,
                ]);
                $sentScript = "<script type=\"text/javascript\">parent.document.forms['shbox'].shbox_text.value='';</script>";
            }
        }

        $limit = (int) ($curUser['sbnum'] ?? 70);
        if ($limit <= 0) {
            $limit = 70;
        }

        $builder = Shoutbox::query();
        if ($where === 'helpbox' && $showHelpbox) {
            // helpbox content only, no login required
            $builder->where('type', Shoutbox::TYPE_HELPBOX);
        } elseif ($where === 'shoutbox' && $currentUser && (($curUser['hidehb'] ?? '') === 'yes' || ! $showHelpbox)) {
            // shoutbox content, helpbox hidden
            $builder->where('type', Shoutbox::TYPE_SHOUTBOX);
        } elseif (! $currentUser) {
            $html = '<h1>' . htmlspecialchars($lang['std_access_denied']) . '</h1>'
                . '<p>' . htmlspecialchars($lang['std_access_denied_note']) . '</p></body></html>';
            return response($html);
        }
        $rows = $builder->orderByDesc('date')->limit($limit)->get();

        $refresh = (int) ($curUser['sbrefresh'] ?? 120);
        if ($refresh <= 0) {
            $refresh = 120;
        }

        return view('shoutbox', compact('lang', 'where', 'rows', 'canManage', 'refresh', 'sentScript'))
            ->with('pageTitle', $lang['text_del'] ?? 'Shoutbox')
            ->with('curTimetype', $curUser['timetype'] ?? '');
    }
}