<?php

namespace App\Http\Controllers;

use App\Models\Chronicle;
use App\Models\Fun;
use App\Models\News;
use App\Models\Poll;
use App\Models\PollAnswer;
use App\Models\Setting;
use App\Models\SiteLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Site log page — replaces legacy public/log.php.
 *
 * GET/POST /log.php?action=dailylog|chronicle|funbox|news|poll. The page shows
 * the daily site log (sitelog), the chronicle (with add/edit/delete for
 * chrmanage users), the funbox, the news list and the previous-polls overview
 * (with poll deletion for chrmanage users).
 */
class LogController extends Controller
{
    public function web(Request $request)
    {
        [$curUser, $lang] = $this->bootstrap($request);

        if (!user_can('log', false, $curUser['id'])) {
            $message = $lang['std_sorry'] . $lang['std_permission_denied_only']
                . get_user_class_name((int) get_setting('authority.log', \App\Models\User::CLASS_POWER_USER), false, true, true)
                . sprintf($lang['std_or_above_can_view'], Setting::getSiteName());
            return $this->messagePage($lang['std_sorry'], $message, $lang['head_site_log'] ?? 'Log', false);
        }

        $action = htmlspecialchars(trim((string) ($request->input('action', $request->query('action', '')))));
        if ($action === '') {
            $action = 'dailylog';
        }
        if (!in_array($action, ['dailylog', 'chronicle', 'funbox', 'news', 'poll'], true)) {
            return $this->messagePage($lang['std_error'], $lang['std_invalid_action'], $lang['std_error']);
        }

        switch ($action) {
            case 'chronicle':
                return $this->webChronicle($request, $curUser, $lang);
            case 'funbox':
                return $this->webFunbox($request, $curUser, $lang);
            case 'news':
                return $this->webNews($request, $curUser, $lang);
            case 'poll':
                return $this->webPoll($request, $curUser, $lang);
            default:
                return $this->webDailyLog($request, $curUser, $lang);
        }
    }

    /**
     * Authenticate, set the legacy globals used by the shared helpers and
     * return the current user array + log lang file.
     */
    private function bootstrap(Request $request): array
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        if (!$currentUser) {
            abort(401);
        }
        $curUser = $currentUser->toArray();
        if (($curUser['parked'] ?? '') == 'yes') {
            abort(403, 'Your account is parked.');
        }

        $lang = get_legacy_lang_file('log');

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_log'] = $lang;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();
        $GLOBALS['showfunbox_main'] = get_setting('main.showfunbox', 'no');

