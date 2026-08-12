<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Nexus\Database\NexusDB;

/**
 * Ranking page, replaces legacy public/topten.php (Phase 2 P2).
 *
 * Mirrors the legacy type/subtype switch; the heavy queries are cached for 60
 * minutes (Laravel Cache), same as the legacy $Cache->new_page() output cache.
 */
class ToptenController extends Controller
{
    public function index(Request $request)
    {
        $currentUser = Auth::user();
        if ($currentUser && $currentUser->parked == 'yes') {
            abort(403, 'Your account is parked.');
        }
        if (!user_can('topten')) {
            $langTopten = get_legacy_lang_file('topten');
            $message = ($langTopten['std_permission_denied_only'] ?? '')
                . get_user_class_name(get_setting('authority.topten', \App\Models\User::CLASS_POWER_USER), false, true, true)
                . sprintf($langTopten['std_or_above_can_view'] ?? '', \App\Models\Setting::getSiteName());
            abort(403, $message);
        }

        $lang = get_legacy_lang_file('topten');

        $type = (int) $request->get('type', 0);
        if (!in_array($type, [1, 2, 3, 5, 6], true)) {
            $type = 1;
        }
        $limit = (int) $request->get('lim', 0);
        if ($limit <= 0 || $limit > 250) {
            $limit = 10;
        }
        $subtype = (string) $request->get('subtype', '');

        $cacheKey = sprintf('topten_type_%d_limit_%d_subtype_%s', $type, $limit, $subtype);
        $tables = Cache::get($cacheKey);
        if ($tables === null) {
            $tables = $this->buildTables($type, $limit, $subtype, $lang);
            Cache::put($cacheKey, $tables, now()->addMinutes(60));
        }

        return view('topten', [
            'request' => $request,
            'lang' => $lang,
            'langFunctions' => get_legacy_lang_file('functions'),
            'type' => $type,
            'pageTitle' => $lang['head_top_ten'],
            'tables' => $tables,
            'lastUpdated' => date('Y-m-d H:i:s'),
            'recordStart' => get_setting('tweak.datefounded', ''),
        ]);
    }

    private function buildTables(int $type, int $limit, string $subtype, array $lang): array
    {
        switch ($type) {
            case 1:
                return $this->userTables($limit, $subtype, $lang);
            case 2:
                return $this->torrentTables($limit, $subtype, $lang);
            case 3:
                return $this->countryTables($limit, $subtype, $lang);
            case 5:
                return $this->communityTables($limit, $subtype, $lang);
            case 6:
                return $this->otherTables($limit, $subtype, $lang);
            default:
                return [];
        }
    }

    // --------------------------------------------------------- shared helpers

    private function caption(array $lang, string $text, int $type, string $subtype, int $limit, array $linkLimits): string
    {
        $caption = $lang['text_top'] . $limit . $text;
        if ($limit == 10 && $linkLimits) {
            $links = [];
            foreach ($linkLimits as $linkLimit) {
                $links[] = '<a class="altlink" href="topten.php?type=' . $type . '&amp;lim=' . $linkLimit . '&amp;subtype=' . $subtype . '">Top ' . $linkLimit . '</a>';
            }
            $caption .= ' <font class="small"> - [' . implode('] - [', $links) . ']</font>';
        }

        return $caption;
    }

    private function colDivision(string $numerator, string $denominator): string
    {
        return NexusDB::isPgsql() ? "(($numerator))::numeric / (($denominator))" : "$numerator / $denominator";
    }

    // --------------------------------------------------------- type 1: users

