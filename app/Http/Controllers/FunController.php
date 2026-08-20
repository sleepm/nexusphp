<?php

namespace App\Http\Controllers;

use App\Models\Fun;
use App\Models\FunVote;
use App\Models\Message;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Legacy public/fun.php migration — the "fun stuff" box (funmanage gate).
 *
 * GET/POST /fun.php?action=view|new|add|edit|delete|ban|vote. The home page
 * embeds /fun.php?action=view in an iframe and votes via /fun.php?action=vote.
 */
class FunController extends Controller
{
    public function web(Request $request)
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        if (! $currentUser) {
            abort(401);
        }
        $curUser = $currentUser->toArray();
        $lang = get_legacy_lang_file('fun');

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_fun'] = $lang;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();

        $action = (string) $request->query('action', '');
        if ($action === '') {
            $action = (string) $request->input('action', '');
        }
        if ($action === '') {
            $action = 'view';
        }

        switch ($action) {
            case 'view':
                return $this->webView($request, $curUser, $lang);
            case 'new':
                return $this->webNew($request, $curUser, $lang);
            case 'add':
                return $this->webAdd($request, $curUser, $lang);
            case 'edit':
                return $this->webEdit($request, $curUser, $lang);
            case 'delete':
                return $this->webDelete($request, $curUser, $lang);
            case 'ban':
                return $this->webBan($request, $curUser, $lang);
            case 'vote':
                return $this->webVote($request, $curUser, $lang);
            default:
                abort(400, $lang['std_error'] ?? 'Error');
        }
    }

    // ------------------------------------------------------------------- view

    private function webView(Request $request, array $curUser, array $lang)
    {
        $row = Cache::remember(IndexController::CACHE_FUN_CONTENT, 1043, function () {
            return Fun::query()
                ->whereNotIn('status', [Fun::STATUS_BANNED, Fun::STATUS_DULL])
                ->orderByDesc('added')
                ->first();
        });
        if (! $row) {
            return response("<html><head><title>{$lang['head_fun']}</title>"
                . '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
                . '</head><body class="inframe"></body></html>');
        }
        $username = get_username($row->userid, false, true, true, true, false, false, '', false);
        $time = ($curUser['timetype'] ?? '') != 'timealive'
            ? $lang['text_on'] . $row->added->format('Y-m-d H:i:s')
            : $lang['text_blank'] . gettime($row->added, true, false);

        return view('fun', compact('lang', 'row', 'username', 'time'));
    }

    // ------------------------------------------------------------------- new

    private function webNew(Request $request, array $curUser, array $lang)
    {
        $latest = $this->latestFun();
        if ($latest && ! $this->needNew($latest)) {
            return $this->messagePage(
                $lang['std_error'],
                $lang['std_the_newest_fun_item'] . htmlspecialchars($latest->title)
                . $lang['std_posted_on'] . $latest->added->format('Y-m-d H:i:s') . $lang['std_need_to_wait'],
                $lang['head_new_fun']
            );
        }

        return view('fun_form', [
            'lang' => $lang,
            'langFunctions' => $GLOBALS['lang_functions'],
            'fun' => null,
            'pageTitle' => $lang['head_new_fun'],
            'title' => $lang['text_submit_new_fun'],
            'formAction' => 'add',
            'subject' => '',
            'body' => '',
        ]);
    }

    private function webAdd(Request $request, array $curUser, array $lang)
    {
        $latest = $this->latestFun();
        if ($latest && ! $this->needNew($latest)) {
            return $this->messagePage(
                $lang['std_error'],
                $lang['std_the_newest_fun_item'] . htmlspecialchars($latest->title)
                . $lang['std_posted_on'] . $latest->added->format('Y-m-d H:i:s') . $lang['std_need_to_wait'],
                $lang['head_new_fun']
            );
        }

        $body = (string) $request->input('body', '');
        if ($body === '') {
            return $this->messagePage($lang['std_error'], $lang['std_body_is_empty'], $lang['std_error']);
        }
        $title = htmlspecialchars(trim((string) $request->input('subject', '')));
        if ($title === '') {
            return $this->messagePage($lang['std_error'], $lang['std_title_is_empty'], $lang['std_error']);
        }

        $fun = Fun::query()->create([
            'userid' => $curUser['id'],
            'added' => now(),
            'body' => $body,
            'title' => $title,
            'status' => Fun::STATUS_NORMAL,
        ]);
        $this->clearFunCaches($fun->id);

        if ($fun->id > 0) {
            return redirect(get_protocol_prefix() . Setting::getBaseUrl() . '/index.php');
        }
        return $this->messagePage($lang['std_error'], $lang['std_error_happened'], $lang['std_error']);
    }

    // ------------------------------------------------------------------ edit

    private function webEdit(Request $request, array $curUser, array $lang)
    {
        $id = (int) $request->query('id', 0);
        if (! is_valid_id($id)) {
            return $this->messagePage($lang['std_error'], $lang['std_invalid_id'], $lang['std_error']);
        }
        $fun = Fun::query()->where('id', $id)->first();
        if (! $fun) {
            return $this->messagePage($lang['std_error'], $lang['std_invalid_id'], $lang['std_error']);
        }
        if ((int) $fun->userid !== (int) $curUser['id'] && ! user_can('funmanage', false, $curUser['id'])) {
            return $this->messagePage(
                $lang['std_permission_denied'],
                $lang['std_permission_denied'],
                $lang['head_edit_fun']
            );
        }

        if ($request->isMethod('POST')) {
            $body = (string) $request->input('body', '');
            if ($body === '') {
                return $this->messagePage($lang['std_error'], $lang['std_body_is_empty'], $lang['std_error']);
            }
            $title = htmlspecialchars(trim((string) $request->input('subject', '')));
            if ($title === '') {
                return $this->messagePage($lang['std_error'], $lang['std_title_is_empty'], $lang['std_error']);
            }
            $fun->update(['body' => $body, 'title' => $title]);
            $this->clearFunCaches($fun->id);

            return redirect(get_protocol_prefix() . Setting::getBaseUrl() . '/index.php');
        }

        return view('fun_form', [
            'lang' => $lang,
            'langFunctions' => $GLOBALS['lang_functions'],
            'fun' => $fun,
            'pageTitle' => $lang['head_edit_fun'],
            'title' => $lang['text_edit_fun'],
            'formAction' => 'edit',
            'subject' => $fun->title,
            'body' => $fun->body,
        ]);
    }

    // ----------------------------------------------------------------- delete

    private function webDelete(Request $request, array $curUser, array $lang)
    {
        if (($curUser['class'] ?? 0) != User::CLASS_ADMINISTRATOR && ! user_can('funmanage', false, $curUser['id'])) {
            abort(403);
        }
        $id = (int) $request->query('id', 0);
        if (! is_valid_id($id)) {
            return $this->messagePage($lang['std_error'], $lang['std_invalid_id'], $lang['std_error']);
        }
        $fun = Fun::query()->where('id', $id)->first();
        if (! $fun) {
            return $this->messagePage($lang['std_error'], $lang['std_invalid_id'], $lang['std_error']);
        }

        $returnto = (string) $request->query('returnto', '');
        if ($returnto === '') {
            $returnto = (string) $request->headers->get('referer', '');
        }
        $returnto = htmlspecialchars($returnto);

        if ((int) $request->query('sure', 0) !== 1) {
            $link = "fun.php?action=delete&id=$id&returnto=$returnto&sure=1";
            $text = $lang['text_please_click']
                . "<a class=altlink href=\"$link\">" . $lang['text_here_if_sure'];
            return $this->messagePage($lang['std_delete_fun'], $text, $lang['head_fun'], false);
        }

        Fun::query()->where('id', $id)->delete();
        $this->clearFunCaches($id);

        if ($returnto != '') {
            return redirect($returnto);
        }
        return redirect(get_protocol_prefix() . Setting::getBaseUrl() . '/index.php');
    }

    // -------------------------------------------------------------------- ban

    private function webBan(Request $request, array $curUser, array $lang)
    {
        if (($curUser['class'] ?? 0) != User::CLASS_ADMINISTRATOR && ! user_can('funmanage', false, $curUser['id'])) {
            abort(403);
        }
        $id = (int) $request->query('id', 0);
        if (! is_valid_id($id)) {
            return $this->messagePage($lang['std_error'], $lang['std_invalid_id'], $lang['std_error']);
        }
        $fun = Fun::query()->where('id', $id)->first();
        if (! $fun) {
            return $this->messagePage($lang['std_error'], $lang['std_invalid_id'], $lang['std_error']);
        }

        if ($request->isMethod('POST')) {
            $banreason = htmlspecialchars((string) $request->input('banreason', ''), ENT_QUOTES);
            if ($banreason === '') {
                return $this->messagePage($lang['std_error'], $lang['std_reason_is_empty'], $lang['std_error']);
            }
            $title = htmlspecialchars($fun->title);
            $fun->update(['status' => Fun::STATUS_BANNED]);
            $this->clearFunCaches($fun->id);

            $locale = get_user_locale((int) $fun->userid);
            $subject = nexus_trans('fun.msg_fun_item_banned', [], $locale);
            $msg = nexus_trans('fun.msg_your_fun_item', [], $locale) . $title
                . nexus_trans('fun.msg_is_ban_by', [], $locale) . $curUser['username']
                . nexus_trans('fun.msg_reason', [], $locale) . $banreason;
            Message::add([
                'sender' => 0,
                'receiver' => $fun->userid,
                'subject' => $subject,
                'added' => now(),
                'msg' => $msg,
            ]);

            write_log("Fun item $id ($title) was banned by {$curUser['username']}. Reason: $banreason", 'normal');

            return $this->messagePage($lang['std_success'], $lang['std_fun_item_banned'], $lang['std_success']);
        }

        $form = $lang['std_only_against_rule']
            . "<br /><form name=ban method=post action=\"fun.php?action=ban&id=$id\">"
            . '<input type=hidden name=sure value=1>'
            . $lang['std_reason_required'] . '<input type=text style="width: 200px" name=banreason>'
            . '<input type=submit value="' . $lang['submit_okay'] . '"></form>';
        return $this->messagePage($lang['std_are_you_sure'], $form, $lang['head_fun'], false);
    }

    // ------------------------------------------------------------------- vote

    private function webVote(Request $request, array $curUser, array $lang)
    {
        $id = (int) $request->query('id', 0);
        if (! is_valid_id($id)) {
            return $this->messagePage($lang['std_error'], $lang['std_invalid_id'], $lang['std_error']);
        }
        $fun = Fun::query()->where('id', $id)->first();
        if (! $fun) {
            return $this->messagePage($lang['std_error'], $lang['std_invalid_id'], $lang['std_error']);
        }

        if (FunVote::query()->where('funid', $id)->where('userid', $curUser['id'])->exists()) {
            return $this->messagePage($lang['std_error'], $lang['std_already_vote'], $lang['std_error']);
        }

        $vote = (string) $request->query('yourvote', '') == 'dull' ? FunVote::VOTE_DULL : FunVote::VOTE_FUN;
        FunVote::query()->create([
            'funid' => $id,
            'userid' => $curUser['id'],
            'added' => now(),
            'vote' => $vote,
        ]);

        $voteBonus = (float) get_setting('bonus.funboxvote', 0);
        $this->addBonus((int) $curUser['id'], $voteBonus);

        $totalVote = FunVote::query()->where('funid', $id)->count();
        $funVote = FunVote::query()->where('funid', $id)->where('vote', FunVote::VOTE_FUN)->count();
        Cache::put(IndexController::CACHE_FUN_VOTE_COUNT . $id, $totalVote, 756);
        Cache::put(IndexController::CACHE_FUN_VOTE_FUNNY_COUNT . $id, $funVote, 756);

        $ratio = $totalVote > 0 ? $funVote / $totalVote : 1;
        $rewardBonus = (float) get_setting('bonus.funboxreward', 0);
        if ($totalVote >= 20) {
            if ($ratio > 0.75) {
                $fun->update(['status' => Fun::STATUS_VERY_FUNNY]);
                if (in_array($totalVote, [25, 50, 100, 200], true)) {
                    $this->funReward($funVote, $totalVote, $fun->title, (int) $fun->userid, $rewardBonus * 2);
                }
            } elseif ($ratio > 0.5) {
                $fun->update(['status' => Fun::STATUS_FUNNY]);
                if (in_array($totalVote, [25, 50, 100, 200], true)) {
                    $this->funReward($funVote, $totalVote, $fun->title, (int) $fun->userid, $rewardBonus);
                }
            } elseif ($ratio > 0.25) {
                $fun->update(['status' => Fun::STATUS_NOT_FUNNY]);
            } else {
                $fun->update(['status' => Fun::STATUS_DULL]);
                $locale = get_user_locale((int) $fun->userid);
                $subject = nexus_trans('fun.msg_fun_item_dull', [], $locale);
                $msg = ($totalVote - $funVote) . nexus_trans('fun.msg_out_of', [], $locale) . $totalVote
                    . nexus_trans('fun.msg_people_think', [], $locale) . $fun->title
                    . nexus_trans('fun.msg_is_dull', [], $locale);
                Message::add([
                    'sender' => 0,
                    'receiver' => $fun->userid,
                    'subject' => $subject,
                    'added' => now(),
                    'msg' => $msg,
                ]);
            }
            Cache::forget(IndexController::CACHE_FUN_CONTENT);
        }

        return response('');
    }

    // ---------------------------------------------------------------- helpers

    private function needNew(Fun $fun): bool
    {
        return $fun->added->copy()->addDay()->isPast();
    }

    private function latestFun(): ?Fun
    {
        return Fun::query()
            ->whereNotIn('status', [Fun::STATUS_BANNED, Fun::STATUS_DULL])
            ->orderByDesc('added')
            ->first();
    }

    private function clearFunCaches(?int $funId): void
    {
        Cache::forget(IndexController::CACHE_FUN_CONTENT);
        Cache::forget('current_fun');
        Cache::forget('current_fun_vote_count');
        Cache::forget('current_fun_vote_funny_count');
        if ($funId) {
            Cache::forget(IndexController::CACHE_FUN_VOTE_COUNT . $funId);
            Cache::forget(IndexController::CACHE_FUN_VOTE_FUNNY_COUNT . $funId);
        }
    }

    private function addBonus(int $uid, float $points): void
    {
        if ($points <= 0) {
            return;
        }
        $tweak = (string) get_setting('tweak.bonus', 'enable');
        if (in_array($tweak, ['enable', 'disablesave'], true)) {
            User::query()->where('id', $uid)->increment('seedbonus', $points);
        }
    }

    /**
     * Reward a fun item poster once it gathers enough positive votes: karma
     * bonus + a congratulation PM (mirrors the legacy funreward() helper).
     */
    private function funReward(int $funVote, int $totalVote, string $title, int $posterId, float $bonus): void
    {
        $this->addBonus($posterId, $bonus);
        $locale = get_user_locale($posterId);
        $subject = nexus_trans('fun.msg_fun_item_reward', [], $locale);
        $msg = $funVote . nexus_trans('fun.msg_out_of', [], $locale) . $totalVote
            . nexus_trans('fun.msg_people_think', [], $locale) . $title
            . nexus_trans('fun.msg_is_fun', [], $locale) . $bonus
            . nexus_trans('fun.msg_bonus_as_reward', [], $locale);
        Message::add([
            'sender' => 0,
            'receiver' => $posterId,
            'subject' => $subject,
            'added' => now(),
            'msg' => $msg,
        ]);
        Cache::forget('user_' . $posterId . '_unread_message_count');
        Cache::forget('user_' . $posterId . '_inbox_count');
    }

    /**
     * Full-page message (legacy stderr() equivalent) rendered through Blade.
     */
    private function messagePage(string $heading, string $text, string $pageTitle = 'Fun', bool $htmlstrip = true)
    {
        $content = $this->capture(function () use ($heading, $text, $htmlstrip) {
            stderr($heading, $text, $htmlstrip, false, false, false);
        });

        return view('fun_page', compact('content') + ['pageTitle' => $pageTitle]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}