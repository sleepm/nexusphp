<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DonorListController extends Controller
{
    /**
     * Donor list. Mirrors legacy public/donorlist.php: administrators
     * (class > moderator) see every donor with e-mail, join date and the
     * amount donated, paginated 50 per page.
     *
     * GET /donorlist.php renders the paginated donor table.
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
        if (get_user_class() < User::CLASS_ADMINISTRATOR) {
            abort(403, 'Access denied.');
        }

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $pageTitle = 'Donorlist';

        $content = $this->capture(function () use ($request) {
            $count = User::query()->where('donor', 'yes')->count();
            list($pagertop, $pagerbottom, $limit) = pager(50, $count, 'donorlist.php?');
            preg_match('/limit (\d+) offset (\d+)/', $limit, $limitMatches);

            print('<h1>Donorlist</h1>');

            if ($count == 0) {
                echo $pagertop;
                print('<p>No donors yet.</p>');
                echo $pagerbottom;
                return;
            }

            $rows = User::query()
                ->where('donor', 'yes')
                ->orderByDesc('id')
                ->limit((int) ($limitMatches[1] ?? 50))
                ->offset((int) ($limitMatches[2] ?? 0))
                ->get(['id', 'username', 'email', 'added', 'donated']);

            $users = number_format($count);
            print('<table class="main" width="100%" border="1" cellspacing="0" cellpadding="5">');
            print('<tr><td class="colhead">ID</td><td class="colhead" align="left">Username</td>'
                . '<td class="colhead" align="left">e-mail</td><td class="colhead" align="left">Joined</td>'
                . '<td class="colhead" align="left">How much?</td></tr>');
            foreach ($rows as $arr) {
                print('<tr><td>' . $arr['id'] . '</td><td align="left">' . get_username($arr['id']) . '</td>'
                    . '<td align="left"><a href="mailto:' . htmlspecialchars($arr['email'], ENT_QUOTES) . '">' . htmlspecialchars($arr['email']) . '</a></td>'
                    . '<td align="left">' . $arr['added'] . '</td>'
                    . '<td align="left">$' . $arr['donated'] . '</td></tr>');
            }
            print('</table>');
            print('<p align="center" class="nexus-pagination">Donor List (' . $users . ')</p>');
            echo $pagertop;
            echo $pagerbottom;
        });

        return view('donorlist', compact('content') + [
            'pageTitle' => $pageTitle,
        ]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}