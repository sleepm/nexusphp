<?php

namespace App\Http\Controllers;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\Pmbox;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Nexus\Database\NexusDB;

class MessageController extends Controller
{
    const PM_DELETED = 0; // Message was deleted
    const PM_INBOX = 1; // Message located in Inbox for receiver
    const PM_SENTBOX = -1; // GET value for sent box

    /**
     * Web entry point. Mirrors the legacy public/messages.php action dispatch,
     * so the messages inbox/sentbox page can be served by the Laravel router.
     */
    public function web(Request $request)
    {
        [$curUser, $lang] = $this->bootstrap($request);
        $action = (string) $request->query('action', '');
        if ($action === '') {
            $action = (string) $request->input('action', '');
        }
        if ($action === '') {
            $action = 'viewmailbox';
        }
        switch ($action) {
            case 'viewmailbox':
                return $this->webViewMailbox($request, $curUser, $lang);
            case 'viewmessage':
                return $this->webViewMessage($request, $curUser, $lang);
            case 'moveordel':
                return $this->webMoveOrDel($request, $curUser, $lang);
            case 'forward':
                return $this->webForward($request, $curUser, $lang);
            case 'editmailboxes':
                return $this->webEditMailboxes($request, $curUser, $lang);
            case 'editmailboxes2':
                return $this->webEditMailboxes2($request, $curUser, $lang);
            case 'deletemessage':
                return $this->webDeleteMessage($request, $curUser, $lang);
            default:
                abort(400, $lang['std_no_action'] ?? 'No action.');
        }
    }

    /**
     * Authenticate the request, set the legacy globals used by the shared
     * helpers, and return the current user array + messages lang file.
     */
    private function bootstrap(Request $request): array
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        if (! $currentUser) {
            abort(401);
        }
        if ($currentUser->parked == 'yes') {
            abort(403, 'Your account is parked.');
        }
        $curUser = $currentUser->toArray();