    private function userTables(int $limit, string $subtype, array $lang): array
    {
        $speedStr = NexusDB::isPgsql()
            ? "uploaded::numeric / (EXTRACT(EPOCH FROM NOW()) - EXTRACT(EPOCH FROM added)) AS upspeed, downloaded::numeric / (EXTRACT(EPOCH FROM NOW()) - EXTRACT(EPOCH FROM added)) AS downspeed"
            : "uploaded / (UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(added)) AS upspeed, downloaded / (UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(added)) AS downspeed";
        $ratioDiv = $this->colDivision('uploaded', 'downloaded');

        $subtypes = [
            'ul' => ['title' => $lang['text_uploaders'], 'order' => 'uploaded DESC', 'where' => null],
            'dl' => ['title' => $lang['text_downloaders'], 'order' => 'downloaded DESC', 'where' => null],
            'uls' => ['title' => $lang['text_fastest_uploaders'] . '<font class="small">' . $lang['text_fastest_up_note'] . '</font>', 'order' => 'upspeed DESC', 'where' => 'uploaded > 53687091200'],
            'dls' => ['title' => $lang['text_fastest_downloaders'] . '<font class="small">' . $lang['text_fastest_note'] . '</font>', 'order' => 'downspeed DESC', 'where' => null],
            'bsh' => ['title' => $lang['text_best_sharers'] . '<font class="small">' . $lang['text_sharers_note'] . '</font>', 'order' => "$ratioDiv DESC", 'where' => 'downloaded > 53687091200'],
            'wsh' => ['title' => $lang['text_worst_sharers'] . $lang['text_sharers_note'], 'order' => "$ratioDiv ASC, downloaded DESC", 'where' => 'downloaded > 53687091200'],
        ];

        $tables = [];
        foreach ($subtypes as $key => $conf) {
            if ($limit != 10 && $subtype !== $key) {
                continue;
            }
            $query = DB::table('users')
                ->selectRaw("id, username, added, uploaded, downloaded, $speedStr")
                ->where('enabled', 'yes');
            if ($conf['where']) {
                $query->whereRaw($conf['where']);
            }
            $rows = $query->orderByRaw($conf['order'])->limit($limit)->get();

            $tables[] = [
                'caption' => $this->caption($lang, $conf['title'], 1, $key, $limit, [100, 250]),
                'headers' => [
                    ['label' => $lang['col_rank'], 'align' => 'center'],
                    ['label' => $lang['col_user'], 'align' => 'left'],
                    ['label' => $lang['col_uploaded'], 'align' => 'right'],
                    ['label' => $lang['col_ul_speed'], 'align' => 'right'],
                    ['label' => $lang['col_downloaded'], 'align' => 'right'],
                    ['label' => $lang['col_dl_speed'], 'align' => 'right'],
                    ['label' => $lang['col_ratio'], 'align' => 'right'],
                    ['label' => $lang['col_joined'], 'align' => 'left'],
                ],
                'rows' => $this->userShareRows($rows, $lang),
            ];
        }

        return $tables;
    }

    private function userShareRows($rows, array $lang): array
    {
        $result = [];
        $num = 0;
        foreach ($rows as $a) {
            ++$num;
            if ($a->downloaded) {
                $ratioValue = $a->uploaded / $a->downloaded;
                $color = get_ratio_color($ratioValue);
                $ratio = number_format($ratioValue, 2);
                if ($color) {
                    $ratio = "<font color=\"{$color}\">{$ratio}</font>";
                }
            } else {
                $ratio = $lang['text_inf'];
            }
            $result[] = [
                ['html' => $num, 'align' => 'center'],
                ['html' => get_username($a->id), 'align' => 'left'],
                ['html' => mksize($a->uploaded), 'align' => 'right'],
                ['html' => mksize($a->upspeed) . '/s', 'align' => 'right'],
                ['html' => mksize($a->downloaded), 'align' => 'right'],
                ['html' => mksize($a->downspeed) . '/s', 'align' => 'right'],
                ['html' => $ratio, 'align' => 'right'],
                ['html' => gettime($a->added, true, false), 'align' => 'left'],
            ];
        }

        return $result;
    }

    // --------------------------------------------------------- type 2: torrents

