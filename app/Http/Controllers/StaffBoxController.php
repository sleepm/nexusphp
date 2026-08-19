<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Setting;
use App\Models\StaffMessage;
use App\Models\User;
use App\Repositories\MessageRepository;
use App\Repositories\ToolRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StaffBoxController extends Controller
{
    /**
     * Staff inbox. Mirrors legacy public/staffbox.php action dispatch so the
     * "Staff's PM" inbox, per-message view, answer/delete/mark-answered flows
     * keep working under the Laravel router.
     *
     * GET/POST /staffbox.php?action=viewpm|answermessage|takeanswer|
     * deletestaffmessage|setanswered|takecontactanswered
     */
    public function web(Request $request)
    {
        [$curUser, $lang] = $this->bootstrap($request);

        $action = (string) $request->query('action', '');
        if ($action === '') {
            $action = (string) $request->input('action', '');
        }
        switch ($action) {
            case '':
                return $this->webList($request, $curUser, $lang);
            case 'viewpm':
                return $this->webViewPm($request, $curUser, $lang);
            case 'answermessage':
                return $this->webAnswerMessage($request, $curUser, $lang);
            case 'takeanswer':
                return $this->webTakeAnswer($request, $curUser, $lang);
            case 'deletestaffmessage':
                return $this->webDeleteStaffMessage($request, $curUser, $lang);
            case 'setanswered':
                return $this->webSetAnswered($request, $curUser, $lang);
            case 'takecontactanswered':
                return $this->webTakeContactAnswered($request, $curUser, $lang);
            default:
                abort(400, $lang['std_error'] ?? 'Error');
        }
    }

    private function bootstrap(Request $request): array
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
        $lang = get_legacy_lang_file('staffbox');

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_staffbox'] = $lang;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        return [$curUser, $lang];
    }

    /**
     * Access guard for a single staff message: staff members may read any
     * message; others must hold the message's required permission.
     */
    private function canAccessStaffMessage($msg, array $curUser): void
    {
        if (user_can('staffmem', false, $curUser['id'])) {
            return;
        }
        if (is_numeric($msg)) {
            $msg = StaffMessage::query()->findOrFail((int) $msg)->toArray();
        }
        if (empty($msg['permission']) || ! in_array($msg['permission'], ToolRepository::listUserAllPermissions($curUser['id']))) {
            abort(403, $GLOBALS['lang_functions']['std_permission_denied'] ?? 'Permission denied.');
        }
    }

    // ------------------------------------------------------------------ list

    private function webList(Request $request, array $curUser, array $lang)
    {
        $content = $this->capture(function () use ($curUser, $lang) {
            $url = 'staffbox.php?';
            $query = MessageRepository::buildStaffMessageQuery($curUser['id']);
            $count = $query->count();
            $perpage = 20;
            [$pagertop, $pagerbottom, , , , $pageNum] = pager($perpage, $count, $url);

            print('<h1 align=center>' . $lang['text_staff_pm'] . '</h1>');
            if ($count == 0) {
                stdmsg($lang['std_sorry'], $lang['std_no_messages_yet']);
                return;
            }

            begin_main_frame();
            print('<form method=post action="?action=takecontactanswered">');
            print('<table width=940 border=1 cellspacing=0 cellpadding=5 align=center>' . "\n");
            print('<tr>
                <td class=colhead align=left>' . $lang['col_subject'] . '</td>
                <td class=colhead align=center>' . $lang['col_sender'] . '</td>
                <td class=colhead align=center><nobr>' . $lang['col_added'] . '</nobr></td>
                <td class=colhead align=center>' . $lang['col_answered'] . '</td>
                <td class=colhead align=center><nobr>' . $lang['col_action'] . '</nobr></td>
            </tr>');

            $rows = (clone $query)->forPage($pageNum + 1, $perpage)->orderBy('id', 'desc')->get()->toArray();
            foreach ($rows as $arr) {
                if ($arr['answered']) {
                    $answered = '<nobr><font color=green>' . $lang['text_yes'] . '</font> - ' . get_username($arr['answeredby']) . '</nobr>';
                } else {
                    $answered = '<font color=red>' . $lang['text_no'] . '</font>';
                }
                $pmid = $arr['id'];
                print('<tr><td width=100% class=rowfollow align=left><a href=staffbox.php?action=viewpm&pmid=' . $pmid . '&return=' . urlencode((string) ($_SERVER['QUERY_STRING'] ?? '')) . '>' . htmlspecialchars($arr['subject']) . '</a></td>'
                    . '<td class=rowfollow align=center>' . get_username($arr['sender']) . '</td>'
                    . '<td class=rowfollow align=center><nobr>' . gettime($arr['added'], true, false) . '</nobr></td>'
                    . '<td class=rowfollow align=center>' . $answered . '</td>'
                    . '<td class=rowfollow align=center><input type="checkbox" name="setanswered[]" value="' . $arr['id'] . '" /></td></tr>' . "\n");
            }
            $checkAll = $GLOBALS['lang_functions']['input_check_all'] ?? 'Check All';
            $uncheckAll = $GLOBALS['lang_functions']['input_uncheck_all'] ?? 'Uncheck All';
            print('<tr><td class=rowfollow align=right colspan=5>'
                . '<input type="button" value="' . $checkAll . '" onclick="this.value=check(form, \'' . $checkAll . '\', \'' . $uncheckAll . '\')"/>'
                . '<input type="submit" name="setdealt" value="' . $lang['submit_set_answered'] . '" />'
                . '<input type="submit" name="delete" value="' . $lang['submit_delete'] . '" /></td></tr>');
            print("</table>\n");
            print('</form>');
            echo $pagerbottom;
            end_main_frame();
        });

        return view('staffbox', compact('content') + [
            'pageTitle' => $lang['head_staff_pm'],
        ]);
    }

    // ---------------------------------------------------------------- view

    private function webViewPm(Request $request, array $curUser, array $lang)
    {
        $pmid = (int) $request->query('pmid', 0);
        $staffMsg = StaffMessage::query()->where('id', $pmid)->first();
        if (! $staffMsg) {
            abort(400, $lang['std_no_messages_yet'] ?? 'No messages yet!');
        }
        $arr4 = $staffMsg->toArray();
        $this->canAccessStaffMessage($arr4, $curUser);

        $answeredby = get_username($arr4['answeredby']);
        $sender = is_valid_id($arr4['sender']) ? get_username($arr4['sender']) : $lang['text_system'];
        $subject = htmlspecialchars($arr4['subject']);
        if ($arr4['answered'] == 1) {
            $colspan = '3';
            $width = '33';
        } else {
            $colspan = '2';
            $width = '50';
        }

        $content = $this->capture(function () use (
            $lang, $arr4, $answeredby, $sender, $subject, $colspan, $width, $request
        ) {
            print('<h1 align="center"><a class="faqlink" href="staffbox.php">' . $lang['text_staff_pm'] . '</a>--&gt;' . $subject . '</h1>');
            print('<table width="737" border="0" cellpadding="4" cellspacing="0">');
            print('<tr><td width="' . $width . '%" class="colhead" align="left">' . $lang['col_from'] . '</td>');
            if ($arr4['answered'] == 1) {
                print('<td width="34%" class="colhead" align="left">' . $lang['col_answered_by'] . '</td>');
            }
            print('<td width="' . $width . '%" class="colhead" align="left">' . $lang['col_date'] . '</td></tr>');
            print('<tr><td class="rowfollow" align="left">' . $sender . '</td>');
            if ($arr4['answered'] == 1) {
                print('<td class="rowfollow" align="left">' . $answeredby . '</td>');
            }
            print('<td class="rowfollow" align="left">' . gettime($arr4['added']) . '</td></tr>');
            print('<tr><td colspan="' . $colspan . '" align="left">' . format_comment($arr4['msg']) . '</td></tr>');
            if ($arr4['answered'] == 1 && $arr4['answer']) {
                print('<tr><td colspan="' . $colspan . '" align="left">' . format_comment($arr4['answer']) . '</td></tr>');
            }
            print('<tr><td colspan="' . $colspan . '" align="right">');
            print('<font color=white>');
            if ($arr4['answered'] == 0) {
                print('[ <a href="staffbox.php?action=answermessage&receiver=' . $arr4['sender'] . '&answeringto=' . $arr4['id'] . '">' . $lang['text_reply'] . '</a> ] [ <a href="staffbox.php?action=setanswered&id=' . $arr4['id'] . '&return=' . urlencode((string) $request->query('return', '')) . '">' . $lang['text_mark_answered'] . '</a> ] ');
            }
            print('[ <a href="staffbox.php?action=deletestaffmessage&id=' . $arr4['id'] . '">' . $lang['text_delete'] . '</a> ]');
            print('</font>');
            print('</td></tr>');
            print('</table>');
        });

        return view('staffbox', compact('content') + [
            'pageTitle' => $lang['head_view_staff_pm'],
        ]);
    }

    // ------------------------------------------------------------- answer

    private function webAnswerMessage(Request $request, array $curUser, array $lang)
    {
        $answeringto = (int) $request->query('answeringto', 0);
        $receiver = (int) $request->query('receiver', 0);

        if (! is_valid_id($receiver)) {
            abort(400, $lang['std_no_user_id'] ?? 'No user with that ID.');
        }
        $user = User::query()->where('id', $receiver)->first();
        if (! $user) {
            abort(400, $lang['std_no_user_id'] ?? 'No user with that ID.');
        }
        $staffMsg = StaffMessage::query()->where('id', $answeringto)->first();
        if (! $staffMsg) {
            abort(400, $lang['std_no_messages_yet'] ?? 'No messages yet!');
        }
        $this->canAccessStaffMessage($staffMsg->toArray(), $curUser);

        $content = $this->capture(function () use ($lang, $staffMsg, $receiver, $answeringto, $request) {
            begin_main_frame();
            print('<form method="post" id="compose" name="message" action="?action=takeanswer">');
            $returnto = $request->query('returnto');
            if (! $returnto) {
                $returnto = $request->headers->get('referer');
            }
            if ($returnto) {
                print('<input type=hidden name=returnto value="' . htmlspecialchars((string) $returnto) . '">');
            }
            print('<input type=hidden name=receiver value=' . $receiver . '>');
            print('<input type=hidden name=answeringto value=' . $answeringto . '>');
            $title = $lang['text_answering_to']
                . '<a href="staffbox.php?action=viewpm&pmid=' . $staffMsg['id'] . '">' . htmlspecialchars($staffMsg['subject']) . '</a>'
                . $lang['text_sent_by'] . get_username($staffMsg['sender']);
            begin_compose($title, 'reply', '', false);
            end_compose();
            print('</form>');
            end_main_frame();
        });

        return view('staffbox', compact('content') + [
            'pageTitle' => $lang['head_answer_to_staff_pm'],
        ]);
    }

    private function webTakeAnswer(Request $request, array $curUser, array $lang)
    {
        if (! $request->isMethod('POST')) {
            abort(400);
        }
        $receiver = (int) $request->input('receiver', 0);
        $answeringto = (int) $request->input('answeringto', 0);

        if (! is_valid_id($receiver)) {
            abort(400, $lang['std_no_user_id'] ?? 'No user with that ID.');
        }
        $msg = trim((string) $request->input('body', ''));
        if (! $msg) {
            abort(400, $lang['std_body_is_empty'] ?? 'Please enter something!');
        }

        $this->canAccessStaffMessage($answeringto, $curUser);

        $subject = StaffMessage::query()->where('id', $answeringto)->firstOrFail()->subject;

        Message::add([
            'sender' => $curUser['id'],
            'receiver' => $receiver,
            'subject' => $subject,
            'added' => now(),
            'msg' => $msg,
        ]);

        StaffMessage::query()->where('id', $answeringto)->update([
            'answer' => $msg,
            'answered' => 1,
            'answeredby' => $curUser['id'],
        ]);
        clear_staff_message_cache();

        return redirect('staffbox.php?action=viewpm&pmid=' . $answeringto);
    }

    // --------------------------------------------------------- delete / mark

    private function webDeleteStaffMessage(Request $request, array $curUser, array $lang)
    {
        $id = (int) $request->query('id', 0);
        if (! is_numeric($id) || $id < 1 || floor((float) $id) != (float) $id) {
            abort(400);
        }
        $this->canAccessStaffMessage($id, $curUser);
        StaffMessage::query()->where('id', $id)->delete();
        clear_staff_message_cache();

        return redirect(get_protocol_prefix() . $GLOBALS['BASEURL'] . '/staffbox.php');
    }

    private function webSetAnswered(Request $request, array $curUser, array $lang)
    {
        $id = (int) $request->query('id', 0);
        $this->canAccessStaffMessage($id, $curUser);
        StaffMessage::query()->where('id', $id)->update(['answered' => 1, 'answeredby' => $curUser['id']]);
        clear_staff_message_cache();

        return redirect('staffbox.php' . (! empty($request->query('return')) ? '?' . $request->query('return') : ''));
    }

    private function webTakeContactAnswered(Request $request, array $curUser, array $lang)
    {
        $setAnswered = (array) $request->input('setanswered', []);
        if (empty($setAnswered)) {
            abort(400, $lang['std_sorry'] . ': ' . nexus_trans('nexus.select_one_please'));
        }
        $setAnswered = array_map('intval', $setAnswered);

        if ($request->input('setdealt')) {
            $rows = StaffMessage::query()->where('answered', 0)->whereIn('id', $setAnswered)->get();
            foreach ($rows as $arr) {
                $this->canAccessStaffMessage($arr->toArray(), $curUser);
                StaffMessage::query()->where('id', $arr['id'])->update(['answered' => 1, 'answeredby' => $curUser['id']]);
            }
        } elseif ($request->input('delete')) {
            $rows = StaffMessage::query()->whereIn('id', $setAnswered)->get();
            foreach ($rows as $arr) {
                $this->canAccessStaffMessage($arr->toArray(), $curUser);
                StaffMessage::query()->where('id', $arr['id'])->delete();
            }
        }
        clear_staff_message_cache();

        return redirect('staffbox.php');
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}