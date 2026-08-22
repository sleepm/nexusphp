<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Message;
use App\Models\Request;
use App\Models\Setting;
use App\Models\Torrent;
use App\Models\User;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Request (求种) management page. Mirrors legacy public/viewrequests.php so the
 * whole viewrequests.php/?action=* surface keeps working under the Laravel router.
 *
 * Dispatched by the action query/post parameter exactly like the legacy script:
 *   list / new / newmessage / view / edit / takeedit / takeadded / res /
 *   takeres / addamount / delete / confirm / message / search.
 */
class RequestController extends Controller
{
    /**
     * Legacy user-class constants live in include/core.php which is not loaded
     * by the Laravel bootstrap; a few legacy helpers compare against them.
     */
    private const USER_CLASS_CONSTANTS = [
        'UC_PEASANT' => 0, 'UC_USER' => 1, 'UC_POWER_USER' => 2, 'UC_ELITE_USER' => 3,
        'UC_CRAZY_USER' => 4, 'UC_INSANE_USER' => 5, 'UC_VETERAN_USER' => 6,
        'UC_EXTREME_USER' => 7, 'UC_ULTIMATE_USER' => 8, 'UC_NEXUS_MASTER' => 9,
        'UC_VIP' => 10, 'UC_RETIREE' => 11, 'UC_UPLOADER' => 12, 'UC_MODERATOR' => 13,
        'UC_ADMINISTRATOR' => 14, 'UC_SYSOP' => 15, 'UC_STAFFLEADER' => 16,
    ];

    public function web(HttpRequest $request)
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

        $lang = get_legacy_lang_file('viewrequests');
        $this->bootstrap($curUser, $lang);

        // Legacy helpers (pager, gettime, ...) read the request globals.
        $_GET = $request->query();
        $_REQUEST = $request->all();

        // Determine the action exactly like the legacy script: POST first,
        // then GET, then infer from the presence of an id.
        $action = (string) $request->input('action', $request->query('action', ''));
        $allowedActions = ['list', 'new', 'newmessage', 'view', 'edit', 'takeedit', 'takeadded', 'res', 'takeres', 'addamount', 'delete', 'confirm', 'message', 'search'];
        if ($action === '') {
            $action = $request->query('id') !== null ? 'view' : 'list';
        }
        if (! in_array($action, $allowedActions, true)) {
            $action = 'list';
        }

        return match ($action) {
            'view' => $this->view($request, $curUser, $lang),
            'new' => $this->showNewForm($curUser, $lang),
            'newmessage' => $this->showNewMessageForm($request, $curUser, $lang),
            'edit' => $this->showEditForm($request, $curUser, $lang),
            'takeedit' => $this->takeEdit($request, $curUser, $lang),
            'takeadded' => $this->takeAdded($request, $curUser, $lang),
            'res' => $this->showResForm($request, $lang),
            'takeres' => $this->takeRes($request, $curUser, $lang),
            'addamount' => $this->addAmount($request, $curUser, $lang),
            'delete' => $this->delete($request, $curUser, $lang),
            'confirm' => $this->confirm($request, $curUser, $lang),
            'message' => $this->message($request, $curUser, $lang),
            'search' => $this->showSearchForm($lang),
            default => $this->index($request, $curUser, $lang),
        };
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Globals the shared legacy helpers expect (mirrors public/viewrequests.php bootstrap).
     */
    private function bootstrap(array $curUser, array $lang): void
    {
        foreach (self::USER_CLASS_CONSTANTS as $constant => $value) {
            defined($constant) || define($constant, $value);
        }

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['lang_viewrequests'] = $lang;
        $GLOBALS['lang_details'] = get_legacy_lang_file('details');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();
        $GLOBALS['bonus_tweak'] = get_setting('tweak.bonus', '');
        $GLOBALS['commanage_class'] = (int) get_setting('authority.commanage', 0);
        if (empty($GLOBALS['Advertisement'])) {
            require_once ROOT_PATH . 'classes/class_advertisement.php';
            $GLOBALS['Advertisement'] = new \ADVERTISEMENT($curUser['id']);
        }
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();

        return (string) ob_get_clean();
    }

    /**
     * Full-page message (mirrors the legacy stdmsg()/stderr() box) rendered
     * through Blade instead of stderr()/stdhead()/stdfoot().
     */
    private function messagePage(string $heading, string $message, string $pageTitle = 'Error', bool $htmlstrip = true)
    {
        if ($htmlstrip) {
            $heading = htmlspecialchars(trim($heading));
            $message = htmlspecialchars(trim($message));
        }

        return view('request.message', compact('heading', 'message', 'pageTitle'));
    }

    private function isUploaderOrAbove(array $curUser): bool
    {
        return (int) ($curUser['class'] ?? 0) >= UC_UPLOADER;
    }

    // ------------------------------------------------------------ main listing