    private function torrentTables(int $limit, string $subtype, array $lang): array
    {
        $seederDiv = $this->colDivision('seeders', 'leechers');
        $dataExpr = 't.size * t.times_completed + COALESCE((SELECT SUM(p.downloaded) FROM peers AS p WHERE p.torrent = t.id AND p.seeder = \'no\'), 0)';

        $subtypes = [
            'act' => ['title' => $lang['text_most_active_torrents'], 'order' => 'seeders + leechers DESC, seeders DESC, added ASC', 'where' => null],
            'sna' => ['title' => $lang['text_most_snatched_torrents'], 'order' => 'times_completed DESC', 'where' => null],
            'mdt' => ['title' => $lang['text_most_data_transferred_torrents'], 'order' => 'data DESC, added ASC', 'where' => 'times_completed > 0'],
            'bse' => ['title' => $lang['text_best_seeded_torrents'] . '<font class="small">' . $lang['text_best_seeded_torrents_note'] . '</font>', 'order' => "$seederDiv DESC, seeders DESC, added ASC", 'where' => 'seeders >= 5'],
            'wse' => ['title' => $lang['text_worst_seeded_torrents'] . '<font class="small">' . $lang['text_worst_seeded_torrents_note'] . '</font>', 'order' => "$seederDiv ASC, leechers DESC", 'where' => 'leechers > 0 AND times_completed > 0'],
        ];

        $tables = [];
        foreach ($subtypes as $key => $conf) {
            if ($limit != 10 && $subtype !== $key) {
                continue;
            }
            $query = DB::table('torrents as t')
                ->selectRaw("t.*, ($dataExpr) AS data")
                ->whereExists(function ($q) {
                    $q->selectRaw('1')->from('peers as p')
                        ->whereColumn('p.torrent', 't.id')
                        ->where('p.seeder', 'no');
                });
            if ($conf['where']) {
                $query->whereRaw($conf['where']);
            }
            $rows = $query->orderByRaw($conf['order'])->limit($limit)->get();

            $tables[] = [
                'caption' => $this->caption($lang, $conf['title'], 2, $key, $limit, [25, 50]),
                'headers' => [
                    ['label' => $lang['col_rank'], 'align' => 'center'],
                    ['label' => $lang['col_name'], 'align' => 'left'],
                    ['label' => '<img class="snatched" src="pic/trans.gif" alt="snatched" title="' . $lang['title_sna'] . '" />', 'align' => 'right'],
                    ['label' => $lang['col_data'], 'align' => 'right'],
                    ['label' => '<img class="seeders" src="pic/trans.gif" alt="seeders" title="' . $lang['title_se'] . '" />', 'align' => 'right'],
                    ['label' => '<img class="leechers" src="pic/trans.gif" alt="leechers" title="' . ($lang['title_le'] ?? 'Number of Leechers') . '" />', 'align' => 'right'],
                    ['label' => $lang['col_to'], 'align' => 'right'],
                    ['label' => $lang['col_ratio'], 'align' => 'right'],
                ],
                'rows' => $this->torrentRows($rows, $lang),
            ];
        }

        return $tables;
    }

    private function torrentRows($rows, array $lang): array
    {
        $result = [];
        $num = 0;
        foreach ($rows as $a) {
            ++$num;
            if ($a->leechers) {
                $ratioValue = $a->seeders / $a->leechers;
                $ratio = '<font color="' . get_ratio_color($ratioValue) . '">' . number_format($ratioValue, 2) . '</font>';
            } else {
                $ratio = $lang['text_inf'];
            }
            $result[] = [
                ['html' => $num, 'align' => 'center'],
                ['html' => '<a href="details.php?id=' . $a->id . '&amp;hit=1"><b>' . $a->name . '</b></a>', 'align' => 'left'],
                ['html' => number_format($a->times_completed), 'align' => 'right'],
                ['html' => mksize($a->data), 'align' => 'right'],
                ['html' => number_format($a->seeders), 'align' => 'right'],
                ['html' => number_format($a->leechers), 'align' => 'right'],
                ['html' => number_format($a->leechers + $a->seeders), 'align' => 'right'],
                ['html' => $ratio, 'align' => 'right'],
            ];
        }

        return $result;
    }

    // --------------------------------------------------------- type 3: countries

