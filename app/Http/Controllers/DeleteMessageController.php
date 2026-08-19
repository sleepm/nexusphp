<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Nexus\Database\NexusDB;

class DeleteMessageController extends Controller
{
    /**
     * Single-message deletion. Mirrors legacy public/deletemessage.php so the
     * inbox/sentbox mail links keep working under the Laravel router.
     *
     * GET /deletemessage.php?id=&type= deletes (or un-promotes) a message that
     * belongs to the current user's inbox (`type=in`) or sentbox (`type=out`),
     * then redirects back to messages.php.
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
        $lang = get_legacy_lang_file('deletemessage');

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $id = $request->query('id');
        if (! is_numeric($id) || $id < 1 || floor((float) $id) != (float) $id) {
            abort(400, 'Invalid ID');
        }
        $type = (string) $request->query('type', '');

        $message = Message::query()->where('id', (int) $id)->first(['id', 'sender', 'receiver', 'location']);
        if (! $message) {
            abort(400, $lang['std_bad_message_id'] ?? 'Bad message ID');
        }

        if ($type == 'in') {
            // make sure the message is in the inbox
            if ($message['receiver'] != $curUser['id']) {
                abort(403, $lang['std_not_suggested'] ?? '');
            }
            if ($message['location'] == 1) {
                $message->delete();
            } elseif ($message['location'] == 0) {
                $message->update(['location' => -1]);
            } else {
                abort(400, $lang['std_not_in_inbox'] ?? '');
            }
        } elseif ($type == 'out') {
            // make sure the message is in the sentbox
            if ($message['sender'] != $curUser['id']) {
                abort(403, $lang['std_not_suggested'] ?? '');
            }
            if ($message['location'] == -1) {
                $message->delete();
            } elseif ($message['location'] == 0) {
                $message->update(['location' => 1]);
            } else {
                abort(400, $lang['std_not_in_sentbox'] ?? '');
            }
        } else {
            abort(400, $lang['std_unknown_pm_type'] ?? 'Unknown PM type.');
        }

        NexusDB::cache_del('user_' . $curUser['id'] . '_inbox_count');
        NexusDB::cache_del('user_' . $curUser['id'] . '_outbox_count');
        NexusDB::cache_del('user_' . $curUser['id'] . '_unread_message_count');

        return redirect(get_protocol_prefix() . Setting::getBaseUrl() . '/messages.php' . ($type == 'out' ? '?out=1' : ''));
    }
}