    private function index(HttpRequest $request, array $curUser, array $lang)
    {
        $finished = (string) $request->input('finished', $request->query('finished', ''));
        $finishedlimit = $request->query('finished') !== null
            ? 'finished=' . $request->query('finished') . '&'
            : '';

        $query = Request::query()
            ->select('requests.*')
            ->selectRaw('(SELECT count(DISTINCT torrentid) FROM resreq WHERE reqid = requests.id) as Totalreq');

        switch ($finished) {
            case 'yes':
                $query->where('finish', 'yes');
                break;
            case 'no':
                $query->where('finish', 'no');
                break;
            case 'all':
                break;
            case 'my':
                $query->where('userid', $curUser['id']);
                break;
            case 'ing':
                $query->where('finish', 'no')
                    ->whereExists(function ($sub) {
                        $sub->selectRaw('1')
                            ->from('resreq')
                            ->whereColumn('resreq.reqid', 'requests.id');
                    });
                break;
            default:
                $query->where('finish', 'no');
                break;
        }

        $queryText = trim((string) $request->input('query', ''));
        if ($queryText !== '') {
            $query->where(function ($q) use ($queryText) {
                $q->where('request', 'like', '%' . $queryText . '%')
                    ->orWhere('descr', 'like', '%' . $queryText . '%');
            });
        }

        $count = (clone $query)->count();
        [$pagertop, $pagerbottom, $limit2] = pager(20, $count, 'viewrequests.php?' . $finishedlimit);

        preg_match('/limit\s+(\d+)\s+offset\s+(\d+)/', $limit2, $limitMatch);
        $rows = $query
            ->orderByDesc('requests.id')
            ->limit((int) ($limitMatch[1] ?? 20))
            ->offset((int) ($limitMatch[2] ?? 0))
            ->get();

        $content = '<h1 align=center>' . $lang['page_title'] . "</h1>\n";
        $content .= "<br><b><a href='viewrequests.php?action=new'>{$lang['add_request']}</a>"
            . " | <a href='viewrequests.php?finished=all'>{$lang['view_request_all']}</a>"
            . " | <a href='viewrequests.php?finished=yes'>{$lang['view_request_resolved']}</a>"
            . " | <a href='viewrequests.php?finished=no'>{$lang['view_request_unresolved']}</a>"
            . " | <a href='viewrequests.php?finished=ing'>{$lang['view_request_resolving']}</a>"
            . " | <a href='viewrequests.php?finished=my' " . get_requestcount() . ">{$lang['view_request_my']}</a></b><p>\n";

        $content .= "<table width=98% border=1 cellspacing=0 cellpadding=5 style=border-collapse:collapse >\n";
        if ($rows->isEmpty()) {
            $content .= "<tr><td class=colhead align=center>Nothing</td></tr>\n";
        } else {
            $content .= '<tr>'
                . '<td class=colhead align=left>' . $lang['thead_name'] . '</td>'
                . '<td class=colhead align=center>' . $lang['thead_price_newest'] . '</td>'
                . '<td class=colhead align=center>' . $lang['thead_price_original'] . '</td>'
                . '<td class=colhead align=center>' . $lang['thead_comment_count'] . '</td>'
                . '<td class=colhead align=center>' . $lang['thead_on_request_count'] . '</td>'
                . '<td class=colhead align=center>' . $lang['thead_request_user'] . '</td>'
                . '<td class=colhead align=center>' . $lang['thead_created_at'] . '</td>'
                . '<td class=colhead align=center>' . $lang['thead_status'] . "</td></tr>\n";

            foreach ($rows as $row) {
                $rowArr = $row->toArray();
                $status = $rowArr['finish'] == 'yes'
                    ? $lang['request_status_resolved']
                    : ($rowArr['userid'] == $curUser['id']
                        ? $lang['request_status_resolving']
                        : "<a href='viewrequests.php?action=res&id=" . $rowArr['id'] . "'>{$lang['request_status_resolving']}</a>");
                $content .= "<tr>"
                    . "<td align=left class='rowfollow'><a href='viewrequests.php?action=view&id=" . $rowArr['id'] . "'><b>" . htmlspecialchars($rowArr['request']) . "</b></a></td>"
                    . "<td align=center class='rowfollow nowrap'><font color=#ff0000><b>" . $rowArr['amount'] . "</b></font></td>"
                    . "<td align=center class='rowfollow nowrap'>" . $rowArr['ori_amount'] . "</td>"
                    . "<td align=center class='rowfollow nowrap'>" . $rowArr['comments'] . "</td>"
                    . "<td align=center class='rowfollow nowrap'>" . $rowArr['Totalreq'] . "</td>"
                    . "<td align=center class='rowfollow nowrap'>" . get_username($rowArr['userid']) . "</td>"
                    . "<td align=center class='rowfollow nowrap'>" . gettime($rowArr['added'], true, false) . "</td>"
                    . "<td align=center class='rowfollow nowrap'>" . $status . "</td>"
                    . "</tr>\n";
            }
        }
        $content .= "</table>\n";
        $content .= $pagerbottom;

        $content .= "<table border=1 cellspacing=0 cellpadding=5>\n";
        $content .= "<tr><td class=toolbox align=left><form method=\"post\" action='viewrequests.php'>\n";
        $content .= "<input type=\"text\" name=\"query\" style=\"width:500px\" >\n";
        $content .= "<input type=\"hidden\" name=\"action\" value='list'>";
        $content .= "<input type=\"hidden\" name=\"finished\" value='all'>";
        $content .= "<input type=submit value='{$lang['action_search']}'></form>\n";
        $content .= "</td></tr></table><br />\n";

        return view('request.index', [
            'content' => $content,
            'pageTitle' => $lang['page_title'],
        ]);
    }