    private function countryTables(int $limit, string $subtype, array $lang): array
    {
        $sumUlCount = $this->colDivision('sum(u.uploaded)', 'count(u.id)');
        $sumUlSumDl = $this->colDivision('sum(u.uploaded)', 'sum(u.downloaded)');

        $tables = [];
        $builders = [
            'us' => function () use ($lang) {
                return DB::table('countries as c')
                    ->selectRaw('c.name, c.flagpic, COUNT(users.country) AS num')
                    ->leftJoin('users', 'users.country', '=', 'c.id')
                    ->groupByRaw('c.name, c.flagpic');
            },
            'ul' => function () use ($lang) {
                return DB::table('users as u')
                    ->selectRaw('c.name, c.flagpic, SUM(u.uploaded) AS ul')
                    ->leftJoin('countries as c', 'u.country', '=', 'c.id')
                    ->where('u.enabled', 'yes')
                    ->groupByRaw('c.name');
            },
            'avg' => function () use ($lang, $sumUlCount) {
                return DB::table('users as u')
                    ->selectRaw("c.name, c.flagpic, $sumUlCount AS ul_avg")
                    ->leftJoin('countries as c', 'u.country', '=', 'c.id')
                    ->where('u.enabled', 'yes')
                    ->groupByRaw('c.name')
                    ->havingRaw('SUM(u.uploaded) > 1099511627776 AND COUNT(u.id) >= 100');
            },
            'r' => function () use ($lang, $sumUlSumDl) {
                return DB::table('users as u')
                    ->selectRaw("c.name, c.flagpic, $sumUlSumDl AS r")
                    ->leftJoin('countries as c', 'u.country', '=', 'c.id')
                    ->where('u.enabled', 'yes')
                    ->groupByRaw('c.name')
                    ->havingRaw('SUM(u.uploaded) > 1099511627776 AND SUM(u.downloaded) > 1099511627776 AND COUNT(u.id) >= 100');
            },
        ];

        $subtypes = [
            'us' => ['title' => $lang['text_countries_users'], 'valueColumn' => $lang['col_users'], 'order' => 'num DESC'],
            'ul' => ['title' => $lang['text_countries_uploaded'], 'valueColumn' => $lang['col_uploaded'], 'order' => 'ul DESC'],
            'avg' => ['title' => $lang['text_countries_per_user'], 'valueColumn' => $lang['col_average'], 'order' => 'ul_avg DESC'],
            'r' => ['title' => $lang['text_countries_ratio'], 'valueColumn' => $lang['col_ratio'], 'order' => 'r DESC'],
        ];

        foreach ($subtypes as $key => $conf) {
            if ($limit != 10 && $subtype !== $key) {
                continue;
            }
            $rows = $builders[$key]()->orderByRaw($conf['order'])->limit($limit)->get();

            $tables[] = [
                'caption' => $this->caption($lang, $conf['title'], 3, $key, $limit, [25]),
                'headers' => [
                    ['label' => $lang['col_rank'], 'align' => 'center'],
                    ['label' => $lang['col_country'], 'align' => 'left'],
                    ['label' => $conf['valueColumn'], 'align' => 'right'],
                ],
                'rows' => $this->countryRows($rows, $conf['valueColumn'], $lang),
            ];
        }

        return $tables;
    }

    private function countryRows($rows, string $valueColumn, array $lang): array
    {
        $result = [];
        $num = 0;
        foreach ($rows as $a) {
            ++$num;
            if ($valueColumn == $lang['col_users']) {
                $value = number_format($a->num);
            } elseif ($valueColumn == $lang['col_uploaded']) {
                $value = mksize($a->ul);
            } elseif ($valueColumn == $lang['col_average']) {
                $value = mksize($a->ul_avg);
            } else {
                $value = number_format($a->r, 2);
            }
            $country = '<table border="0" class="main" cellspacing="0" cellpadding="0"><tr>'
                . '<td class="embedded"><img align="center" src="pic/flag/' . $a->flagpic . '" alt="" /></td>'
                . '<td class="embedded" style="padding-left: 5px"><b>' . $a->name . '</b></td>'
                . '</tr></table>';
            $result[] = [
                ['html' => $num, 'align' => 'center'],
                ['html' => $country, 'align' => 'left'],
                ['html' => $value, 'align' => 'right'],
            ];
        }

        return $result;
    }

    // --------------------------------------------------------- type 5: community