        $lang = get_legacy_lang_file('messages');

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_messages'] = $lang;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        return [$curUser, $lang];
    }

    /**
     * Inbox / sentbox / custom mailbox listing with search, mirrors legacy
     * public/messages.php `action=viewmailbox`.
     */
    private function webViewMailbox(Request $request, array $curUser, array $lang)
    {
        $mailbox = (int) $request->query('box', 0);
        if (! $mailbox) {
            $mailbox = self::PM_INBOX;
        }

        // Get Mailbox Name
        if ($mailbox != self::PM_INBOX && $mailbox != self::PM_SENTBOX) {
            $pmbox = Pmbox::query()->where('userid', $curUser['id'])->where('boxnumber', $mailbox)->first();
            if (! $pmbox) {
                abort(403, $lang['std_invalid_mailbox'] ?? 'Invalid Mailbox');
            }
            $mailboxName = htmlspecialchars($pmbox->name);
        } else {
            $mailboxName = $mailbox == self::PM_INBOX ? ($lang['text_inbox'] ?? 'Inbox') : ($lang['text_sentbox'] ?? 'Sentbox');
        }

        if ($mailbox != self::PM_SENTBOX) {
            $senderReceiver = $lang['text_sender'] ?? 'Sender';
        } else {
            $senderReceiver = $lang['text_receiver'] ?? 'Receiver';
        }

        $content = $this->messageMenu($curUser, $lang, $mailbox);

        $content .= '<table border="0" cellpadding="4" cellspacing="0" width="737">';
        $content .= '<tr><td class=colhead align=left>' . ($lang['col_search_message'] ?? 'Search Message') . '</td></tr>';
        $content .= '<tr><td class=toolbox align=center>' . $this->insertJumpTo($curUser, $lang, $mailbox, $request) . '</td></tr>';
        $content .= '</table>';

        // search
        $keyword = mysql_real_escape_string(trim((string) ($request->query('keyword', ''))));
        $place = (string) $request->query('place', '');
        $unread = (string) $request->query('unread', '');
        $wherea = '';
        if ($keyword) {
            switch ($place) {
                case 'body':
                    $wherea = " AND msg LIKE '%$keyword%' ";
                    break;
                case 'title':
                    $wherea = " AND subject LIKE '%$keyword%' ";
                    break;
                default:
                    $wherea = " AND (msg LIKE '%$keyword%' or subject LIKE '%$keyword%') ";
                    break;
            }
        }
        if ($unread) {
            if ($unread == 'yes') {
                $wherea .= " AND unread = 'yes' ";
            } elseif ($unread == 'no') {
                $wherea .= " AND unread = 'no' ";
            }
        }

        $perpage = ($curUser['pmnum'] ? (int) $curUser['pmnum'] : 20);
        $boxQueryString = ($mailbox ? "&box=" . $mailbox : "") . ($place ? "&place=" . $place : "") . ($keyword ? "&keyword=" . rawurlencode($keyword) : "") . ($unread ? "&unread=" . $unread : "") . "&";

        if ($mailbox != self::PM_SENTBOX) {
            $count = Message::query()->where('receiver', $curUser['id'])->where('location', $mailbox)->when($wherea !== '', fn ($q) => $q->whereRaw(substr($wherea, 5)))->count();
            [$pagertop, $pagerbottom, , , ] = pager($perpage, $count, "?action=viewmailbox" . $boxQueryString);
            $messages = Message::query()->where('receiver', $curUser['id'])->where('location', $mailbox)->when($wherea !== '', fn ($q) => $q->whereRaw(substr($wherea, 5)))->orderBy('id', 'desc')->paginate($perpage);
        } else {
            $count = Message::query()->where('sender', $curUser['id'])->where('saved', 'yes')->when($wherea !== '', fn ($q) => $q->whereRaw(substr($wherea, 5)))->count();
            [$pagertop, $pagerbottom, , , ] = pager($perpage, $count, "?action=viewmailbox" . $boxQueryString);
            $messages = Message::query()->where('sender', $curUser['id'])->where('saved', 'yes')->when($wherea !== '', fn ($q) => $q->whereRaw(substr($wherea, 5)))->orderBy('id', 'desc')->paginate($perpage);
        }

        if ($messages->isEmpty()) {
            $content .= '<p align="center">' . ($lang['text_no_messages'] ?? 'No Messages.') . "</p>\n";
        } else {
            $content .= $pagertop;
            $content .= '<form action="messages.php" method="post">';
            $content .= '<input type="hidden" name="action" value="moveordel">';
            $content .= '<table border="0" cellpadding="4" cellspacing="0" width="737">';
            $content .= '<tr>';
            $content .= '<td width="1%" class="colhead" align="center">' . ($lang['col_status'] ?? 'Status') . '</td>';
            $content .= '<td class="colhead" align="left">' . ($lang['col_subject'] ?? 'Subject') . ' </td>';
            $content .= '<td width="35%" class="colhead" align="left">' . $senderReceiver . '</td>';
            $content .= '<td width="1%" class="colhead" align="center"><img class="time" src="pic/trans.gif" alt="time" title="' . ($lang['col_date'] ?? 'Date') . '" /></td>';
            $content .= '<td width="1%" class="colhead" align="center">' . ($lang['col_act'] ?? 'Act.') . '</td>';
            $content .= '</tr>';

            foreach ($messages as $row) {
                // Get Sender/Receiver Username
                if ($row['sender'] != 0) {
                    if ($mailbox != self::PM_SENTBOX) {
                        $username = get_username($row['sender']);
                    } else {
                        $username = get_username($row['receiver']);
                    }
                } else {
                    $username = $lang['text_system'] ?? 'System';
                }
                $subject = htmlspecialchars($row['subject']);
                if (strlen($subject) <= 0) {
                    $subject = $lang['text_no_subject'] ?? 'No Subject';
                }

                if ($row['unread'] == 'yes') {
                    $content .= "<tr>\n<td class=rowfollow align=center><img class=\"unreadpm\" src=\"pic/trans.gif\" alt=\"Unread\" title=" . ($lang['title_unread'] ?? 'Unread') . " /></td>\n";
                } else {
                    $content .= "<tr>\n<td class=rowfollow align=center><img class=\"readpm\" src=\"pic/trans.gif\" alt=\"Read\" title=" . ($lang['title_read'] ?? 'Read') . " /></td>\n";
                }
                $content .= '<td class=rowfollow align=left><a href="messages.php?action=viewmessage&id=' . $row['id'] . '">' . $subject . "</a></td>\n";
                $content .= '<td class=rowfollow align=left>' . $username . "</td>\n";
                $content .= '<td class=rowfollow nowrap>' . gettime($row['added'], true, false) . "</td>\n";
                $content .= '<td class=rowfollow><input class=checkbox type="checkbox" name="messages[]" value="' . $row['id'] . '"></td>' . "\n</tr>\n";
            }

            $content .= '<tr class="colhead">';
            $content .= '<td colspan="5" align="right" class="colhead"><input class=btn type="button" value="' . ($lang['input_check_all'] ?? 'Check All') . '" onClick="this.value=check(form,\'' . ($lang['input_check_all'] ?? 'Check All') . '\',\'' . ($lang['input_uncheck_all'] ?? 'Uncheck All') . '\')">';
            if ($mailbox != self::PM_SENTBOX) {
                $content .= '<input class=btn type="submit" name="markread" value="' . ($lang['submit_mark_as_read'] ?? 'Mark as read') . '">';
            }
            $content .= '<input class=btn type="submit" name="delete" value=' . ($lang['submit_delete'] ?? 'Delete') . '>';
            if ($mailbox != self::PM_SENTBOX) {
                $content .= ($lang['text_or'] ?? ' or ');
                $content .= '<input class=btn type="submit" name="move" value="' . ($lang['submit_move_to'] ?? 'Move to') . '"> <select name="box"><option value="1">' . ($lang['text_inbox'] ?? 'Inbox') . '</option>';
                $pmboxes = Pmbox::query()->where('userid', $curUser['id'])->orderBy('boxnumber')->get();
                foreach ($pmboxes as $pmbox) {
                    $content .= '<option value="' . $pmbox['boxnumber'] . '">' . htmlspecialchars($pmbox['name']) . "</option>\n";
                }
            }
            $content .= '</select>';

            $content .= '</td></tr></table></form>';
            $content .= '<tr><td class=toolbox colspan=5>';
            $content .= '<div align="center"><img class="unreadpm" src="pic/trans.gif" alt="Unread" title="' . ($lang['title_unread'] ?? 'Unread') . '" /><a href="messages.php?action=viewmailbox&box=' . $mailbox . '&unread=yes">' . ($lang['text_unread_messages'] ?? 'Unread Messages.') . '</a>';
            $content .= '<img class="readpm" src="pic/trans.gif" alt="Read" title="' . ($lang['title_read'] ?? 'Read') . '" /><a href="messages.php?action=viewmailbox&box=' . $mailbox . '&unread=no">' . ($lang['text_read_messages'] ?? 'Read Messages.') . '</a>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
            $content .= '<a href="messages.php?action=editmailboxes"><b>' . ($lang['text_mailbox_manager'] ?? 'Mailbox Manager') . '</a></b></div></td></tr>';
            $content .= $pagerbottom;
        }

        $pageTitle = $mailboxName;
        $content = $this->wrapContent($content);
        return view('messages', compact('pageTitle', 'content', 'lang'));
    }

    /**
     * View a single message, mirrors legacy public/messages.php `action=viewmessage`.
     */
    private function webViewMessage(Request $request, array $curUser, array $lang)
    {
        $pmId = (int) $request->query('id', 0);
        if (! $pmId) {
            abort(403, $lang['std_no_permission'] ?? 'Permission denied.');
        }

        $message = Message::query()->where('id', $pmId)
            ->where(function ($query) use ($curUser) {
                $query->where('receiver', $curUser['id'])
                    ->orWhere(function ($query) use ($curUser) {
                        $query->where('sender', $curUser['id'])->where('saved', 'yes');
                    });
            })->first();
        if (! $message) {
            abort(403, $lang['std_no_permission'] ?? 'Permission denied.');
        }

        $messageArr = $message->toArray();
        if ($messageArr['sender'] == $curUser['id']) {
            // Display to
            $sender = get_username($messageArr['receiver']);
            $reply = '';
            $from = $lang['text_to'] ?? 'To';
        } else {
            $from = $lang['text_from'] ?? 'From';
            if ($messageArr['sender'] == 0) {
                $sender = $lang['text_system'] ?? 'System';
                $reply = '';
            } else {
                $sender = get_username($messageArr['sender']);
                $reply = ' [ <a href="sendmessage.php?receiver=' . $messageArr['sender'] . '&replyto=' . $pmId . '">' . ($lang['text_reply'] ?? 'Reply') . '</a> ]';
            }
        }
        $body = format_comment($messageArr['msg'], true);
        $added = $messageArr['added'];
        if ($messageArr['sender'] == $curUser['id']) {
            $unread = ($messageArr['unread'] == 'yes' ? '<span style="color: #FF0000;"><b>' . ($lang['text_new'] ?? '(New)') . '</b></span>' : '');
        } else {
            $unread = '';
        }
        $subject = htmlspecialchars($messageArr['subject']);
        if (strlen($subject) <= 0) {
            $subject = $lang['text_no_subject'] ?? 'No Subject';
        }

        // Mark message read
        Message::query()->where('id', $pmId)->where('receiver', $curUser['id'])->update(['unread' => 'no']);
        NexusDB::cache_del('user_' . $curUser['id'] . '_unread_message_count');

        $mailbox = ($messageArr['sender'] == $curUser['id'] ? self::PM_SENTBOX : $messageArr['location']);
        $content = '<h1>' . $subject . '</h1>';
        $content .= $this->messageMenu($curUser, $lang, $mailbox);

        $content .= '<table width="737" border="0" cellpadding="4" cellspacing="0">';
        $content .= '<tr>';
        $content .= '<td width="50%" class="colhead" align="left">' . $from . '</td>';
        $content .= '<td width="50%" class="colhead" align="left">' . ($lang['col_date'] ?? 'Date') . '</td>';
        $content .= '</tr>';
        $content .= '<tr>';
        $content .= '<td class="rowfollow" align="left">' . $sender . '</td>';
        $content .= '<td class="rowfollow" align="left">' . gettime($added, true, false) . '&nbsp;&nbsp;' . $unread . '</td>';
        $content .= '</tr>';
        $content .= '<tr>';
        $content .= '<td colspan="2" align="left">' . $body . '</td>';
        $content .= '</tr>';
        $content .= '<tr>';
        $content .= '<td align=left>';
        if ($messageArr['sender'] != $curUser['id']) {
            $content .= '<form action="messages.php" method="post"><input type="hidden" name="action" value="moveordel"><input type="hidden" name="id" value=' . $pmId . '>';
            $content .= '<input type="submit" name="move" value="' . ($lang['submit_move_to'] ?? 'Move to') . '"><select name="box"><option value="1">' . ($lang['text_inbox'] ?? 'Inbox') . '</option>';
            $pmboxes = Pmbox::query()->where('userid', $curUser['id'])->orderBy('boxnumber')->get();
            foreach ($pmboxes as $pmbox) {
                $content .= '<option value="' . $pmbox['boxnumber'] . '">' . htmlspecialchars($pmbox['name']) . "</option>\n";
            }
            $content .= '</select></form>';
        }
        $content .= '</td><td align="right"><font color=white>[ ';
        $content .= '<a href="messages.php?action=deletemessage&id=' . $pmId . '">' . ($lang['text_delete'] ?? 'Delete') . '</a> ]' . $reply;
        $content .= ' [ <a href="messages.php?action=forward&id=' . $pmId . '">' . ($lang['text_forward_pm'] ?? 'Forward PM') . '</a> ]</font></td>';
        $content .= '</tr>';
        $content .= '</table>';

        $pageTitle = 'PM (' . $subject . ')';
        $content = $this->wrapContent($content);
        return view('messages', compact('pageTitle', 'content', 'lang'));
    }

    /**
     * Move / mark-read / delete messages, mirrors legacy public/messages.php
     * `action=moveordel` (POST).
     */
    private function webMoveOrDel(Request $request, array $curUser, array $lang)
    {
        $pmId = (int) $request->input('id', 0);
        $pmBox = (int) $request->input('box', 0);
        $pmMessages = $request->input('messages', []);

        if ($request->input('markread')) {
            if ($pmId) {
                // Mark a single message as read
                Message::query()->where('id', $pmId)->where('receiver', $curUser['id'])->limit(1)->update(['unread' => 'no']);
            } else {
                if (empty($pmMessages)) {
                    abort(400, $GLOBALS['lang_functions']['select_at_least_one_record'] ?? 'Select at least one record!');
                }
                // Mark multiple messages as read
                Message::query()->whereIn('id', $pmMessages)->where('receiver', $curUser['id'])->update(['unread' => 'no']);
            }
            NexusDB::cache_del('user_' . $curUser['id'] . '_unread_message_count');

            return redirect('messages.php?action=viewmailbox&box=' . $pmBox);
        } elseif ($request->input('move')) {
            if ($pmId) {
                // Move a single message
                Message::query()->where('id', $pmId)->where('receiver', $curUser['id'])->limit(1)->update(['location' => $pmBox]);
            } else {
                // Move multiple messages
                Message::query()->whereIn('id', $pmMessages)->where('receiver', $curUser['id'])->update(['location' => $pmBox]);
            }
            NexusDB::cache_del('user_' . $curUser['id'] . '_unread_message_count');
            NexusDB::cache_del('user_' . $curUser['id'] . '_inbox_count');
            NexusDB::cache_del('user_' . $curUser['id'] . '_outbox_count');

            return redirect('messages.php?action=viewmailbox&box=' . $pmBox);
        } elseif ($request->input('delete')) {
            $processed = 0;
            if ($pmId) {
                // Delete a single message
                $processed = $this->deleteMessage($pmId, $curUser['id']);
            } else {
                if (empty($pmMessages)) {
                    abort(400, $lang['std_no_message_selected'] ?? 'No message selected.');
                }
                foreach ($pmMessages as $id) {
                    $processed += $this->deleteMessage((int) $id, $curUser['id']);
                }
            }
            if ($processed == 0) {
                abort(400, $lang['std_cannot_delete_messages'] ?? 'Messages couldn\'t be deleted!');
            }

            return redirect('messages.php?action=viewmailbox');
        }

        abort(400, $lang['std_no_action'] ?? 'No action');
    }

    /**
     * Apply the single-message delete rules shared by moveordel & deletemessage.
     * Returns the number of affected messages.
     */
    private function deleteMessage(int $pmId, int $uid): int
    {
        $message = Message::query()->find($pmId);
        if (! $message) {
            return 0;
        }
        $affected = 0;
        if ($message['receiver'] == $uid && $message['saved'] == 'no') {
            $message->delete();
            $affected = 1;
            NexusDB::cache_del('user_' . $uid . '_unread_message_count');
            NexusDB::cache_del('user_' . $uid . '_inbox_count');
        } elseif ($message['sender'] == $uid && $message['location'] == self::PM_DELETED) {
            $message->delete();
            $affected = 1;
            NexusDB::cache_del('user_' . $uid . '_outbox_count');
        } elseif ($message['receiver'] == $uid && $message['saved'] == 'yes') {
            $message->update(['location' => self::PM_DELETED, 'unread' => 'no']);
            $affected = 1;
            NexusDB::cache_del('user_' . $uid . '_unread_message_count');
            NexusDB::cache_del('user_' . $uid . '_inbox_count');
        } elseif ($message['sender'] == $uid && $message['location'] != self::PM_DELETED) {
            $message->update(['saved' => 'no']);
            $affected = 1;
            NexusDB::cache_del('user_' . $uid . '_outbox_count');
        }
        return $affected;
    }

    /**
     * Forward form, mirrors legacy public/messages.php `action=forward`.
     */
    private function webForward(Request $request, array $curUser, array $lang)
    {
        $pmId = (int) $request->query('id', 0);

        $message = Message::query()->where('id', $pmId)
            ->where(function ($query) use ($curUser) {
                $query->where('receiver', $curUser['id'])->orWhere('sender', $curUser['id']);
            })->first();
        if (! $message) {
            abort(403, $lang['std_no_permission_forwarding'] ?? 'You do not have permission to forward this message.');
        }
        $messageArr = $message->toArray();

        // Prepare variables
        $subjectH = 'Fwd: ' . htmlspecialchars($messageArr['subject']);
        $from = $messageArr['receiver'];
        $orig = $messageArr['sender'];

        $fromName = get_username($from);
        if ($orig == 0) {
            $origName = $origName2 = $lang['text_system'] ?? 'System';
        } else {
            $origName = get_username($orig);
            $origUser = User::query()->find($orig, ['username']);
            $origName2 = $origUser->username ?? '';
        }

        $body = '-------- Original Message from ' . $origName2 . ' --------<br />' . format_comment($messageArr['msg']);

        $content = '<h1 align="center">' . ($lang['text_forward_pm'] ?? 'Forward PM') . '</h1>';
        $content .= '<table border="0" cellpadding="4" cellspacing="0" width="737">';
        $content .= '<form action="takemessage.php" method="post">';
        $content .= '<input type="hidden" name="forward" value="1">';
        $content .= '<input type="hidden" name="origmsg" value="' . $pmId . '">';
        $content .= '<tr>';
        $content .= '<td class="rowhead" align="right">' . ($lang['row_to'] ?? 'To: ') . '</td>';
        $content .= '<td class="rowfollow" align=left><input type="text" name="to" style="width: 200px"></td>';
        $content .= '</tr>';
        $content .= '<tr>';
        $content .= '<td class="rowhead" align="right">' . ($lang['row_original_receiver'] ?? 'Original Receiver:') . '</td>';
        $content .= '<td class="rowfollow" align=left>' . $fromName . '</td>';
        $content .= '</tr>';
        $content .= '<tr>';
        $content .= '<td class="rowhead" align="right">' . ($lang['row_original_sender'] ?? 'Original Sender:') . '</td>';
        $content .= '<td class="rowfollow" align=left>' . $origName . '</td>';
        $content .= '</tr>';
        $content .= '<tr>';
        $content .= '<td class="rowhead" align="right">' . ($lang['row_subject'] ?? 'Subject:') . '</td>';
        $content .= '<td class="rowfollow" align=left><input type="text" name="subject" value="' . $subjectH . '" style="width: 500px"></td>';
        $content .= '</tr>';
        $content .= '<tr>';
        $content .= '<td class="rowhead" align="right" valign="top"><nobr>' . ($lang['row_message'] ?? 'Message:') . '</nobr></td>';
        $content .= '<td class="rowfollow" align=left><textarea name="body" style="width: 500px" rows="8"></textarea><br />' . $body . '</td>';
        $content .= '</tr>';
        $content .= '<tr>';
        $content .= '<td class=toolbox colspan="2" align="center"><input class=checkbox type="checkbox" name="save" value="yes"' . ($curUser['savepms'] == 'yes' ? ' checked' : '') . '>' . ($lang['checkbox_save_message'] ?? 'Save Message ') . '&nbsp;';
        $content .= '<input type="submit" class="btn" value=' . ($lang['submit_forward'] ?? 'Forward') . '></td>';
        $content .= '</tr>';
        $content .= '</form>';
        $content .= '</table>';

        $pageTitle = $subjectH;
        $content = $this->wrapContent($content);
        return view('messages', compact('pageTitle', 'content', 'lang'));
    }

    /**
     * Mailbox manager, mirrors legacy public/messages.php `action=editmailboxes`.
     */
    private function webEditMailboxes(Request $request, array $curUser, array $lang)
    {
        $content = '<h1>' . ($lang['text_editing_mailboxes'] ?? 'Editing Mailboxes') . '</h1>';
        $content .= '<table width="737" border="0" cellpadding="4" cellspacing="0">';
        $content .= '<tr>';
        $content .= '<td class="colhead" align="left">' . ($lang['text_add_mailboxes'] ?? 'Add Mailboxes') . '</td>';
        $content .= '</tr>';
        $content .= '<tr>';
        $content .= '<td align=left>' . ($lang['text_extra_mailboxes_note'] ?? 'You may add extra mailboxes. You do not have to use all the input boxes.') . '<br />';
        $content .= '<form action="messages.php" method="get">';
        $content .= '<input type="hidden" name="action" value="editmailboxes2">';
        $content .= '<input type="hidden" name="action2" value="add">';
        $content .= '<input type="text" name="new1" size="40" maxlength="14"><br />';
        $content .= '<input type="text" name="new2" size="40" maxlength="14"><br />';
        $content .= '<input type="text" name="new3" size="40" maxlength="14"><br />';
        $content .= '<input type="submit" value="' . ($lang['submit_add'] ?? 'Add') . '">';
        $content .= '</form></td>';
        $content .= '</tr>';
        $content .= '<tr>';
        $content .= '<td class="colhead" align=left>' . ($lang['text_edit_mailboxes'] ?? 'Edit Mailboxes') . '</td>';
        $content .= '</tr>';
        $content .= '<tr>';
        $content .= '<td align=left>' . ($lang['text_edit_mailboxes_note'] ?? 'You may edit the names, or delete the name to delete this virtual directory.') . '<br />';
        $content .= '<form action="messages.php" method="get">';
        $content .= '<input type="hidden" name="action" value="editmailboxes2">';
        $content .= '<input type="hidden" name="action2" value="edit">';

        $pmboxes = Pmbox::query()->where('userid', $curUser['id'])->get();
        if ($pmboxes->isEmpty()) {
            $content .= '<span align="center"><b>' . ($lang['text_no_mailboxes_to_edit'] ?? 'There are no mailboxes to edit.') . '</b></span>';
        } else {
            foreach ($pmboxes as $row) {
                $content .= '<input type="text" name="edit' . $row['id'] . '" value="' . htmlspecialchars($row['name']) . '" size="40" maxlength="14"><br />' . "\n";
            }
            $content .= '<input type="submit" value="' . ($lang['submit_edit'] ?? 'Edit') . '">';
        }
        $content .= '</form></td>';
        $content .= '</tr>';
        $content .= '</table>';

        $pageTitle = $lang['head_editing_mailboxes'] ?? 'Editing Mailboxes';
        $content = $this->wrapContent($content);
        return view('messages', compact('pageTitle', 'content', 'lang'));
    }

    /**
     * Add / edit / delete custom mailboxes, mirrors legacy `action=editmailboxes2`.
     */
    private function webEditMailboxes2(Request $request, array $curUser, array $lang)
    {
        $action2 = (string) $request->query('action2', '');
        if ($action2 === '') {
            abort(400, $lang['std_no_action'] ?? 'No action');
        }

        if ($action2 == 'add') {
            $nameone = (string) $request->query('new1', '');
            $nametwo = (string) $request->query('new2', '');
            $namethree = (string) $request->query('new3', '');

            // Get current max box number
            $box = (int) Pmbox::query()->where('userid', $curUser['id'])->max('boxnumber');
            if ($box < 2) {
                $box = 1;
            }
            foreach ([$nameone, $nametwo, $namethree] as $name) {
                if (strlen($name) > 0) {
                    ++$box;
                    Pmbox::query()->create([
                        'userid' => $curUser['id'],
                        'name' => $name,
                        'boxnumber' => $box,
                    ]);
                }
            }
            return redirect('messages.php?action=editmailboxes');
        }

        // action2 == edit (the legacy trailing `if ($action2 == "edit");` is a
        // no-op, so any non-add action2 runs this block)
        $pmboxes = Pmbox::query()->where('userid', $curUser['id'])->get();
        if ($pmboxes->isEmpty()) {
            abort(400, $lang['std_error'] ?? 'Error', $lang['text_no_mailboxes_to_edit'] ?? 'There are no mailboxes to edit.');
        }
        foreach ($pmboxes as $row) {
            if ($request->has('edit' . $row['id'])) {
                if ($request->query('edit' . $row['id']) != $row['name']) {
                    if (strlen((string) $request->query('edit' . $row['id'])) > 0) {
                        // Edit name
                        Pmbox::query()->where('id', $row['id'])->limit(1)->update(['name' => (string) $request->query('edit' . $row['id'])]);
                    } else {
                        // Delete box + relocate its messages
                        Pmbox::query()->where('id', $row['id'])->limit(1)->delete();
                        Message::query()->where('saved', 'yes')->where('location', $row['boxnumber'])->where('receiver', $curUser['id'])->update(['location' => 0]);
                        Message::query()->where('saved', 'yes')->where('sender', $curUser['id'])->update(['saved' => 'no']);
                        Message::query()->where('saved', 'no')->where('location', $row['boxnumber'])->where('receiver', $curUser['id'])->delete();
                        Message::query()->where('location', 0)->where('saved', 'yes')->where('sender', $curUser['id'])->delete();
                    }
                }
            }
        }
        return redirect('messages.php?action=editmailboxes');
    }

    /**
     * Delete a single message, mirrors legacy `action=deletemessage`.
     */
    private function webDeleteMessage(Request $request, array $curUser, array $lang)
    {
        $pmId = (int) $request->query('id', 0);

        $message = Message::query()->find($pmId);
        if (! $message) {
            abort(400, $lang['std_no_message_id'] ?? 'No message with this ID.');
        }

        $processed = $this->deleteMessage($pmId, $curUser['id']);
        if (! $processed) {
            abort(400, $lang['std_could_not_delete_message'] ?? 'Could not delete message.');
        }
        return redirect('messages.php?action=viewmailbox&id=' . $message['location']);
    }

    /**
     * Send a PM, mirrors legacy public/takemessage.php.
     */
    public function webTakeMessage(Request $request)
    {
        [$curUser] = $this->bootstrap($request);

        $lang = get_legacy_lang_file('takemessage');
        $GLOBALS['lang_takemessage'] = $lang;
        $GLOBALS['SITEEMAIL'] = (string) get_setting('main.SITEEMAIL', '');
        $GLOBALS['smtptype'] = Setting::getSmtpType();
        $GLOBALS['emailnotify_smtp'] = (string) get_setting('smtp.emailnotify', 'no');

        if (! $request->isMethod('POST')) {
            abort(403, $lang['std_permission_denied'] ?? 'Permission Denied!');
        }

        $origmsg = (int) $request->input('origmsg', 0);
        $msg = trim((string) $request->input('body', ''));

        if ($request->input('forward') == 1) {
            // Forwarding an existing message
            if (! $origmsg) {
                abort(400, $lang['std_invalid_id'] ?? 'Invalid ID');
            }
            $origMsg = Message::query()
                ->where('id', $origmsg)
                ->where(function ($query) use ($curUser) {
                    $query->where('receiver', $curUser['id'])->orWhere('sender', $curUser['id']);
                })
                ->first();
            if (! $origMsg) {
                abort(403, $lang['std_no_permission_forwarding'] ?? 'You do not have permission to forward this message.');
            }
            $to = trim((string) $request->input('to', ''));
            if (! $to) {
                abort(400, $lang['std_must_enter_username'] ?? 'You must enter the username to whom you want to forward the message.');
            }
            $receiverUser = User::query()->whereRaw('LOWER(username) = LOWER(?)', [$to])->first();
            if (! $receiverUser) {
                $langFunctions = $GLOBALS['lang_functions'] ?? get_legacy_lang_file('functions');
                abort(400, ($langFunctions['std_no_user_named'] ?? 'No user with that name.') . "'" . $to . "'");
            }
            $receiver = (int) $receiverUser->id;
            $locale = get_user_locale($receiver);
            $origMsgArr = $origMsg->toArray();
            if ($origMsgArr['sender'] == 0) {
                $origfrom = nexus_trans('message.msg_system', [], $locale);
            } else {
                $origfrom = '[url=userdetails.php?id=' . $origMsgArr['sender'] . ']' . get_plain_username($origMsgArr['sender']) . '[/url]';
            }
            $msg = '-------- ' . nexus_trans('message.msg_original_message_from', [], $locale) . $origfrom . " --------\n" . $origMsgArr['msg'] . "\n\n" . ($msg ? '-------- [url=userdetails.php?id=' . $curUser['id'] . ']' . $curUser['username'] . "[/url][i] Wrote at " . date('Y-m-d H:i:s') . ":[/i] --------\n" . $msg : '');
        } else {
            $receiver = (int) $request->input('receiver', 0);
            if (! is_valid_id($receiver) || ($origmsg && ! is_valid_id($origmsg))) {
                abort(400, $lang['std_invalid_id'] ?? 'Invalid ID');
            }
            if (! $msg) {
                abort(400, $lang['std_please_enter_something'] ?? 'Please enter something!');
            }
        }

        $save = $request->input('save');
        $returnto = (string) $request->input('returnto', '');

        // Anti Flood Code: a member can only send one PM every 10 seconds.
        if (! user_can('staffmem')) {
            if (strtotime((string) $curUser['last_pm']) > (TIMENOW - 10)) {
                $secs = 60 - (TIMENOW - strtotime((string) $curUser['last_pm']));
                abort(429, ($lang['std_message_flooding_denied'] ?? 'Message Flooding Not Allowed. Please wait ') . $secs . ($lang['std_before_sending_pm'] ?? ' second(s) before sending PM.'));
            }
        }

        $save = ($save == 'yes') ? 'yes' : 'no';

        $user = User::query()->where('id', $receiver)
            ->select(['id', 'username', 'parked', 'email', 'acceptpms', 'notifs'])
            ->first();
        if (! $user) {
            abort(400, $lang['std_user_not_exist'] ?? 'No user with this ID');
        }
        $recipient = $user->toArray();

        // Make sure the recipient wants this message
        if (! user_can('staffmem')) {
            if ($recipient['parked'] == 'yes') {
                abort(403, ($lang['std_refused'] ?? 'Refused') . ' - ' . ($lang['std_account_parked'] ?? 'This account is parked.'));
            }
            if ($recipient['acceptpms'] == 'yes') {
                $blocked = DB::table('blocks')->where('userid', $receiver)->where('blockid', $curUser['id'])->exists();
                if ($blocked) {
                    abort(403, ($lang['std_refused'] ?? 'Refused') . ' - ' . ($lang['std_user_blocks_your_pms'] ?? 'This user has blocked PMs from you.'));
                }
            } elseif ($recipient['acceptpms'] == 'friends') {
                $isFriend = DB::table('friends')->where('userid', $receiver)->where('friendid', $curUser['id'])->exists();
                if (! $isFriend) {
                    abort(403, ($lang['std_refused'] ?? 'Refused') . ' - ' . ($lang['std_user_accepts_friends_pms'] ?? 'This user only accepts PMs from users in his friends list.'));
                }
            } elseif ($recipient['acceptpms'] == 'no') {
                abort(403, ($lang['std_refused'] ?? 'Refused') . ' - ' . ($lang['std_user_blocks_all_pms'] ?? 'This user does not accept PMs.'));
            }
        }

        $subject = trim((string) $request->input('subject', ''));

        $messageRecord = Message::add([
            'sender' => $curUser['id'],
            'receiver' => $receiver,
            'msg' => $msg,
            'subject' => $subject,
            'added' => now(),
            'saved' => $save,
            'location' => 1,
        ]);

        NexusDB::cache_del('user_' . $curUser['id'] . '_outbox_count');

        $msgid = $messageRecord->id;
        $date = date('Y-m-d H:i:s');
        // Update last PM sent...
        User::query()->where('id', $curUser['id'])->update(['last_pm' => now()]);

        // Send notification email when the recipient opted in.
        if ($GLOBALS['emailnotify_smtp'] == 'yes' && $GLOBALS['smtptype'] != 'none') {
            $sm = strpos((string) $recipient['notifs'], '[pm]') !== false;
            if ($sm) {
                $username = trim($curUser['username']);
                $msgReceiver = trim($recipient['username']);
                $prefix = get_protocol_prefix();
                $locale = get_user_locale($recipient['id']);
                $title = $GLOBALS['SITENAME'] . ' ' . nexus_trans('message.mail_received_pm_from', [], $locale) . $username . '!';
                $mailDear = nexus_trans('message.mail_dear', [], $locale);
                $mailYouReceivedAPm = nexus_trans('message.mail_you_received_a_pm', [], $locale);
                $mailSender = nexus_trans('message.mail_sender', [], $locale);
                $mailSubject = nexus_trans('message.mail_subject', [], $locale);
                $mailDate = nexus_trans('message.mail_date', [], $locale);
                $mailYouFollowingUrl = nexus_trans('message.mail_use_following_url', [], $locale);
                $mailHere = nexus_trans('message.mail_here', [], $locale);
                $mailYouFollowingUrl1 = nexus_trans('message.mail_use_following_url_1', [], $locale);
                $mailYours = nexus_trans('message.mail_yours', [], $locale);
                $siteName = Setting::getSiteName();
                $mailTheSiteTeam = sprintf(nexus_trans('message.mail_the_site_team', [], $locale), $siteName);
                $body = <<<EOD
                {$mailDear}$msgReceiver,

                {$mailYouReceivedAPm}

                {$mailSender}: $username
                {$mailSubject}: $subject
                {$mailDate}: $date

                {$mailYouFollowingUrl}<b><a href="javascript:void(null)" onclick="window.open('$prefix{$GLOBALS['BASEURL']}/messages.php?action=viewmessage&id=$msgid')">{$mailHere}</a></b>{$mailYouFollowingUrl1}<br />
                $prefix{$GLOBALS['BASEURL']}/messages.php?action=viewmessage&id=$msgid

                ------{$mailYours}
                {$mailTheSiteTeam}
                EOD;

                sent_mail($recipient['email'], $GLOBALS['SITENAME'], $GLOBALS['SITEEMAIL'], $title, str_replace('<br />', '<br />', nl2br($body)), 'sendmessage', false, false, '');
            }
        }

        $delete = $request->input('delete');

        if ($origmsg) {
            if ($delete == 'yes') {
                // Make sure receiver of $origmsg is current user
                $origTarget = Message::query()->where('id', $origmsg)->first();
                if ($origTarget) {
                    $arr = $origTarget->toArray();
                    if ($arr['receiver'] != $curUser['id']) {
                        abort(400, "w00t - This shouldn't happen.");
                    }
                    if ($arr['saved'] == 'no') {
                        Message::query()->where('id', $origmsg)->delete();
                    } elseif ($arr['saved'] == 'yes') {
                        Message::query()->where('id', $origmsg)->update(['location' => '0']);
                    }
                }
            }
            if (! $returnto) {
                $returnto = get_protocol_prefix() . $GLOBALS['BASEURL'] . '/messages.php';
            }
        }

        if ($returnto) {
            return redirect($returnto);
        }

        $pageTitle = $lang['std_succeeded'] ?? 'Succeeded';
        $content = $this->wrapContent(
            '<h1 align="center">' . ($lang['std_succeeded'] ?? 'Succeeded') . '</h1>'
            . '<p align="center">' . ($lang['std_message_was'] ?? 'Message was') . ($lang['std_successfully_sent'] ?? ' successfully sent!') . '</p>'
        );
        return view('messages', compact('pageTitle', 'content'));
    }

    /**
     * Message-box navigation menu, mirrors the legacy messages.php
     * `messagemenu()` helper.
     */
    private function messageMenu(array $curUser, array $lang, int $selected = self::PM_INBOX): string
    {
        $base = $GLOBALS['BASEURL'];
        $content = '<div id="pmboxnav"><ul id="pmboxmenu" class="menu">';
        $content .= '<li class=' . ($selected == self::PM_INBOX ? 'selected' : '') . '><a href="' . get_protocol_prefix() . $base . '/messages.php">' . ($lang['text_inbox'] ?? 'Inbox') . '</a></li>';
        $content .= '<li class=' . ($selected == self::PM_SENTBOX ? 'selected' : '') . '><a href="' . get_protocol_prefix() . $base . '/messages.php?action=viewmailbox&box=-1">' . ($lang['text_sentbox'] ?? 'Sentbox') . '</a></li>';
        $pmboxes = Pmbox::query()->where('userid', $curUser['id'])->get();
        foreach ($pmboxes as $row) {
            $content .= '<li class=' . ($selected == $row['boxnumber'] ? 'selected' : '') . '><a href="' . get_protocol_prefix() . $base . '/messages.php?action=viewmailbox&box=' . $row['boxnumber'] . '">' . $row['name'] . '</a></li>';
        }
        $content .= '</ul></div>';
        return $content;
    }

    /**
     * Search + jump-to select, mirrors the legacy messages.php `insertJumpTo()`
     * helper.
     */
    private function insertJumpTo(array $curUser, array $lang, int $selected = 0, Request $request = null): string
    {
        $place = (string) ($request ? $request->query('place', '') : ($_GET['place'] ?? ''));
        $keyword = (string) ($request ? $request->query('keyword', '') : ($_GET['keyword'] ?? ''));
        $content = '<form action="messages.php" method="get">';
        $content .= '<input type="hidden" name="action" value="viewmailbox">' . ($lang['text_search'] ?? 'Search: ') . '&nbsp;&nbsp;<input id="searchinput" name="keyword" type="text" value="' . htmlspecialchars($keyword) . '" style="width: 200px"/>';
        $content .= ($lang['text_in'] ?? 'in') . '&nbsp;<select name="place">';
        $content .= '<option value="both"' . ($place == 'both' ? ' selected' : '') . '>' . ($lang['select_both'] ?? 'both') . '</option>';
        $content .= '<option value="title"' . ($place == 'title' ? ' selected' : '') . '>' . ($lang['select_title'] ?? 'title') . '</option>';
        $content .= '<option value="body"' . ($place == 'body' ? ' selected' : '') . '>' . ($lang['select_body'] ?? 'body') . '</option>';
        $content .= '</select>';
        $content .= ($lang['text_jump_to'] ?? ' at ') . '<select name="box">';
        $content .= '<option value="1"' . ($selected == self::PM_INBOX ? ' selected' : '') . '>' . ($lang['select_inbox'] ?? 'Inbox') . '</option>';
        $content .= '<option value="-1"' . ($selected == self::PM_SENTBOX ? ' selected' : '') . '>' . ($lang['select_sentbox'] ?? 'Sentbox') . '</option>';
        $pmboxes = Pmbox::query()->where('userid', $curUser['id'])->orderBy('boxnumber')->get();
        foreach ($pmboxes as $row) {
            $content .= '<option value="' . $row['boxnumber'] . '"' . ($row['boxnumber'] == $selected ? ' selected' : '') . '>' . $row['name'] . "</option>\n";
        }
        $content .= '</select> <input class=btn type="submit" value="' . ($lang['submit_go'] ?? 'Go') . '"></form>';
        return $content;
    }

    /**
     * Open a main frame table, mirroring the legacy begin_main_frame() /
     * end_main_frame() pair used around the page body.
     */
    private function wrapContent(string $content): string
    {
        $width = CONTENT_WIDTH;
        return '<table class="main" width="' . $width . '" border="0" cellspacing="0" cellpadding="0"><tr><td class="embedded">' . $content . '</td></tr></table>';
    }

    /**
     * message list
     *
     * @param Request $request
     * @return array
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = $user->receive_messages()
            ->with(['send_user'])
            ->orderBy('id', 'desc');

        if ($request->unread) {
            $query->where('unread', 'yes');
        }
        $messages = $query->paginate();
        $resource = MessageResource::collection($messages);
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
        $message = Message::query()->with(['send_user'])->findOrFail($id);
        $message->update(['unread' => 'no']);
        $resource = new MessageResource($message);
//        $resource->additional([
//            'page_title' => nexus_trans('message.show.page_title'),
//        ]);

        return $this->success($resource);
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

    public function listUnread(Request $request): array
    {
        $user = Auth::user();
        $query = $user->receive_messages()
            ->with(['send_user'])
            ->orderBy('id', 'desc')
            ->where('unread', 'yes');

        $messages = $query->paginate();
        $resource = MessageResource::collection($messages);
//        $resource->additional([
//            'site_info' => site_info(),
//        ]);
        return $this->success($resource);
    }

    public function countUnread()
    {
        $user = Auth::user();
        $count = $user->receive_messages()->where('unread', 'yes')->count();
        return $this->success(['unread' => $count]);
    }
}