    // ----------------------------------------------------------- request details

    private function view(HttpRequest $request, array $curUser, array $lang)
    {
        $langFunctions = $GLOBALS['lang_functions'];
        $id = (int) $request->query('id', 0);
        if ($id <= 0) {
            return $this->messagePage($langFunctions['std_error'], $langFunctions['std_target_not_exists']);
        }

        $arr = Request::query()->where('id', $id)->first();
        if (! $arr) {
            return $this->messagePage($langFunctions['std_error'], $langFunctions['std_target_not_exists']);
        }
        $arrArr = $arr->toArray();

        $content = '<h1 align=center id=top>' . $lang['request'] . '-' . htmlspecialchars($arrArr['request']) . "</h1>\n";
        $content .= "<table width=100% cellspacing=0 cellpadding=5>\n";

        $content .= tr(
            $lang['basic_info'],
            get_username($arrArr['userid']) . $lang['created_at'] . gettime($arrArr['added'], true, false) . "\n",
            1,
            '',
            true
        );
        $content .= tr(
            $lang['reward'],
            $lang['newest_bidding'] . $arrArr['amount'] . "     {$lang['original_bidding']}" . $arrArr['ori_amount'] . "\n",
            1,
            '',
            true
        );

        $isOwner = $arrArr['userid'] == $curUser['id'];
        $isUploader = $this->isUploaderOrAbove($curUser);
        $isManager = $isOwner || $isUploader;

        $resreqCount = DB::table('resreq')->where('reqid', $id)->count();
        $action = "<a href='report.php?reportrequestid=" . $id . "'>{$langFunctions['std_report']}</a>";
        if ($isManager && $arrArr['finish'] == 'no') {
            $action .= " | <a href='viewrequests.php?action=edit&id=" . $id . "'>{$langFunctions['title_edit']}</a>\n";
        }
        if (! $isOwner && $arrArr['finish'] != 'yes') {
            $action .= " | <a href='viewrequests.php?action=res&id=" . $id . "'>{$lang['on_request']}</a>\n";
        }
        if ($isManager && $arrArr['finish'] == 'no') {
            $action .= ' | <a href="viewrequests.php?action=delete&id=' . $id . '" '
                . ($resreqCount ? ">{$langFunctions['title_delete']}" : "title='{$lang['recycle_title']}'>{$lang['recycle']}")
                . "</a>\n";
        }
        $content .= tr($langFunctions['std_action'], $action, 1, '', true);

        if ($arrArr['finish'] == 'no') {
            $content .= tr(
                $lang['add_reward'],
                '<form action=viewrequests.php method=post>'
                . '<input type=hidden name=action value=addamount>'
                . '<input type=hidden name=reqid value=' . $arrArr['id'] . '>'
                . '<input size=6 name=amount value=1000 >'
                . '<input type=submit value=' . $langFunctions['submit_submit'] . '> '
                . $lang['add_reward_desc'] . '</form>',
                1,
                '',
                true
            );
        }
        $content .= tr($langFunctions['std_desc'], format_comment(unesc($arrArr['descr'])), 1, '', true);

        // ---- supply list (resreq)
        $resreqRows = DB::table('resreq')->where('reqid', $id)->get();
        $ress = '';
        if ($resreqRows->isEmpty()) {
            $ress = $lang['no_request_yet'];
        } else {
            if ($isManager) {
                $ress .= "<form action=viewrequests.php method=post>\n"
                    . '<input type=hidden name=action value=confirm > <input type=hidden name=id value=' . $id . " >\n";
            }
            foreach ($resreqRows as $row) {
                $torrent = Torrent::query()->where('id', $row->torrentid)->first(['id', 'name', 'owner']);
                if ($torrent) {
                    $ress .= ($isManager && $arrArr['finish'] == 'no'
                            ? '<input type=checkbox name=torrentid[] value=' . $torrent->id . '>'
                            : '')
                        . "<a href='details.php?id=" . $torrent->id . "&hit=1' >" . htmlspecialchars($torrent->name) . '</a> '
                        . ($arrArr['finish'] == 'no' ? '' : 'by ' . get_username($torrent->owner)) . "<br/>\n";
                }
            }
            if ($isManager && $arrArr['finish'] == 'no') {
                $ress .= "<input type=submit value={$lang['btn_select_text']}>\n";
            }
            $ress .= "</form>\n";
        }
        $content .= tr($lang['request'], $ress, 1, '', true);
        $content .= "</table><br/><br/>\n";

        // ---- comments
        $count = Comment::query()->where('request', $id)->count();
        if ($count) {
            $content .= "<br /><br />";
            $content .= '<h1 align="center" id="startcomments">' . $langFunctions['std_comment'] . "</h1>\n";
            [$commentPagertop, $commentPagerbottom, $limit] = pager(
                10,
                $count,
                'viewrequests.php?action=view&id=' . $id . '&',
                ['lastpagedefault' => 1],
                'page'
            );

            preg_match('/limit\s+(\d+)\s+offset\s+(\d+)/', $limit, $limitMatch);
            $commentRows = Comment::query()
                ->select('id', 'text', 'user', 'added', 'editedby', 'editdate')
                ->where('request', $id)
                ->orderBy('id')
                ->limit((int) ($limitMatch[1] ?? 10))
                ->offset((int) ($limitMatch[2] ?? 0))
                ->get()
                ->map(fn ($row) => $row->toArray())
                ->all();

            $content .= $commentPagertop;
            $content .= $this->capture(function () use ($commentRows, $id) {
                commenttable($commentRows, 'request', $id);
            });
            $content .= $commentPagerbottom;
        }

        // ---- quick comment
        $content .= $this->capture(function () use ($id, $langFunctions) {
            $langDetails = $GLOBALS['lang_details'];
            echo '<table style="border:1px solid #000000;">';
            echo '<tr><td class="text" align="center"><b>' . $langDetails['text_quick_comment'] . '</b><br /><br />';
            echo '<form id="compose" name="comment" method="post" action="' . htmlspecialchars('comment.php?action=add&type=request') . '" onsubmit="return postvalid(this);">';
            echo '<input type="hidden" name="pid" value="' . $id . '" /><br />';
            quickreply('comment', 'body', $langFunctions['std_quick_comment']);
            echo '</form></td></tr></table>';
        });

        $content .= "<a class=\"index\" href='comment.php?action=add&pid=$id&type=request'>{$langFunctions['title_add_comments']}</a></td></tr></table>";

        return view('request.details', [
            'content' => $content,
            'pageTitle' => $lang['page_title'] . ' - ' . $arrArr['request'],
        ]);
    }

