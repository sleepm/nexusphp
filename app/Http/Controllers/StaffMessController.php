<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StaffMessController extends Controller
{
    /**
     * Mass-PM form. Mirrors legacy public/staffmess.php: administrators (class
     * > administrator) pick a set of user classes (plus optional plugin role
     * filter) and send a PM to every enabled/confirmed user in those classes.
     *
     * GET /staffmess.php?sent=1 renders the compose form for takestaffmess.php.
     */
    public function web(Request $request)
    {
        $curUser = $this->authenticate($request);
        if (get_user_class() < User::CLASS_ADMINISTRATOR) {
            abort(403, 'Access denied.');
        }

        $classes = array_chunk(User::$classes, 4, true);
        $content = $this->capture(function () use ($classes, $request, $curUser) {
            print('<table class="main" width=737 border=0 cellspacing=0 cellpadding=0><tr><td class=embedded>');
            print('<div align=center>');
            print('<h1>Mass PM to all Staff members and users:</a></h1>');
            print('<form method=post action=takestaffmess.php>');

            $returnto = $request->query('returnto');
            if (! $returnto) {
                $returnto = $request->headers->get('referer');
            }
            if ($returnto) {
                print('<input type=hidden name=returnto value="' . htmlspecialchars((string) $returnto) . '">');
            }

            print('<table cellspacing=0 cellpadding=5>');
            if ($request->query('sent') == 1) {
                print('<tr><td colspan=2><font color=red><b>The message has ben sent.</b></font></td></tr>');
            }
            print('<tr><td><b>Send to class:</b></td><td>');
            print('<table style="border: 0" width="100%" cellpadding="0" cellspacing="0">');
            foreach ($classes as $chunk) {
                print('<tr>');
                foreach ($chunk as $class => $info) {
                    printf('<td style="border: 0"><label><input type="checkbox" name="classes[]" value="%s" />%s</label></td>', $class, $info['text']);
                }
                print('</tr>');
            }
            print('</table>');
            print('</td></tr>');
            do_action('form_role_filter', 'Send to Role:');
            print('<tr><td class="rowhead">Subject</td><td> <input type=text name=subject size=75></td></tr>');
            print('<tr><td class="rowhead">Message</td><td><textarea name=msg cols=80 rows=15></textarea></td></tr>');
            print('<tr><td colspan=2><div align="center"><b>Sender:&nbsp;&nbsp;</b>');
            print($curUser['username']);
            print('<input name="sender" type="radio" value="self" checked>');
            print('&nbsp; System');
            print('<input name="sender" type="radio" value="system">');
            print('</div></td></tr>');
            print('<tr><td colspan=2 align=center><input type=submit value="Send!" class=btn></td></tr>');
            print('</table>');
            print('<input type=hidden name=receiver value="' . htmlspecialchars((string) $request->query('receiver', '')) . '">');
            print('</form>');
            print('</div></td></tr></table>');
            print('<br />');
            print('NOTE: Do not user BB codes. (NO HTML)');
        });

        return view('staffmess', compact('content') + [
            'pageTitle' => 'Mass PM',
        ]);
    }

    /**
     * Mass-PM submission. Mirrors legacy public/takestaffmess.php: builds a
     * SQL condition from the checked classes + plugin role filter, then bulk
     * inserts a message per enabled/confirmed user, redirecting to staffmess.
     *
     * POST /takestaffmess.php
     */
    public function webTake(Request $request)
    {
        $curUser = $this->authenticate($request);
        if (! $request->isMethod('POST')) {
            abort(403, 'Permission denied!');
        }
        if (get_user_class() < User::CLASS_ADMINISTRATOR) {
            abort(403, 'Permission denied.');
        }

        $senderId = ($request->input('sender') == 'system' ? 0 : (int) $curUser['id']);
        $msg = trim((string) $request->input('msg', ''));
        if (! $msg) {
            abort(400, "Don't leave any fields blank.");
        }

        $updateset = $request->input('clases');
        if (is_array($updateset)) {
            foreach ($updateset as &$class) {
                $class = intval($class);
                if (! is_valid_id($class) && $class != 0) {
                    abort(400, 'Invalid Class');
                }
            }
        } else {
            if (! is_valid_id($updateset) && $updateset != 0) {
                abort(400, 'Invalid Class');
            }
        }
        $subject = trim((string) $request->input('subject', ''));

        $conditions = [];
        if (! empty($request->input('classes'))) {
            $classes = array_map('intval', (array) $request->input('classes'));
            $conditions[] = 'class IN (' . implode(', ', $classes) . ')';
        }
        $conditions = apply_filter('role_query_conditions', $conditions, $request->all());
        if (empty($conditions)) {
            abort(400, 'No valid filter');
        }
        $whereStr = implode(' OR ', $conditions);

        set_time_limit(300);

        $added = date('Y-m-d H:i:s');
        $batch = [];
        User::query()
            ->whereRaw("($whereStr)")
            ->where('enabled', 'yes')
            ->where('status', 'confirmed')
            ->select('id')
            ->chunkById(1000, function ($users) use (&$batch, $added, $senderId, $subject, $msg) {
                foreach ($users as $user) {
                    $batch[] = [
                        'sender' => $senderId,
                        'receiver' => $user->id,
                        'added' => $added,
                        'subject' => $subject,
                        'msg' => $msg,
                    ];
                }
                if (count($batch) >= 10000) {
                    Message::query()->insert($batch);
                    DB::table('users')->whereIn('id', array_column($batch, 'receiver'))->update(['last_pm' => $added]);
                    $batch = [];
                }
            });
        if (! empty($batch)) {
            Message::query()->insert($batch);
            DB::table('users')->whereIn('id', array_column($batch, 'receiver'))->update(['last_pm' => $added]);
        }

        return redirect('staffmess.php?sent=1');
    }

    private function authenticate(Request $request): array
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

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        return $curUser;
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}