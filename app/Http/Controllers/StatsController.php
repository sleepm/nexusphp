<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Site statistics page — replaces legacy public/stats.php.
 *
 * GET /stats.php requires at least the moderator class. The page shows two
 * frames: "Uploader Activity" (users of the uploader classes grouped by user,
 * with last upload / torrents / peers and their percentages) and "Category
 * Activity" (torrents / peers per category). Sorting is driven by the
 * uporder / catorder query parameters (name, lastul, torrents, peers).
 */
class StatsController extends Controller
{
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
        if (get_user_class() < User::CLASS_MODERATOR) {
            abort(403, 'Permission denied.');
        }

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $nTor = (int) DB::table('torrents')->count();
        $nPeers = (int) DB::table('peers')->count();

        $uporder = trim((string) $request->query('uporder', ''));
        $catorder = trim((string) $request->query('catorder', ''));

        $content = $this->capture(function () use ($uporder, $catorder, $nTor, $nPeers) {
            print('<h1>Stats</h1>');

            $uploaderOrderBy = match ($uporder) {
                'lastul' => 'last DESC, name',
                'torrents' => 'n_t DESC, name',
                'peers' => 'n_p DESC, name',
                default => 'name',
            };

            $uploaders = $this->uploaderActivity($uploaderOrderBy);

            if ($uploaders->isEmpty()) {
                stdmsg('Sorry...', 'No uploaders.');
            } else {
                begin_frame('Uploader Activity', true);
                begin_table();
                print("<tr>\n"
                    . "<td class=colhead><a href=\"" . $_SERVER['PHP_SELF'] . "?uporder=uploader&catorder=$catorder\" class=colheadlink>Uploader</a></td>\n"
                    . "<td class=colhead><a href=\"" . $_SERVER['PHP_SELF'] . "?uporder=lastul&catorder=$catorder\" class=colheadlink>Last Upload</a></td>\n"
                    . "<td class=colhead><a href=\"" . $_SERVER['PHP_SELF'] . "?uporder=torrents&catorder=$catorder\" class=colheadlink>Torrents</a></td>\n"
                    . "<td class=colhead>Perc.</td>\n"
                    . "<td class=colhead><a href=\"" . $_SERVER['PHP_SELF'] . "?uporder=peers&catorder=$catorder\" class=colheadlink>Peers</a></td>\n"
                    . "<td class=colhead>Perc.</td>\n"
                    . "</tr>\n");
                foreach ($uploaders as $uper) {
                    print('<tr><td>' . get_username($uper->id) . "</td>\n");
                    print('<td ' . ($uper->last ? '>' . $uper->last . ' (' . get_elapsed_time(strtotime($uper->last)) . ' ago)' : 'align=center>---') . "</td>\n");
                    print("<td align=right>{$uper->n_t}</td>\n");
                    print('<td align=right>' . ($nTor > 0 ? number_format(100 * $uper->n_t / $nTor, 1) . '%' : '---') . "</td>\n");
                    print("<td align=right>{$uper->n_p}</td>\n");
                    print('<td align=right>' . ($nPeers > 0 ? number_format(100 * $uper->n_p / $nPeers, 1) . '%' : '---') . "</td></tr>\n");
                }
                end_table();
                end_frame();
            }

            if ($nTor == 0) {
                stdmsg('Sorry...', 'No categories defined!');
            } else {
                $categoryOrderBy = match ($catorder) {
                    'lastul' => 'last DESC, c.name',
                    'torrents' => 'n_t DESC, c.name',
                    'peers' => 'n_p DESC, name',
                    default => 'c.name',
                };

                $categories = DB::table('categories as c')
                    ->leftJoin('torrents as t', 't.category', '=', 'c.id')
                    ->leftJoin('peers as p', 't.id', '=', 'p.torrent')
                    ->select('c.name')
                    ->selectRaw('MAX(t.added) AS last')
                    ->selectRaw('COUNT(DISTINCT t.id) AS n_t')
                    ->selectRaw('COUNT(p.id) AS n_p')
                    ->groupBy('c.id')
                    ->orderByRaw($categoryOrderBy)
                    ->get();

                begin_frame('Category Activity', true);
                begin_table();
                print("<tr><td class=colhead><a href=\"" . $_SERVER['PHP_SELF'] . "?uporder=$uporder&catorder=category\" class=colheadlink>Category</a></td>\n"
                    . "<td class=colhead><a href=\"" . $_SERVER['PHP_SELF'] . "?uporder=$uporder&catorder=lastul\" class=colheadlink>Last Upload</a></td>\n"
                    . "<td class=colhead><a href=\"" . $_SERVER['PHP_SELF'] . "?uporder=$uporder&catorder=torrents\" class=colheadlink>Torrents</a></td>\n"
                    . "<td class=colhead>Perc.</td>\n"
                    . "<td class=colhead><a href=\"" . $_SERVER['PHP_SELF'] . "?uporder=$uporder&catorder=peers\" class=colheadlink>Peers</a></td>\n"
                    . "<td class=colhead>Perc.</td></tr>\n");
                foreach ($categories as $cat) {
                    print('<tr><td class=rowhead>' . $cat->name . "</b></a></td>");
                    print('<td ' . ($cat->last ? '>' . $cat->last . ' (' . get_elapsed_time(strtotime($cat->last)) . ' ago)' : 'align = center>---') . '</td>');
                    print("<td align=right>{$cat->n_t}</td>");
                    print('<td align=right>' . number_format(100 * $cat->n_t / $nTor, 1) . '%</td>');
                    print("<td align=right>{$cat->n_p}</td>");
                    print('<td align=right>' . ($nPeers > 0 ? number_format(100 * $cat->n_p / $nPeers, 1) . '%' : '---') . "</td>\n");
                }
                end_table();
                end_frame();
            }
        });

        return view('stats', compact('content') + [
            'pageTitle' => 'Stats',
        ]);
    }

    /**
     * Uploader activity rows: users whose class is exactly the elite-user
     * uploader class (3) plus every class above it, grouped per user with
     * last upload time, distinct torrent count and peer count.
     */
    private function uploaderActivity(string $orderBy): \Illuminate\Support\Collection
    {
        $uploaderQuery = function () {
            return DB::table('users as u')
                ->leftJoin('torrents as t', 'u.id', '=', 't.owner')
                ->leftJoin('peers as p', 't.id', '=', 'p.torrent')
                ->select('u.id', 'u.username AS name')
                ->selectRaw('MAX(t.added) AS last')
                ->selectRaw('COUNT(DISTINCT t.id) AS n_t')
                ->selectRaw('COUNT(p.id) AS n_p')
                ->groupBy('u.id');
        };

        $elite = $uploaderQuery()->where('u.class', User::CLASS_ELITE_USER);
        $above = $uploaderQuery()->where('u.class', '>', User::CLASS_ELITE_USER);

        return DB::query()->fromSub($elite->union($above), 'up')
            ->orderByRaw($orderBy)
            ->get();
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}