    // -------------------------------------------------------------- new request

    private function showNewForm(array $curUser, array $lang)
    {
        $langFunctions = $GLOBALS['lang_functions'];
        if ((int) $curUser['class'] < 1) {
            return $this->messagePage(
                $langFunctions['std_error'],
                "{$langFunctions['std_permission_denied']}<a href='viewrequests.php'>{$langFunctions['std_click_here_to_goback']}</a>",
                $langFunctions['std_error'],
                false
            );
        }

        $content = "<form id=edit method=post name=edit action=viewrequests.php >\n<input type=hidden name=action value=takeadded >\n";
        $content .= "<table width=100% cellspacing=0 cellpadding=3><tr><td class=colhead align=center colspan=2>{$lang['add_request']}</td></tr>\n";
        $content .= tr("{$langFunctions['col_name']}：", '<input name=request size=134><br/>', 1, '', true);
        $content .= tr("{$lang['reward']}：", "<input name=amount size=11 value=2000>{$lang['add_request_desc']}<br/>", 1, '', true);
        $content .= '<tr><td class=rowhead align=right valign=top><b>' . $langFunctions['std_desc'] . '：</b></td><td class=rowfollow align=left>';
        $content .= $this->capture(function () {
            textbbcode('edit', 'descr', '', false, 130, true);
        });
        $content .= '</td></tr>';
        $content .= "<tr><td class=toolbox style=vertical-align: middle; padding-top: 10px; padding-bottom: 10px; align=center colspan=2><input id=qr type=submit value={$lang['add_request']} class=btn /></td></tr></table></form><br />\n";

        return view('request.details', [
            'content' => $content,
            'pageTitle' => $lang['add_request'],
        ]);
    }

    // ------------------------------------------------------------- reply form

    private function showNewMessageForm(HttpRequest $request, array $curUser, array $lang)
    {
        $langFunctions = $GLOBALS['lang_functions'];
        $ruserid = (int) $request->query('userid', 0);

        $content = '<form id=reply name=reply method=post action=viewrequests.php >\n'
            . '<input type=hidden name=action value=message ><input type=hidden name=id value=' . intval($request->query('id', 0)) . " >\n";
        $content .= "<table width=100% cellspacing=0 cellpadding=3>\n";
        $content .= '<tr><td class=rowfollow align=left>';
        if ($ruserid) {
            $content .= $this->capture(function () use ($ruserid, $langFunctions) {
                textbbcode('reply', 'message', "[b]{$langFunctions['text_reply']}:" . get_plain_username($ruserid) . "[/b]\n");
                echo '<input id=ruserid type=hidden value=' . $ruserid . ' />';
            });
        } else {
            $content .= $this->capture(function () {
                textbbcode('reply', 'message');
            });
        }
        $content .= '</td></tr>';
        $content .= "</table><input id=qr type=submit value={$langFunctions['title_add_comments']} class=btn /></form><br />\n";

        return view('request.details', [
            'content' => $content,
            'pageTitle' => $langFunctions['text_reply'],
        ]);
    }

    // -------------------------------------------------------------- edit request