    private function communityTables(int $limit, string $subtype, array $lang): array
    {
        $subtypes = [
            'mtop' => ['title' => $lang['text_most_topic']],
            'mpos' => ['title' => $lang['text_most_post']],
            'mcmt' => ['title' => $lang['text_most_commenter']],
            'btop' => ['title' => $lang['text_biggest_topics']],
        ];

        $tables = [];
        foreach ($subtypes as $key => $conf) {
            if ($limit != 10 && $subtype !== $key) {
                continue;
            }
            if ($key == 'btop') {
                $rows = DB::table('topics as t')
                    ->selectRaw("t.id AS topicid, t.subject AS topicsubject, (SELECT COUNT(*) FROM posts AS p WHERE p.topicid = t.id) AS postnum, forums.id AS forumid")
                    ->leftJoin('forums', function ($join) {
                        $join->on('forums.id', '=', 't.forumid')
                            ->where('forums.minclassread', '<=', 1);
                    })
                    ->orderByRaw('postnum DESC')
                    ->limit($limit)
                    ->get();
                $headers = [
                    ['label' => $lang['col_rank'], 'align' => 'center'],
                    ['label' => $lang['col_subject'], 'align' => 'left'],
                    ['label' => $lang['col_posts'], 'align' => 'right'],
                ];
            } elseif ($key == 'mcmt') {
                $commentExpr = '(SELECT COUNT(*) FROM comments AS c WHERE c.user = um.id) AS num';
                $rows = DB::table('users as um')
                    ->selectRaw("um.id AS userid, um.username, $commentExpr")
                    ->orderByRaw('num DESC')
                    ->limit($limit)
                    ->get();
                $headers = [
                    ['label' => $lang['col_rank'], 'align' => 'center'],
                    ['label' => $lang['col_username'], 'align' => 'left'],
                    ['label' => $lang['col_comments'], 'align' => 'right'],
                ];
            } else {
                $topicsExpr = '(SELECT COUNT(*) FROM topics AS t WHERE t.userid = um.id) AS usertopics';
                $postsExpr = '(SELECT COUNT(*) FROM posts AS p WHERE p.userid = um.id) AS userposts';
                $rows = DB::table('users as um')
                    ->selectRaw("um.id AS userid, um.username, $topicsExpr, $postsExpr")
                    ->orderByRaw($key == 'mtop' ? 'usertopics DESC' : 'userposts DESC')
                    ->limit($limit)
                    ->get();
                $headers = [
                    ['label' => $lang['col_rank'], 'align' => 'center'],
                    ['label' => $lang['col_username'], 'align' => 'left'],
                    ['label' => $lang['col_topics'], 'align' => 'right'],
                    ['label' => $lang['col_posts'], 'align' => 'right'],
                ];
            }

            $rowBuilder = $key == 'btop' ? 'communityTopicRows' : ($key == 'mcmt' ? 'communityCountRows' : 'communityPostRows');
            $tables[] = [
                'caption' => $this->caption($lang, $conf['title'], 5, $key, $limit, [100, 250]),
                'headers' => $headers,
                'rows' => $this->{$rowBuilder}($rows),
            ];
        }

        return $tables;
    }

    private function communityPostRows($rows): array
    {
        $result = [];
        $num = 1;
        foreach ($rows as $a) {
            $result[] = [
                ['html' => $num++, 'align' => 'center'],
                ['html' => get_username($a->userid), 'align' => 'left'],
                ['html' => number_format($a->usertopics), 'align' => 'right'],
                ['html' => number_format($a->userposts), 'align' => 'right'],
            ];
        }

        return $result;
    }

    private function communityCountRows($rows): array
    {
        $result = [];
        $num = 1;
        foreach ($rows as $a) {
            $result[] = [
                ['html' => $num++, 'align' => 'center'],
                ['html' => get_username($a->userid), 'align' => 'left'],
                ['html' => number_format($a->num), 'align' => 'right'],
            ];
        }

        return $result;
    }