        return [$curUser, $lang];
    }

    // ------------------------------------------------------------- dailylog

    /**
     * Site log listing (legacy action=dailylog). Confilog users may filter the
     * security level (mod/normal/all), everyone else only sees normal entries.
     */
    private function webDailyLog(Request $request, array $curUser, array $lang)
    {
        $q = htmlspecialchars(trim((string) $request->query('query', '')));
        $search = (string) $request->query('search', '');
        $canConfilog = user_can('confilog', false, $curUser['id']);

        $queryBuilder = SiteLog::query();
        if ($canConfilog) {
            switch ($search) {
                case 'mod':
                    $queryBuilder->where('security_level', 'mod');
                    break;
                case 'normal':
                    $queryBuilder->where('security_level', 'normal');
                    break;
                case 'all':
                    break;
            }
        } else {
            $queryBuilder->where('security_level', 'normal');
        }
        if ($q !== '') {
            $queryBuilder->where('txt', 'like', '%' . $q . '%');
        }
        $logs = $queryBuilder->orderByDesc('added')->paginate(50)->withQueryString();

        $content = $this->capture(function () use ($lang, $q, $search, $canConfilog, $logs, $request) {
            $this->logMenu($lang, 'dailylog');
            $opts = $canConfilog
                ? ['all' => $lang['text_all'], 'normal' => $lang['text_normal'], 'mod' => $lang['text_mod']]
                : [];
            $this->searchTable($lang, $lang['text_search_log'], 'dailylog', $q, $search, $opts, $request);

            if ($logs->isEmpty()) {
                print($lang['text_log_empty']);
            } else {
                print('<table width="940" border="1" cellspacing="0" cellpadding="5">');
                print('<tr><td class="colhead" align="center"><img class="time" src="pic/trans.gif" alt="time" title="' . $lang['title_time_added'] . '" /></td>'
                    . '<td class="colhead" align="left">' . $lang['col_event']);
                if ($canConfilog) {
                    print('<td class="colhead" align="left">' . $lang['col_user'] . '</td>');
                }
                print('</td></tr>');
                foreach ($logs as $arr) {
                    $color = '';
                    if (str_contains($arr['txt'], 'was uploaded by')) {
                        $color = 'green';
                    }
                    if (str_contains($arr['txt'], 'was deleted by')) {
                        $color = 'red';
                    }
                    if (str_contains($arr['txt'], 'was added to the Request section')) {
                        $color = 'purple';
                    }
                    if (str_contains($arr['txt'], 'was edited by')) {
                        $color = 'blue';
                    }
                    if (str_contains($arr['txt'], 'settings updated by')) {
                        $color = 'darkred';
                    }
                    print('<tr><td class="rowfollow nowrap" align="center">' . gettime($arr['added'], true, false) . '</td>'
                        . '<td class="rowfollow" align="left"><font color="' . $color . '">' . htmlspecialchars($arr['txt']) . '</font></td>');
                    if ($canConfilog) {
                        print('<td class="rowfollow" align="left">' . ($arr['uid'] > 0 ? get_username($arr['uid']) : 'System') . '</td>');
                    }
                    print('</tr>');
                }
                print('</table>');
                if ($logs->hasPages()) {
                    print(view('partials.pagination', ['paginator' => $logs])->render());
                }
            }
            print($lang['time_zone_note']);
        });

        return view('log', compact('content') + ['pageTitle' => $lang['head_site_log']]);
    }

    // ------------------------------------------------------------- chronicle

    /**
     * Chronicle listing + add/edit/delete (legacy action=chronicle). Add/update
     * are POST (do=add/update), del/edit are GET (do=del/edit); chrmanage
     * permission is required for all of them.
     */
    private function webChronicle(Request $request, array $curUser, array $lang)
    {
        $q = htmlspecialchars(trim((string) $request->query('query', '')));
        $canChrManage = user_can('chrmanage', false, $curUser['id']);

        $do = (string) ($request->input('do', $request->query('do', '')));
        $editChronicle = null;
        if (in_array($do, ['add', 'update', 'del', 'edit'], true)) {
            if (!$canChrManage) {
                return $this->messagePage($lang['std_sorry'], $lang['std_permission_denied'], $lang['head_chronicle']);
            }
            if ($do == 'add') {
                $txt = (string) $request->input('txt', '');
                Chronicle::query()->insert([
                    'userid' => $curUser['id'],
                    'added' => now(),
                    'txt' => $txt,
                ]);
            } elseif ($do == 'update') {
                $id = (int) $request->input('id', 0);
                if (!$id) {
                    return redirect('log.php?action=chronicle');
                }
                Chronicle::query()->where('id', $id)->update(['txt' => (string) $request->input('txt', '')]);
            } else {
                $id = (int) $request->query('id', 0);
                if (!$id) {
                    return redirect('log.php?action=chronicle');
                }
                if ($do == 'del') {
                    Chronicle::query()->where('id', $id)->delete();
                } elseif ($do == 'edit') {
                    $editChronicle = Chronicle::query()->where('id', $id)->first();
                }
            }
        }

        $queryBuilder = Chronicle::query();
        if ($q !== '') {
            $queryBuilder->where('txt', 'like', '%' . $q . '%');
        }
        $chronicles = $queryBuilder->orderByDesc('added')->paginate(50)->withQueryString();

        $content = $this->capture(function () use ($lang, $q, $canChrManage, $chronicles, $editChronicle, $request) {
            $this->logMenu($lang, 'chronicle');
            $this->searchTable($lang, $lang['text_search_chronicle'], 'chronicle', $q, '', [], $request);
            if ($canChrManage) {
                $this->addItem($lang, $lang['text_add_chronicle'], 'chronicle', $request);
            }
            if ($editChronicle) {
                $this->editItem($lang, $lang['text_edit_chronicle'], 'chronicle', $editChronicle, $request);
            }

            if ($chronicles->isEmpty()) {
                print($lang['text_chronicle_empty']);
            } else {
                print('<table width="940" border="1" cellspacing="0" cellpadding="5">');
                print('<tr><td class="colhead" align="center">' . $lang['col_date'] . '</td>'
                    . '<td class="colhead" align="left">' . $lang['col_event'] . '</td>'
                    . ($canChrManage ? '<td class="colhead" align="center">' . $lang['col_modify'] . '</td>' : '') . '</tr>');
                foreach ($chronicles as $arr) {
                    $date = gettime($arr['added'], true, false);
                    print('<tr><td class="rowfollow" align="center"><nobr>' . $date . '</nobr></td>'
                        . '<td class="rowfollow" align="left">' . format_comment($arr['txt'], true, false, true) . '</td>'
                        . ($canChrManage
                            ? '<td align="center" nowrap><b><a href="?action=chronicle&do=edit&id=' . $arr['id'] . '">' . $lang['text_edit']
                                . '</a>&nbsp;|&nbsp;<a href="?action=chronicle&do=del&id=' . $arr['id'] . '"><font color="red">'
                                . $lang['text_delete'] . '</font></a></b></td>'
                            : '') . '</tr>');
                }
                print('</table>');
                if ($chronicles->hasPages()) {
                    print(view('partials.pagination', ['paginator' => $chronicles])->render());
                }
            }
            print($lang['time_zone_note']);
        });

        return view('log', compact('content') + ['pageTitle' => $lang['head_chronicle']]);
    }

    // --------------------------------------------------------------- funbox

    private function webFunbox(Request $request, array $curUser, array $lang)
    {
        $q = htmlspecialchars(trim((string) $request->query('query', '')));
        $search = (string) $request->query('search', '');

        $queryBuilder = Fun::query()->where('status', '!=', Fun::STATUS_BANNED);
        if ($q !== '') {
            switch ($search) {
                case 'title':
                    $queryBuilder->where('title', 'like', '%' . $q . '%');
                    break;
                case 'body':
                    $queryBuilder->where('body', 'like', '%' . $q . '%');
                    break;
                case 'both':
                    $queryBuilder->where(function ($query) use ($q) {
                        $query->where('body', 'like', '%' . $q . '%')
                            ->orWhere('title', 'like', '%' . $q . '%');
                    });
                    break;
            }
        }
        $funs = $queryBuilder->orderByDesc('added')->paginate(10)->withQueryString();

        $content = $this->capture(function () use ($lang, $q, $search, $funs, $request) {
            $this->logMenu($lang, 'funbox');
            $opts = ['title' => $lang['text_title'], 'body' => $lang['text_body'], 'both' => $lang['text_both']];
            $this->searchTable($lang, $lang['text_search_funbox'], 'funbox', $q, $search, $opts, $request);

            if ($funs->isEmpty()) {
                print($lang['text_funbox_empty']);
            } else {
                foreach ($funs as $arr) {
                    $date = gettime($arr['added'], true, false);
                    print('<table width="940" border="1" cellspacing="0" cellpadding="5">');
                    print('<tr><td class="rowhead" width="10%">' . $lang['col_title'] . '</td><td class="rowfollow" align="left">'
                        . htmlspecialchars($arr['title']) . ' - <b>' . htmlspecialchars($arr['status']) . '</b></td></tr>'
                        . '<tr><td class="rowhead" width="10%">' . $lang['col_date'] . '</td><td class="rowfollow" align="left">' . $date . '</td></tr>'
                        . '<tr><td class="rowhead" width="10%">' . $lang['col_body'] . '</td><td class="rowfollow" align="left">'
                        . format_comment($arr['body'], false, false, true) . '</td></tr>');
                    print('</table><br />');
                }
                if ($funs->hasPages()) {
                    print(view('partials.pagination', ['paginator' => $funs])->render());
                }
            }
            print($lang['time_zone_note']);
        });

        return view('log', compact('content') + ['pageTitle' => $lang['head_funbox']]);
    }

    // ----------------------------------------------------------------- news

    private function webNews(Request $request, array $curUser, array $lang)
    {
        $q = htmlspecialchars(trim((string) $request->query('query', '')));
        $search = (string) $request->query('search', '');

        $queryBuilder = News::query();
        if ($q !== '') {
            switch ($search) {
                case 'title':
                    $queryBuilder->where('title', 'like', '%' . $q . '%');
                    break;
                case 'body':
                    $queryBuilder->where('body', 'like', '%' . $q . '%');
                    break;
                case 'both':
                    $queryBuilder->where(function ($query) use ($q) {
                        $query->where('body', 'like', '%' . $q . '%')
                            ->orWhere('title', 'like', '%' . $q . '%');
                    });
                    break;
            }
        }
        $news = $queryBuilder->orderByDesc('added')->paginate(20)->withQueryString();

        $content = $this->capture(function () use ($lang, $q, $search, $news, $request) {
            $this->logMenu($lang, 'news');
            $opts = ['title' => $lang['text_title'], 'body' => $lang['text_body'], 'both' => $lang['text_both']];
            $this->searchTable($lang, $lang['text_search_news'], 'news', $q, $search, $opts, $request);

            if ($news->isEmpty()) {
                print($lang['text_news_empty']);
            } else {
                foreach ($news as $arr) {
                    $date = gettime($arr['added'], true, false);
                    print('<table width="940" border="1" cellspacing="0" cellpadding="5">');
                    print('<tr><td class="rowhead" width="10%">' . $lang['col_title'] . '</td><td class="rowfollow" align="left">'
                        . htmlspecialchars($arr['title']) . '</td></tr>'
                        . '<tr><td class="rowhead" width="10%">' . $lang['col_date'] . '</td><td class="rowfollow" align="left">' . $date . '</td></tr>'
                        . '<tr><td class="rowhead" width="10%">' . $lang['col_body'] . '</td><td class="rowfollow" align="left">'
                        . format_comment($arr['body'], false, false, true) . '</td></tr>');
                    print('</table><br />');
                }
                if ($news->hasPages()) {
                    print(view('partials.pagination', ['paginator' => $news])->render());
                }
            }
            print($lang['time_zone_note']);
        });

        return view('log', compact('content') + ['pageTitle' => $lang['head_news']]);
    }

    // ------------------------------------------------------------------ poll

    private function webPoll(Request $request, array $curUser, array $lang)
    {
        $do = (string) $request->query('do', '');
        $pollid = (int) $request->query('pollid', 0);
        $returnto = htmlspecialchars((string) $request->query('returnto', ''));

        if ($do == 'delete') {
            if (!user_can('chrmanage', false, $curUser['id'])) {
                return $this->messagePage($lang['std_error'], $lang['std_permission_denied'], $lang['head_previous_polls']);
            }
            if (!is_valid_id($pollid)) {
                return $this->messagePage($lang['std_error'], $lang['std_permission_denied'], $lang['head_previous_polls']);
            }

            $sure = (string) $request->query('sure', '');
            if ($sure !== '1') {
                $text = $lang['std_delete_poll'] . $lang['std_delete_poll_confirmation']
                    . "<a href=\"?action=poll&do=delete&pollid=$pollid&returnto=$returnto&sure=1\">" . $lang['std_here_if_sure'] . '</a>';
                return $this->messagePage($lang['std_delete_poll'], $text, $lang['std_delete_poll'], false);
            }

            PollAnswer::query()->where('pollid', $pollid)->delete();
            Poll::query()->where('id', $pollid)->delete();
            Cache::forget(IndexController::CACHE_POLL_CONTENT);
            Cache::forget(IndexController::CACHE_POLL_RESULT);

            if ($returnto == 'main') {
                return redirect(get_protocol_prefix() . Setting::getBaseUrl());
            }
            return redirect(get_protocol_prefix() . Setting::getBaseUrl() . '/log.php?action=poll&deleted=1');
        }

        $pollCount = Poll::query()->count();
        if ($pollCount == 0) {
            return $this->messagePage($lang['std_sorry'], $lang['std_no_polls'], $lang['head_previous_polls']);
        }
        $polls = Poll::query()->orderByDesc('id')->skip(1)->take(max(0, $pollCount - 1))->get();

        $content = $this->capture(function () use ($lang, $curUser, $polls, $request) {
            $this->logMenu($lang, 'poll');
            print('<table border="1" cellspacing="0" width="940" cellpadding="5">');

            $canPollManage = user_can('pollmanage', false, $curUser['id']);
            foreach ($polls as $poll) {
                print('<tr><td align="center">');
                $added = gettime($poll['added'], true, false);
                print('<p class="sub">' . $added);
                if ($canPollManage) {
                    print(' - [<a href="makepoll.php?action=edit&pollid=' . $poll['id'] . '"><b>' . $lang['text_edit'] . '</b></a>]');
                    print(' - [<a href="?action=poll&do=delete&pollid=' . $poll['id'] . '"><b>' . $lang['text_delete'] . '</b></a>]');
                }
                print('<a name="' . $poll['id'] . '"></p>');
                print('<table class="main" border="1" cellspacing="0" cellpadding="5"><tr><td class="text">');
                print('<p align="center"><b>' . htmlspecialchars($poll['question']) . '</b></p>');

                $pollanswers = PollAnswer::query()->where('pollid', $poll['id'])->where('selection', '<', 20)->get();
                $tvotes = $pollanswers->count();

                $o = [];
                for ($i = 0; $i <= Poll::MAX_OPTION_INDEX; $i++) {
                    $o[$i] = $poll['option' . $i];
                }

                $vs = [];
                foreach ($pollanswers as $pollanswer) {
                    $sel = (int) $pollanswer['selection'];
                    if (!isset($vs[$sel])) {
                        $vs[$sel] = 0;
                    }
                    $vs[$sel]++;
                }

                $os = [];
                reset($o);
                for ($i = 0; $i < count($o); ++$i) {
                    if ($o[$i]) {
                        $os[$i] = [$vs[$i] ?? 0, $o[$i]];
                    }
                }

                print('<table width="100%" class="main" border="0" cellspacing="0" cellpadding="0">');
                $i = 0;
                while (isset($os[$i])) {
                    $a = $os[$i];
                    $p = $tvotes > 0 ? round($a[0] / $tvotes * 100) : 0;
                    print('<tr><td class="embedded">' . htmlspecialchars($a[1]) . '&nbsp;&nbsp;</td><td class="embedded nowrap">'
                        . '<img class="bar_end" src="pic/trans.gif" alt="" /><img class="unsltbar" src="pic/trans.gif" style="width: ' . ($p * 3) . 'px" />'
                        . '<img class="bar_end" src="pic/trans.gif" alt="" /> ' . $p . '%</td></tr>');
                    ++$i;
                }
                print('</table>');
                $tvotesFormatted = number_format($tvotes);
                print('<p align="center">' . $lang['text_votes'] . $tvotesFormatted . '</p>');
                print('</td></tr></table><br /><br />');
                print('</p></td></tr>');
            }
            print('</table>');
            print($lang['time_zone_note']);
        });

        return view('log', compact('content') + ['pageTitle' => $lang['head_previous_polls']]);
    }

    // ---------------------------------------------------------- shared markup

    private function logMenu(array $lang, string $selected = 'dailylog')
    {
        print('<div id="lognav"><ul id="logmenu" class="menu">');
        print('<li' . ($selected == 'dailylog' ? ' class="selected"' : '') . '><a href="?action=dailylog">' . $lang['text_daily_log'] . '</a></li>');
        print('<li' . ($selected == 'chronicle' ? ' class="selected"' : '') . '><a href="?action=chronicle">' . $lang['text_chronicle'] . '</a></li>');
        if (($GLOBALS['showfunbox_main'] ?? '') == 'yes') {
            print('<li' . ($selected == 'funbox' ? ' class="selected"' : '') . '><a href="?action=funbox">' . $lang['text_funbox'] . '</a></li>');
        }
        print('<li' . ($selected == 'news' ? ' class="selected"' : '') . '><a href="?action=news">' . $lang['text_news'] . '</a></li>');
        print('<li' . ($selected == 'poll' ? ' class="selected"' : '') . '><a href="?action=poll">' . $lang['text_poll'] . '</a></li>');
        print('</ul></div>');
    }

    private function searchTable(array $lang, string $title, string $action, string $q, string $search, array $opts, Request $request)
    {
        print('<table border="1" cellspacing="0" width="940" cellpadding="5">');
        print('<tr><td class="colhead" align="left">' . $title . '</td></tr>');
        print('<tr><td class="toolbox" align="left"><form method="get" action="' . $request->getRequestUri() . '">');
        print('<input type="text" name="query" style="width:500px" value="' . $q . '">');
        if ($opts) {
            print($lang['text_in'] . '<select name="search">');
            foreach ($opts as $value => $text) {
                print("<option value='$value'" . ($search == $value ? ' selected' : '') . ">$text</option>");
            }
            print('</select>');
        }
        print('<input type="hidden" name="action" value="' . $action . '">&nbsp;&nbsp;');
        print('<input type="submit" value="' . $lang['submit_search'] . '"></form>');
        print('</td></tr></table><br />');
    }

    private function addItem(array $lang, string $title, string $action, Request $request)
    {
        print('<table border="1" cellspacing="0" width="940" cellpadding="5">');
        print('<tr><td class="colhead" align="left">' . $title . '</td></tr>');
        print('<tr><td class="toolbox" align="left"><form method="post" action="' . $request->getRequestUri() . '">');
        print('<textarea name="txt" style="width:500px" rows="3">' . $title . '</textarea>');
        print('<input type="hidden" name="action" value="' . $action . '">');
        print('<input type="hidden" name="do" value="add">');
        print('<input type="submit" value="' . $lang['submit_add'] . '"></form>');
        print('</td></tr></table><br />');
    }

    private function editItem(array $lang, string $title, string $action, Chronicle $chronicle, Request $request)
    {
        print('<table border="1" cellspacing="0" width="940" cellpadding="5">');
        print('<tr><td class="colhead" align="left">' . $title . '</td></tr>');
        print('<tr><td class="toolbox" align="left"><form method="post" action="' . $request->getRequestUri() . '">');
        print('<textarea name="txt" style="width:500px" rows="3">' . $chronicle['txt'] . '</textarea>');
        print('<input type="hidden" name="action" value="' . $action . '">');
        print('<input type="hidden" name="do" value="update">');
        print('<input type="hidden" name="id" value="' . $chronicle['id'] . '">');
        print('<input type="submit" value="' . $lang['submit_okay'] . '" style="height: 20px" /></form>');
        print('</td></tr></table><br />');
    }

    /**
     * Full-page message (legacy stderr() equivalent) rendered through Blade.
     */
    private function messagePage(string $heading, string $text, string $pageTitle = 'Log', bool $htmlstrip = true)
    {
        $content = $this->capture(function () use ($heading, $text, $htmlstrip) {
            stderr($heading, $text, $htmlstrip, false, false, false);
        });

        return view('log', compact('content') + ['pageTitle' => $pageTitle]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}