    private function showEditForm(HttpRequest $request, array $curUser, array $lang)
    {
        $langFunctions = $GLOBALS['lang_functions'];
        $id = (int) $request->query('id', 0);
        if ($id <= 0) {
            return $this->messagePage($langFunctions['std_error'], $langFunctions['std_target_not_exists']);
        }

        $arr = Request::query()->where('id', $id)->first();
        if (! $arr) {
            return $this->messagePage($langFunctions['std_error'], $langFunctions['std_target_not_exists']);
        }
        if ($arr->finish == 'yes') {
            return $this->messagePage($langFunctions['std_error'], $lang['request_already_resolved']);
        }
        if ($arr->userid != $curUser['id'] && ! $this->isUploaderOrAbove($curUser)) {
            return $this->messagePage(
                $langFunctions['std_error'],
                "{$langFunctions['std_permission_denied']}<a href='viewrequests.php?action=view&id=" . $id . "'>{$langFunctions['std_click_here_to_goback']}</a>",
                $langFunctions['std_error'],
                false
            );
        }

        $content = "<form id=edit method=post name=edit action=viewrequests.php >\n"
            . '<input type=hidden name=action value=takeedit >'
            . '<input type=hidden name=reqid value=' . $id . " >\n";
        $content .= '<table width=100% cellspacing=0 cellpadding=3><tr><td class=colhead align=center colspan=2>'
            . $langFunctions['title_edit'] . $lang['request'] . '</td></tr>';
        $content .= tr("{$langFunctions['col_name']}：", '<input name=request value="' . htmlspecialchars($arr->request) . '" size=134 ><br/>', 1, '', true);
        $content .= '<tr><td class=rowhead align=right valign=top><b>' . $langFunctions['std_desc'] . '：</b></td><td class=rowfollow align=left>';
        $content .= $this->capture(function () use ($arr) {
            textbbcode('edit', 'descr', $arr->descr, false, 130, true);
        });
        $content .= '</td></tr>';
        $content .= '</td></tr><tr><td class=toolbox align=center colspan=2><input id=qr type=submit class=btn value='
            . $langFunctions['text_edit'] . $lang['request'] . ' ></td></tr></table></form><br />' . "\n";

        return view('request.details', [
            'content' => $content,
            'pageTitle' => $langFunctions['title_edit'] . $lang['request'],
        ]);
    }

    // -------------------------------------------------------------- take edit

    private function takeEdit(HttpRequest $request, array $curUser, array $lang)
    {
        $langFunctions = $GLOBALS['lang_functions'];
        $reqid = (int) $request->input('reqid', 0);
        if ($reqid <= 0) {
            return $this->messagePage(
                $langFunctions['std_error'],
                "{$lang['request_id_must_be_numeric']}<a href='viewrequests.php?action=edit&id=" . $reqid . "'>{$langFunctions['std_click_here_to_goback']}</a>",
                $langFunctions['std_error'],
                false
            );
        }
        if (! $request->input('descr')) {
            return $this->messagePage(
                $langFunctions['std_error'],
                "{$lang['description_required']}<a href='viewrequests.php?action=edit&id=" . $reqid . "'>{$langFunctions['std_click_here_to_goback']}</a>",
                $langFunctions['std_error'],
                false
            );
        }
        if (! $request->input('request')) {
            return $this->messagePage(
                $langFunctions['std_error'],
                "{$lang['name_required']}<a href='viewrequests.php?action=edit&id=" . $reqid . "'>{$langFunctions['std_click_here_to_goback']}</a>",
                $langFunctions['std_error'],
                false
            );
        }

        $arr = Request::query()->where('id', $reqid)->first();
        if (! $arr) {
            return $this->messagePage(
                $langFunctions['std_error'],
                "{$lang['request_deleted']}<a href='viewrequests.php'>{$langFunctions['std_click_here_to_goback']}</a>",
                $langFunctions['std_error'],
                false
            );
        }
        if ($arr->finish == 'yes') {
            return $this->messagePage(
                $langFunctions['std_error'],
                "{$lang['request_already_resolved']}<a href='viewrequests.php?action=view&id=" . $reqid . "'>{$langFunctions['std_click_here_to_goback']}</a>",
                $langFunctions['std_error'],
                false
            );
        }
        if ($arr->userid != $curUser['id'] && ! $this->isUploaderOrAbove($curUser)) {
            return $this->messagePage(
                $langFunctions['std_error'],
                "{$langFunctions['std_permission_denied']}<a href='viewrequests.php?action=view&id=" . $reqid . "'>{$langFunctions['std_click_here_to_goback']}</a>",
                $langFunctions['std_error'],
                false
            );
        }

        Request::query()->where('id', $reqid)->update([
            'descr' => $request->input('descr'),
            'request' => $request->input('request'),
        ]);

        return $this->messagePage(
            $langFunctions['std_success'],
            "{$lang['edit_request_success']}，<a href='viewrequests.php?action=view&id=" . $reqid . "'>{$langFunctions['std_click_here_to_goback']}</a>",
            $langFunctions['std_success'],
            false
        );
    }

    // ------------------------------------------------------------- take added