    private function communityTopicRows($rows): array
    {
        $result = [];
        $num = 1;
        foreach ($rows as $a) {
            $topic = '<a href="forums.php?action=viewtopic&amp;forumid=' . $a->forumid . '&amp;topicid=' . $a->topicid . '">' . $a->topicsubject . '</a>';
            $result[] = [
                ['html' => $num++, 'align' => 'center'],
                ['html' => $topic, 'align' => 'left'],
                ['html' => number_format($a->postnum), 'align' => 'right'],
            ];
        }

        return $result;
    }

    // --------------------------------------------------------- type 6: other

    private function otherTables(int $limit, string $subtype, array $lang): array
    {
        $prolinkEnabled = (bool) get_setting('bonus.prolinkpoint', false);
        $donationEnabled = get_setting('main.donation', 'no') == 'yes';

        $subtypes = [];
        foreach (['bo' => ['title' => $lang['text_most_bonuses']], 'charity' => ['title' => $lang['text_charity_giver']]] as $key => $conf) {
            $subtypes[$key] = $conf;
        }
        if ($prolinkEnabled) {
            $subtypes['pl'] = ['title' => $lang['text_most_clicks'], 'column' => $lang['col_clicks']];
        }
        if ($donationEnabled) {
            $subtypes['do_usd'] = ['title' => $lang['text_most_donated_USD']];
            $subtypes['do_cny'] = ['title' => $lang['text_most_donated_CNY']];
        }
        $subtypes['mcli'] = ['title' => $lang['text_most_client'], 'column' => $lang['col_number']];
        $subtypes['ss'] = ['title' => $lang['text_most_stylesheet'], 'column' => $lang['col_number']];
        $subtypes['lang'] = ['title' => $lang['text_most_language'], 'column' => $lang['col_number']];

        $tables = [];
        foreach ($subtypes as $key => $conf) {
            if ($limit != 10 && $subtype !== $key) {
                continue;
            }
            [$rows, $headers, $builder] = $this->otherRows($key, $limit, $lang);
            $tables[] = [
                'caption' => $this->caption($lang, $conf['title'], 6, $key, $limit, [100, 250]),
                'headers' => $headers,
                'rows' => $builder($rows),
            ];
        }

        return $tables;
    }

