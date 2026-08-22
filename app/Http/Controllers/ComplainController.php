<?php

namespace App\Http\Controllers;

use App\Models\Complain;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Nexus\Database\NexusLock;

class ComplainController extends Controller
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
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $lang = get_legacy_lang_file('complains');
        $GLOBALS['lang_complains'] = $lang;

        $isLogin = ! empty($curUser);
        $isAdmin = $currentUser && user_can('staffmem', false, $currentUser->id);
        $uid = (int) ($curUser['id'] ?? 0);

        if ($isLogin && ! $isAdmin) {
            abort(403);
        }
        if (! $isAdmin && ! Setting::getIsComplainEnabled()) {
            $content = $this->capture(function () use ($lang) {
                stderr($GLOBALS['lang_functions']['std_error'], $lang['complain_not_enabled'], true, false, false, false);
            });
            return view('complains', compact('content') + ['pageTitle' => $lang['text_complain']]);
        }

        // POST
        if ($request->isMethod('POST')) {
            return $this->handlePost($request, $curUser, $lang, $uid, $isAdmin);
        }

        // GET
        $action = (string) $request->query('action', '');
        if ($action === '') {
            $action = 'compose';
        }

        switch ($action) {
            case 'list':
                if (! $isAdmin) {
                    abort(403);
                }
                return $this->listAction($request, $lang);

            case 'view':
                return $this->viewAction($request, $lang, $isLogin, $isAdmin);

            case 'compose':
            default:
                $content = $this->capture(function () use ($lang) {
                    cur_user_check();
                    ?>
                    <h2><?= $lang['text_new_complain'] ?></h2>
                    <form action="" method="post">
                        <input type="hidden" name="action" value="new" />
                        <?php
                        $inputStyle = 'style="width: min(100%, 420px); min-width: 180px; border: 1px solid gray; box-sizing: border-box"';
                        $textareaStyle = 'style="width: min(100%, 420px); min-width: 180px; border: 1px solid gray; box-sizing: border-box; height: 250px; resize: vertical;"';
                        ?>
                        <table border="0" cellpadding="5">
                            <tr><td class="rowhead"><?php echo $lang['text_new_email']?></td><td class="rowfollow" align="left"><input type="email" name="email" <?php echo $inputStyle; ?> autocomplete="email" /></td></tr>
                            <tr><td class="rowhead"><?php echo $lang['text_new_body']?></td><td class="rowfollow" align="left"><textarea name="body" <?php echo $textareaStyle; ?> placeholder="<?= $lang['text_new_body_placeholder'] ?>"></textarea></td></tr>
                            <?php show_image_code(); ?>
                            <tr><td class="toolbox" colspan="2" align="center"><input type="submit" value="<?= $lang['text_new_submit']?>" class="btn" /></td></tr>
                        </table>
                    </form>
                    <?php
                });
                return view('complains', compact('content') + ['pageTitle' => $lang['text_complain']]);
        }
    }

    private function handlePost(Request $request, array $curUser, array $lang, int $uid, bool $isAdmin)
    {
        $action = (string) $request->input('action', '');
        switch ($action) {
            case 'new':
                return $this->postNew($request, $lang);

            case 'reply':
                return $this->postReply($request, $lang, $uid);

            case 'answered':
            case 'unanswered':
                if (! $isAdmin) {
                    abort(403);
                }
                return $this->postToggleAnswered($request, $action);

            default:
                abort(403);
        }
    }

    private function postNew(Request $request, array $lang)
    {
        cur_user_check();
        check_code($request->input('imagehash'), $request->input('imagestring'), 'complains.php');
        NexusLock::lockOrFail('complains:lock:' . getip(), 10);
        $email = $request->input('email');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->messagePage($GLOBALS['lang_functions']['std_error'], $lang['text_new_failure']);
        }
        NexusLock::lockOrFail('complains:lock:' . $email, 600);
        $body = trim((string) $request->input('body', ''));
        if (empty($email) || $body === '') {
            return $this->messagePage($GLOBALS['lang_functions']['std_error'], $lang['text_new_failure']);
        }
        $user = User::query()->where('email', $email)->where('enabled', 'no')->first();
        if (! $user) {
            return $this->messagePage($GLOBALS['lang_functions']['std_error'], $lang['text_new_failure']);
        }
        $uuid = \Illuminate\Support\Str::uuid()->toString();
        DB::table('complains')->insert([
            'uuid' => $uuid,
            'email' => $email,
            'body' => $body,
            'added' => now(),
            'ip' => getip(),
        ]);
        $insertId = DB::getPdo()->lastInsertId();
        $savedUuid = DB::table('complains')->where('id', $insertId)->value('uuid');
        Cache::delete('COMPLAINTS_COUNT_CACHE');

        return redirect('complains.php?action=view&id=' . $savedUuid);
    }

    private function postReply(Request $request, array $lang, int $uid)
    {
        $id = (int) $request->input('id', 0);
        $body = trim((string) $request->input('body', ''));
        if (! $id || $body === '') {
            return $this->messagePage($GLOBALS['lang_functions']['std_error'], $lang['text_new_failure']);
        }

        $complain = Complain::query()->findOrFail($id);

        DB::table('complain_replies')->insert([
            'complain' => $id,
            'userid' => $uid,
            'added' => now(),
            'body' => $body,
            'ip' => getip(),
        ]);

        if ($uid > 0) {
            try {
                $toolRep = new \App\Repositories\ToolRepository();
                $toolRep->sendMail(
                    $complain->email,
                    $lang['reply_notify_subject'],
                    sprintf($lang['reply_notify_body'], get_setting('basic.SITENAME'), getSchemeAndHttpHost() . '/complains.php?action=view&id=' . $complain->uuid)
                );
            } catch (\Exception $exception) {
                do_log($exception->getMessage(), 'error');
            }
        }

        return redirect($request->headers->get('referer', 'complains.php'));
    }

    private function postToggleAnswered(Request $request, string $action)
    {
        $id = (int) $request->input('id', 0);
        if (! $id) {
            abort(403);
        }
        $answered = $action === 'answered' ? 1 : 0;
        DB::table('complains')->where('id', $id)->update(['answered' => $answered]);
        Cache::delete('COMPLAINTS_COUNT_CACHE');

        return redirect($request->headers->get('referer', 'complains.php'));
    }

    private function listAction(Request $request, array $lang)
    {
        $showTable = function ($rows) use ($lang) {
            echo '<table width="100%">';
            echo '<tr><td class="colhead">' . $lang['th_complain_at'] . '</td>'
                . '<td class="colhead">' . $lang['th_complain_account'] . '</td>'
                . '<td class="colhead">' . $lang['th_action_view'] . '</td></tr>';
            foreach ($rows as $row) {
                echo '<tr><td class="rowfollow">' . gettime($row->added, true, false) . '</td>'
                    . '<td class="rowfollow">' . htmlspecialchars($row->email) . '</td>'
                    . '<td class="rowfollow"><a href="?action=view&id=' . $row->uuid . '" class="faqlink">' . $lang['th_action_view'] . '</a></td></tr>';
            }
            echo '</table>';
        };

        $content = $this->capture(function () use ($request, $lang, $showTable) {
            $pendingShown = false;
            if (! $request->has('page')) {
                $pending = DB::table('complains')
                    ->where('answered', 0)
                    ->orderByDesc('id')
                    ->get(['added', 'uuid', 'email']);
                print('<h2>' . $lang['pending_complaints'] . '</h2>');
                if ($pending->isNotEmpty()) {
                    $showTable($pending);
                } else {
                    print($lang['no_pending_complaints']);
                }
                $pendingShown = true;
            }

            print('<h2>' . $lang['complaints_processed'] . '</h2>');
            $count = DB::table('complains')->where('answered', 1)->count();
            $perpage = 20;
            $href = '?action=list&';
            list($pagertop, $pagerbottom, $limit) = pager($perpage, $count, $href);
            preg_match('/limit (\d+) offset (\d+)/', $limit, $matches);
            $processed = DB::table('complains')
                ->where('answered', 1)
                ->orderByDesc('id')
                ->limit((int) ($matches[1] ?? $perpage))
                ->offset((int) ($matches[2] ?? 0))
                ->get(['added', 'uuid', 'email']);
            if ($processed->isNotEmpty()) {
                print($pagertop);
                $showTable($processed);
                print($pagerbottom);
            } else {
                print($lang['no_complaints_have_been_processed']);
            }
        });

        return view('complains', compact('content') + ['pageTitle' => $lang['text_complain']]);
    }

    private function viewAction(Request $request, array $lang, bool $isLogin, bool $isAdmin)
    {
        $uuid = (string) $request->query('id', '');
        if (strlen($uuid) !== 36) {
            abort(403);
        }

        $complain = DB::table('complains')->where('uuid', $uuid)->first();
        if (! $complain) {
            abort(403);
        }

        $user = User::query()->where('email', $complain->email)->first();
        $replies = DB::table('complain_replies')
            ->where('complain', $complain->id)
            ->orderByDesc('id')
            ->get();

        return view('complains_view', [
            'pageTitle' => $lang['text_complain'],
            'lang' => $lang,
            'complain' => $complain,
            'user' => $user,
            'replies' => $replies,
            'isLogin' => $isLogin,
            'isAdmin' => $isAdmin,
        ]);
    }

    private function messagePage(string $heading, string $text, string $pageTitle = 'Error', bool $htmlstrip = true)
    {
        $content = $this->capture(function () use ($heading, $text, $htmlstrip) {
            stderr($heading, $text, $htmlstrip, false, false, false);
        });

        return view('complains', compact('content') + ['pageTitle' => $pageTitle]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}