    private function takeAdded(HttpRequest $request, array $curUser, array $lang)
    {
        $langFunctions = $GLOBALS['lang_functions'];
        $backToNew = "<a href='viewrequests.php?action=new'>{$langFunctions['std_click_here_to_goback']}</a>";

        if (! $request->input('descr')) {
            return $this->messagePage($langFunctions['std_error'], "{$lang['description_required']}{$backToNew}", $langFunctions['std_error'], false);
        }
        if (! $request->input('request')) {
            return $this->messagePage($langFunctions['std_error'], "{$lang['name_required']}{$backToNew}", $langFunctions['std_error'], false);
        }
        if (! $request->input('amount')) {
            return $this->messagePage($langFunctions['std_error'], "{$lang['amount_required']}{$backToNew}", $langFunctions['std_error'], false);
        }
        $amount = (string) $request->input('amount');
        if (! is_numeric($amount)) {
            return $this->messagePage($langFunctions['std_error'], "{$lang['amount_must_be_numeric']}{$backToNew}", $langFunctions['std_error'], false);
        }
        $amount = (int) $amount;
        if ($amount < 100) {
            return $this->messagePage($langFunctions['std_error'], "{$lang['add_request_amount_minimum']}{$backToNew}", $langFunctions['std_error'], false);
        }
        if ($amount > 10000) {
            return $this->messagePage($langFunctions['std_error'], "{$lang['add_request_amount_maximum']}{$backToNew}", $langFunctions['std_error'], false);
        }
        $amount += 100;
        if ($amount + 100 > (int) $curUser['seedbonus']) {
            return $this->messagePage($langFunctions['std_error'], "{$lang['bouns_not_enough']}{$backToNew}", $langFunctions['std_error'], false);
        }
        if ((int) $curUser['class'] < 1) {
            return $this->messagePage(
                $langFunctions['std_error'],
                "{$langFunctions['std_permission_denied']}<a href='viewrequests.php'>{$langFunctions['std_click_here_to_goback']}</a>",
                $langFunctions['std_error'],
                false
            );
        }

        User::query()->where('id', $curUser['id'])->decrement('seedbonus', $amount);
        $created = Request::query()->create([
            'request' => $request->input('request'),
            'descr' => $request->input('descr'),
            'ori_descr' => $request->input('descr'),
            'amount' => (int) $request->input('amount'),
            'ori_amount' => (int) $request->input('amount'),
            'userid' => $curUser['id'],
            'added' => date('Y-m-d H:i:s'),
            'finish' => 'no',
        ]);

        return $this->messagePage(
            $langFunctions['std_success'],
            "{$lang['add_request_success']}，<a href='viewrequests.php?action=view&id=" . $created->id . "'>{$langFunctions['std_click_here_to_goback']}</a>",
            $langFunctions['std_success'],
            false
        );
    }

    // ------------------------------------------------------ supply (res) forms

    private function showResForm(HttpRequest $request, array $lang)
    {
        $langFunctions = $GLOBALS['lang_functions'];
        $id = (int) $request->query('id', 0);

        $content = '<form action=viewrequests.php method=post>'
            . '<input type=hidden name=action value=takeres />'
            . '<input type=hidden name=reqid value="' . $id . '" />'
            . $lang['type_in_torrent_id'] . ':' . getSchemeAndHttpHost() . '/details.php?id=<input type=text name=torrentid size=11/>'
            . '<input type=submit value=' . $langFunctions['submit_submit'] . '></form>'
            . "<a href='viewrequests.php?action=view&id=" . $id . "'>{$langFunctions['std_click_here_to_goback']}</a>";

        return $this->messagePage($lang['do_request'], $content, $lang['request'], false);
    }

    private function takeRes(HttpRequest $request, array $curUser, array $lang)
    {
        $langFunctions = $GLOBALS['lang_functions'];
        $reqid = (int) $request->input('reqid', 0);
        if ($reqid <= 0) {
            return $this->messagePage($langFunctions['std_error'], $lang['request_id_must_be_numeric'], $langFunctions['std_error']);
        }

        $arr = Request::query()->where('id', $reqid)->first();
        if (! $arr) {
            return $this->messagePage(
                $langFunctions['std_error'],
                "{$lang['request_deleted']}<a href='viewrequests.php'>{$langFunctions['std_click_here_to_goback']}</a>",
                $langFunctions['std_error'],
                false
            );
        }
        if ($arr->finish == 'yes') {
            return $this->messagePage(
                $langFunctions['std_error'],
                "{$lang['request_already_resolved']}<a href='viewrequests.php?action=view&id=" . $reqid . "'>{$langFunctions['std_click_here_to_goback']}</a>",
                $langFunctions['std_error'],
                false
            );
        }
        $torrentid = (string) $request->input('torrentid', '');
        if (! is_numeric($torrentid)) {
            return $this->messagePage(
                $langFunctions['std_error'],
                "{$lang['request_id_must_be_numeric']}<a href='viewrequests.php?action=res&id=" . $reqid . "'>{$langFunctions['std_click_here_to_goback']}</a>",
                $langFunctions['std_error'],
                false
            );
        }
        $torrent = Torrent::query()->where('id', (int) $torrentid)->first(['id']);
        if (! $torrent) {
            return $this->messagePage(
                $langFunctions['std_error'],
                "{$langFunctions['std_target_not_exists']}<a href='viewrequests.php?action=res&id=" . $reqid . "'>{$langFunctions['std_click_here_to_goback']}</a>",
                $langFunctions['std_error'],
                false
            );
        }
        if (DB::table('resreq')->where('reqid', $reqid)->where('torrentid', (int) $torrentid)->exists()) {
            return $this->messagePage(
                $langFunctions['std_error'],
                "{$lang['supply_already_exists']}<a href='viewrequests.php?action=res&id=" . $reqid . "'>{$langFunctions['std_click_here_to_goback']}</a>",
                $langFunctions['std_error'],
                false
            );
        }

        DB::table('resreq')->insert([
            'reqid' => $reqid,
            'torrentid' => (int) $torrentid,
        ]);

        $subject = $lang['message_please_confirm_supply'];
        $notifs = "{$lang['request_name']}:[url=viewrequests.php?id={$arr->id}] " . $arr->request . "[/url],{$lang['please_confirm_supply']}.";
        Message::add([
            'sender' => 0,
            'receiver' => $arr->userid,
            'subject' => $subject,
            'msg' => $notifs,
            'added' => now(),
        ]);

        return $this->messagePage(
            $langFunctions['std_success'],
            "{$lang['supply_success']}，<a href='viewrequests.php?action=view&id=" . $reqid . "'>{$langFunctions['std_click_here_to_goback']}</a>",
            $langFunctions['std_success'],
            false
        );
    }