    private function otherRows(string $key, int $limit, array $lang): array
    {
        $rank = ['label' => $lang['col_rank'], 'align' => 'center'];

        if ($key == 'bo') {
            $rows = DB::table('users')->selectRaw('id, username, seedbonus')->orderByRaw('seedbonus DESC')->limit($limit)->get();

            return [$rows, [$rank, ['label' => $lang['col_username'], 'align' => 'left'], ['label' => $lang['col_bonus'], 'align' => 'right']], function ($rows) {
                $result = [];
                $n = 1;
                foreach ($rows as $a) {
                    $result[] = [
                        ['html' => $n++, 'align' => 'center'],
                        ['html' => get_username($a->id), 'align' => 'left'],
                        ['html' => number_format($a->seedbonus, 1), 'align' => 'right'],
                    ];
                }

                return $result;
            }];
        }
        if ($key == 'charity') {
            $rows = DB::table('users')->selectRaw('id, username, charity')->orderByRaw('charity DESC')->limit($limit)->get();

            return [$rows, [$rank, ['label' => $lang['col_username'], 'align' => 'left'], ['label' => $lang['col_bonus'], 'align' => 'right']], function ($rows) {
                $result = [];
                $n = 1;
                foreach ($rows as $a) {
                    $result[] = [
                        ['html' => $n++, 'align' => 'center'],
                        ['html' => get_username($a->id), 'align' => 'left'],
                        ['html' => number_format($a->charity), 'align' => 'right'],
                    ];
                }

                return $result;
            }];
        }
        if ($key == 'pl') {
            $rows = DB::table('prolinkclicks')->selectRaw('userid, COUNT(id) AS clicks')->groupBy('userid')->orderByRaw('clicks DESC')->limit($limit)->get();

            return [$rows, [$rank, ['label' => $lang['col_username'], 'align' => 'left'], ['label' => $lang['col_clicks'], 'align' => 'right']], function ($rows) {
                $result = [];
                $n = 1;
                foreach ($rows as $a) {
                    $result[] = [
                        ['html' => $n++, 'align' => 'center'],
                        ['html' => get_username($a->userid), 'align' => 'left'],
                        ['html' => number_format($a->clicks), 'align' => 'right'],
                    ];
                }

                return $result;
            }];
        }
        if ($key == 'do_usd' || $key == 'do_cny') {
            $rows = DB::table('users')
                ->selectRaw('id, username, donated, donated_cny')
                ->where($key == 'do_usd' ? 'donated' : 'donated_cny', '>', 0)
                ->orderByRaw('donated DESC, donated_cny DESC')
                ->limit($limit)
                ->get();

            return [$rows, [$rank, ['label' => $lang['col_username'], 'align' => 'left'], ['label' => $lang['col_donated_usd'], 'align' => 'right'], ['label' => $lang['col_donated_cny'], 'align' => 'right']], function ($rows) {
                $result = [];
                $n = 1;
                foreach ($rows as $a) {
                    $result[] = [
                        ['html' => $n++, 'align' => 'center'],
                        ['html' => get_username($a->id), 'align' => 'left'],
                        ['html' => number_format($a->donated, 2), 'align' => 'right'],
                        ['html' => number_format($a->donated_cny, 2), 'align' => 'right'],
                    ];
                }

                return $result;
            }];
        }
        if ($key == 'mcli') {
            $rows = DB::table('agent_allowed_family as a')
                ->selectRaw('a.family AS client_name, COUNT(users.id) AS client_num')
                ->leftJoin('users', 'a.id', '=', 'users.clientselect')
                ->groupBy('users.clientselect', 'client_name')
                ->orderByRaw('client_num DESC')
                ->limit($limit)
                ->get();

            return [$rows, [$rank, ['label' => $lang['col_name'], 'align' => 'left'], ['label' => $lang['col_number'], 'align' => 'right']], function ($rows) {
                $result = [];
                $n = 1;
                foreach ($rows as $a) {
                    $result[] = [
                        ['html' => $n++, 'align' => 'center'],
                        ['html' => $a->client_name, 'align' => 'left'],
                        ['html' => number_format($a->client_num), 'align' => 'right'],
                    ];
                }

                return $result;
            }];
        }
        if ($key == 'ss') {
            $rows = DB::table('stylesheets as s')
                ->selectRaw('s.name AS stylesheet_name, COUNT(users.id) AS stylesheet_num')
                ->join('users', 's.id', '=', 'users.stylesheet')
                ->groupBy('users.stylesheet', 'stylesheet_name')
                ->orderByRaw('stylesheet_num DESC')
                ->limit($limit)
                ->get();

            return [$rows, [$rank, ['label' => $lang['col_name'], 'align' => 'left'], ['label' => $lang['col_number'], 'align' => 'right']], function ($rows) {
                $result = [];
                $n = 1;
                foreach ($rows as $a) {
                    $result[] = [
                        ['html' => $n++, 'align' => 'center'],
                        ['html' => $a->stylesheet_name, 'align' => 'left'],
                        ['html' => number_format($a->stylesheet_num), 'align' => 'right'],
                    ];
                }

                return $result;
            }];
        }
        if ($key == 'lang') {
            $rows = DB::table('language as l')
                ->selectRaw('l.lang_name AS lang_name, COUNT(users.id) AS lang_num')
                ->join('users', 'l.id', '=', 'users.lang')
                ->where('l.site_lang', 1)
                ->groupBy('users.lang', 'lang_name')
                ->orderByRaw('lang_num DESC')
                ->limit($limit)
                ->get();

            return [$rows, [$rank, ['label' => $lang['col_name'], 'align' => 'left'], ['label' => $lang['col_number'], 'align' => 'right']], function ($rows) {
                $result = [];
                $n = 1;
                foreach ($rows as $a) {
                    $result[] = [
                        ['html' => $n++, 'align' => 'center'],
                        ['html' => $a->lang_name, 'align' => 'left'],
                        ['html' => number_format($a->lang_num), 'align' => 'right'],
                    ];
                }

                return $result;
            }];
        }

        return [[], [], function () { return []; }];
    }
}