    // --------------------------------------------------------------- add reward

    private function addAmount(HttpRequest $request, array $curUser, array $lang)
    {
        $langFunctions = $GLOBALS['lang_functions'];
        $reqid = (int) $request->input('reqid', 0);
        if ($reqid <= 0) {
            return $this->messagePage($langFunctions['std_error'], $lang['request_id_must_be_numeric'], $langFunctions['std_error']);
        }

        $arr = Request::query()->where('id', $reqid)->first();
        if (! $arr) {
            return $this->messagePage($langFunctions['std_error'], $lang['request_deleted'], $langFunctions['std_error']);
        }
        if ($arr->finish == 'yes') {
            return $this->messagePage($langFunctions['std_error'], $lang['request_already_resolved'], $langFunctions['std_error']);
        }
        $amount = (string) $request->input('amount', '');
        if (! is_numeric($amount)) {
            return $this->messagePage($langFunctions['std_error'], $lang['amount_must_be_numeric'], $langFunctions['std_error']);
        }
        $amount = (int) $amount;
        if ($amount < 100) {
            return $this->messagePage($langFunctions['std_error'], $lang['add_reward_amount_minimum'], $langFunctions['std_error']);
        }
        if ($amount > 5000) {
            return $this->messagePage($langFunctions['std_error'], $lang['add_reward_amount_maximum'], $langFunctions['std_error']);
        }
        $amount += 25;
        if ($amount > (int) $curUser['seedbonus']) {
            return $this->messagePage($langFunctions['std_error'], $lang['bouns_not_enough'], $langFunctions['std_error']);
        }

        User::query()->where('id', $curUser['id'])->decrement('seedbonus', $amount);
        Request::query()->where('id', $reqid)->increment('amount', (int) $request->input('amount'));

        return $this->messagePage(
            $langFunctions['std_success'],
            "{$lang['add_reward_success']}，<a href='viewrequests.php?action=view&id=" . $reqid . "'>{$langFunctions['std_click_here_to_goback']}</a>",
            $langFunctions['std_success'],
            false
        );
    }

    // ------------------------------------------------------------------ delete

    private function delete(HttpRequest $request, array $curUser, array $lang)
    {
        $langFunctions = $GLOBALS['lang_functions'];
        $id = (int) $request->query('id', 0);
        if ($id <= 0) {
            return $this->messagePage($langFunctions['std_error'], $lang['request_id_must_be_numeric'], $langFunctions['std_error']);
        }

        $arr = Request::query()->where('id', $id)->first();
        if (! $arr) {
            return $this->messagePage($langFunctions['std_error'], $lang['request_deleted'], $langFunctions['std_error']);
        }
        if (! $this->isUploaderOrAbove($curUser) && ! ($arr->userid == $curUser['id'] && $arr->finish == 'no')) {
            return $this->messagePage($langFunctions['std_error'], $langFunctions['std_permission_denied'], $langFunctions['std_error']);
        }

        if (! DB::table('resreq')->where('reqid', $id)->exists()) {
            KPS('+', $arr->amount * 8 / 10, $arr->userid);
        }

        DB::table('requests')->where('id', $id)->delete();
        DB::table('resreq')->where('reqid', $id)->delete();
        Comment::query()->where('request', $id)->delete();

        return $this->messagePage(
            $langFunctions['std_success'],
            "{$lang['delete_request_success']}，<a href='viewrequests.php'>{$langFunctions['std_click_here_to_goback']}</a>",
            $langFunctions['std_success'],
            false
        );
    }

    // ----------------------------------------------------------------- confirm

    private function confirm(HttpRequest $request, array $curUser, array $lang)
    {
        $langFunctions = $GLOBALS['lang_functions'];
        $id = (int) $request->input('id', 0);
        if ($id <= 0) {
            return $this->messagePage($langFunctions['std_error'], $lang['request_id_must_be_numeric'], $langFunctions['std_error']);
        }

        $arr = Request::query()->where('id', $id)->first();
        if (! $arr) {
            return $this->messagePage($langFunctions['std_error'], $lang['request_deleted'], $langFunctions['std_error']);
        }
        $torrentids = (array) $request->input('torrentid', []);
        if (empty($torrentids)) {
            return $this->messagePage($langFunctions['std_error'], $langFunctions['std_target_not_exists'], $langFunctions['std_error']);
        }
        if ($arr->userid != $curUser['id'] && ! $this->isUploaderOrAbove($curUser)) {
            return $this->messagePage($langFunctions['std_error'], $langFunctions['std_permission_denied'], $langFunctions['std_error']);
        }

        $amount = $arr->amount / count($torrentids);
        DB::table('requests')->where('id', $id)->update(['finish' => 'yes']);
        DB::table('resreq')
            ->where('reqid', $id)
            ->whereIn('torrentid', $torrentids)
            ->update(['chosen' => 'yes']);
        DB::table('resreq')->where('reqid', $id)->where('chosen', 'no')->delete();

        $owners = Torrent::query()->whereIn('id', $torrentids)->pluck('owner')->unique()->all();
        $subject = $lang['torrent_is_picked_for_request'];
        foreach ($owners as $owner) {
            $notifs = "{$lang['request_name']}:[url=viewrequests.php?id={$id}] " . $arr->request . "[/url].{$langFunctions['std_you_will_get']}: {$amount} {$langFunctions['text_bonus']}";
            Message::add([
                'sender' => 0,
                'receiver' => $owner,
                'added' => now(),
                'msg' => $notifs,
                'subject' => $subject,
            ]);
            User::query()->where('id', $owner)->increment('seedbonus', $amount);
        }

        return $this->messagePage(
            $langFunctions['std_success'],
            "{$lang['confirm_request_success']}，<a href='viewrequests.php?action=view&id=" . $id . "'>{$langFunctions['std_click_here_to_goback']}</a>",
            $langFunctions['std_success'],
            false
        );
    }

    // ----------------------------------------------------------------- message

    private function message(HttpRequest $request, array $curUser, array $lang)
    {
        $langFunctions = $GLOBALS['lang_functions'];
        $id = (int) $request->input('id', 0);
        if ($id <= 0) {
            return $this->messagePage($langFunctions['std_error'], $lang['request_id_must_be_numeric'], $langFunctions['std_error']);
        }

        $arr = Request::query()->where('id', $id)->first();
        if (! $arr) {
            return $this->messagePage($langFunctions['std_error'], $lang['request_deleted'], $langFunctions['std_error']);
        }
        $messageText = (string) $request->input('message', '');
        if ($messageText === '') {
            return $this->messagePage($langFunctions['std_error'], $lang['message_required'], $langFunctions['std_error']);
        }

        Comment::query()->insert([
            'user' => $curUser['id'],
            'request' => $id,
            'added' => date('Y-m-d H:i:s'),
            'text' => $messageText,
            'ori_text' => $messageText,
        ]);
        Request::query()->where('id', $id)->increment('comments');

        if ($curUser['id'] != $arr->userid) {
            Message::add([
                'sender' => 0,
                'receiver' => $arr->userid,
                'subject' => $lang['request_get_new_reply'],
                'msg' => " [url=viewrequests.php?action=view&id={$id}] " . $arr->request . '[/url]',
                'added' => now(),
            ]);
        }
        $ruserid = (int) $request->input('ruserid', 0);
        if ($ruserid && $ruserid != $curUser['id'] && $ruserid != $arr->userid) {
            Message::add([
                'sender' => 0,
                'receiver' => $ruserid,
                'subject' => $lang['request_comment_get_new_reply'],
                'msg' => " [url=viewrequests.php?action=view&id={$id}] " . $arr->request . '[/url]',
                'added' => now(),
            ]);
        }

        return redirect('viewrequests.php?action=view&id=' . $id);
    }

    // ------------------------------------------------------------- search form

    private function showSearchForm(array $lang)
    {
        $langFunctions = $GLOBALS['lang_functions'];

        $content = "<table border=1 cellspacing=0 cellpadding=5>\n";
        $content .= "<tr><td class=colhead align=left>{$langFunctions['text_search']}</td></tr>\n";
        $content .= "<tr><td class=toolbox align=left><form method=\"post\" action='viewrequests.php'>\n";
        $content .= "<input type=\"text\" name=\"query\" style=\"width:500px\" >\n";
        $content .= "<input type=\"hidden\" name=\"action\" value='list'>";
        $content .= "<input type=submit value='{$langFunctions['text_search']}'></form>\n";
        $content .= "</td></tr></table><br />\n";

        return view('request.details', [
            'content' => $content,
            'pageTitle' => $langFunctions['text_search'],
        ]);
    }
}
