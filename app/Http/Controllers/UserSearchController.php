<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Administrative user search, replaces legacy public/usersearch.php (Phase 2 P2).
 *
 * Mirrors the legacy filter form exactly; every filter is converted into a
 * parameterized query-builder clause (no inline SQL values).
 */
class UserSearchController extends Controller
{
    private const UNIT = 1073741824; // 1 GB

    public function index(Request $request)
    {
        $currentUser = Auth::user();
        if ($currentUser->class < User::CLASS_MODERATOR) {
            abort(403, 'Permission denied.');
        }

        $showHelp = (bool) $request->integer('h', 0);

        $searchParams = array_diff(array_keys($request->query()), ['h', 'page']);
        $hasSearch = $searchParams !== [];

        $query = DB::table('users as u');
        if ($hasSearch) {
            $this->applyFilters($query, $request);
        }

        $pageIndex = max(0, (int) $request->get('page', 0));
        $page = $pageIndex + 1;
        $perPage = 30;
        $total = $hasSearch ? (clone $query)->count() : 0;

        $users = $hasSearch
            ? (clone $query)
                ->selectRaw('u.id, u.username, u.email, u.status, u.added, u.last_access, u.ip, u.class, u.uploaded, u.downloaded, u.donor, u.enabled, u.warned')
                ->orderBy('u.id')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get()
            : collect();

        $paginator = new LengthAwarePaginator(
            $users,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $rows = $this->buildResultRows($users, $currentUser);

        return view('usersearch', [
            'request' => $request,
            'langFunctions' => get_legacy_lang_file('functions'),
            'showHelp' => $showHelp,
            'rows' => $rows,
            'paginator' => $paginator,
            'pageTitle' => 'Administrative User Search',
            'total' => $total,
            'hasSearch' => $hasSearch,
        ]);
    }

    private function applyFilters($query, Request $request)
    {
        // ----- username / email shared wildcard helpers

        // name
        $name = trim((string) $request->get('n', ''));
        if ($name !== '') {
            [$inc, $exc] = $this->splitTerms($name);
            if ($inc) {
                $query->where(function ($q) use ($inc) {
                    $this->whereTerms($q, 'u.username', $inc);
                });
            }
            if ($exc) {
                $query->whereNot(function ($q) use ($exc) {
                    $this->whereTerms($q, 'u.username', $exc);
                });
            }
        }

        // email
        $email = trim((string) $request->get('em', ''));
        if ($email !== '') {
            $emailTerms = preg_split('/\s+/', $email, -1, PREG_SPLIT_NO_EMPTY);
            $query->where(function ($q) use ($emailTerms) {
                foreach ($emailTerms as $term) {
                    if (!str_contains($term, '*') && !str_contains($term, '?') && !str_contains($term, '%')) {
                        if (!validemail($term)) {
                            abort(422, 'Bad email.');
                        }
                        $q->orWhere('u.email', $term);
                    } else {
                        $q->orWhere('u.email', 'like', str_replace(['?', '*'], ['_', '%'], $term));
                    }
                }
            });
        }

        // class (the form passes the real class + 2)
        $classValue = (int) $request->get('c', 1) - 2;
        if ($classValue >= 0 && $classValue <= User::CLASS_STAFF_LEADER) {
            $query->where('u.class', $classValue);
        }

        // ip + subnet mask
        $ip = trim((string) $request->get('ip', ''));
        if ($ip !== '') {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                abort(422, 'Bad IP.');
            }
            $mask = trim((string) $request->get('ma', ''));
            if ($mask === '' || $mask === '255.255.255.255') {
                $query->where('u.ip', $ip);
            } else {
                if (str_starts_with($mask, '/')) {
                    $n = (int) substr($mask, 1);
                    if (!is_numeric(substr($mask, 1)) || $n < 0 || $n > 32) {
                        abort(422, 'Bad subnet mask.');
                    }
                    $mask = long2ip(pow(2, 32) - pow(2, 32 - $n));
                } elseif (!preg_match('/^(((1?\d{1,2})|(2[0-4]\d)|(25[0-5]))(\.\b|$)){4}$/', $mask)) {
                    abort(422, 'Bad subnet mask.');
                }
                $query->whereRaw('INET_ATON(u.ip) & INET_ATON(?) = INET_ATON(?) & INET_ATON(?)', [$mask, $ip, $mask]);
            }
        }

        // ratio
        $ratio = trim((string) $request->get('r', ''));
        if ($ratio !== '') {
            if ($ratio === '---') {
                $query->where('u.uploaded', 0)->where('u.downloaded', 0);
            } elseif (strtolower(substr($ratio, 0, 3)) === 'inf') {
                $query->where('u.uploaded', '>', 0)->where('u.downloaded', 0);
            } else {
                if (!is_numeric($ratio) || $ratio < 0) {
                    abort(422, 'Bad ratio.');
                }
                $ratioType = (string) $request->get('rt', '');
                if ($ratioType == '3') {
                    $ratio2 = trim((string) $request->get('r2', ''));
                    if ($ratio2 === '') {
                        abort(422, 'Two ratios needed for this type of search.');
                    }
                    if (!is_numeric($ratio2) || $ratio2 < $ratio) {
                        abort(422, 'Bad second ratio.');
                    }
                    $query->whereRaw('(u.uploaded/u.downloaded) BETWEEN ? AND ?', [$ratio, $ratio2]);
                } elseif ($ratioType == '2') {
                    $query->whereRaw('(u.uploaded/u.downloaded) < ?', [$ratio]);
                } elseif ($ratioType == '1') {
                    $query->whereRaw('(u.uploaded/u.downloaded) > ?', [$ratio]);
                } else {
                    $query->whereRaw('(u.uploaded/u.downloaded) BETWEEN ? AND ?', [$ratio - 0.004, $ratio + 0.004]);
                }
            }
        }

        // uploaded
        $ul = trim((string) $request->get('ul', ''));
        if ($ul !== '') {
            if (!is_numeric($ul) || $ul < 0) {
                abort(422, 'Bad uploaded amount.');
            }
            $this->applyAmountClause($query, $request, 'u.uploaded', $ul, (string) $request->get('ult', ''), 'ul2', 'Bad second uploaded amount.');
        }

        // downloaded
        $dl = trim((string) $request->get('dl', ''));
        if ($dl !== '') {
            if (!is_numeric($dl) || $dl < 0) {
                abort(422, 'Bad downloaded amount.');
            }
            $this->applyAmountClause($query, $request, 'u.downloaded', $dl, (string) $request->get('dlt', ''), 'dl2', 'Bad second downloaded amount.');
        }

        // date joined
        $date = trim((string) $request->get('d', ''));
        if ($date !== '') {
            $this->applyDateClause($query, $request, 'u.added', $date, (string) $request->get('dt', '0'), 'd2', 'Two dates needed for this type of search.');
        }

        // last seen
        $last = trim((string) $request->get('ls', ''));
        if ($last !== '') {
            $this->applyDateClause($query, $request, 'u.last_access', $last, (string) $request->get('lst', '0'), 'ls2', 'The second date is not valid.');
        }

        // member status
        $status = (int) $request->get('st', 0);
        if ($status >= 1) {
            $query->where('u.status', $status == 1 ? 'confirmed' : 'pending');
        }

        // account status
        $accountStatus = (int) $request->get('as', 0);
        if ($accountStatus >= 1) {
            $query->where('u.enabled', $accountStatus == 1 ? 'yes' : 'no');
        }

        // donor
        $donor = (int) $request->get('do', 0);
        if ($donor >= 1) {
            $query->where('u.donor', $donor == 1 ? 'yes' : 'no');
        }

        // warned
        $warned = (int) $request->get('w', 0);
        if ($warned >= 1) {
            $query->where('u.warned', $warned == 1 ? 'yes' : 'no');
        }

        // disabled IP
        if ((int) $request->get('dip', 0)) {
            $query->whereExists(function ($q) {
                $q->selectRaw('1')->from('users as u2')
                    ->whereColumn('u2.ip', 'u.ip')
                    ->where('u2.enabled', 'no');
            });
        }

        // active (seeders/leechers only)
        if ((int) $request->get('ac', 0)) {
            $query->whereExists(function ($q) {
                $q->selectRaw('1')->from('peers')
                    ->whereColumn('peers.userid', 'u.id');
            });
        }
    }

    private function splitTerms(string $text): array
    {
        $inc = [];
        $exc = [];
        foreach (preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) as $term) {
            if (str_starts_with($term, '~')) {
                if ($term === '~') {
                    continue;
                }
                $exc[] = substr($term, 1);
            } else {
                $inc[] = $term;
            }
        }

        return [$inc, $exc];
    }

    private function whereTerms($q, string $column, array $terms, bool $contains = false)
    {
        foreach ($terms as $term) {
            if ($this->hasWildcard($term)) {
                $q->orWhere($column, 'like', str_replace(['?', '*'], ['_', '%'], $term));
            } elseif ($contains) {
                $q->orWhere($column, 'like', '%' . $term . '%');
            } else {
                $q->orWhere($column, $term);
            }
        }
    }

    private function hasWildcard(string $text): bool
    {
        return str_contains($text, '*') || str_contains($text, '?') || str_contains($text, '%') || str_contains($text, '_');
    }

    /**
     * Validates a date in the form [yy]yy-mm-dd; returns 'Y-m-d' if valid, null otherwise.
     */
    private function mkdate(string $date): ?string
    {
        if (str_contains($date, '-')) {
            $parts = explode('-', $date);
        } elseif (str_contains($date, '/')) {
            $parts = explode('/', $date);
        } else {
            return null;
        }
        if (count($parts) !== 3) {
            return null;
        }
        foreach ($parts as $part) {
            if (!is_numeric($part)) {
                return null;
            }
        }
        [$year, $month, $day] = $parts;
        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        return date('Y-m-d', mktime(0, 0, 0, (int) $month, (int) $day, (int) $year));
    }

    private function applyAmountClause($query, Request $request, string $column, string $value, string $type, string $secondParam, string $secondError)
    {
        $unit = self::UNIT;
        if ($type == '3') {
            $value2 = trim((string) $request->get($secondParam, ''));
            if ($value2 === '') {
                abort(422, 'Two uploaded amounts needed for this type of search.');
            }
            if (!is_numeric($value2) || $value2 < $value) {
                abort(422, $secondError);
            }
            $query->whereBetween($column, [$value * $unit, $value2 * $unit]);
        } elseif ($type == '2') {
            $query->where($column, '<', $value * $unit);
        } elseif ($type == '1') {
            $query->where($column, '>', $value * $unit);
        } else {
            $query->whereBetween($column, [($value - 0.004) * $unit, ($value + 0.004) * $unit]);
        }
    }

    private function applyDateClause($query, Request $request, string $column, string $date, string $type, string $secondParam, string $secondError)
    {
        $parsed = $this->mkdate($date);
        if (!$parsed) {
            abort(422, 'Invalid date.');
        }
        $start = $parsed . ' 00:00:00';
        if ($type == '3') {
            $parsed2 = $this->mkdate(trim((string) $request->get($secondParam, '')));
            if (!$parsed2) {
                abort(422, $secondError);
            }
            $query->whereBetween($column, [$start, $parsed2 . ' 00:00:00']);
        } elseif ($type == '1') {
            $query->where($column, '<', $start);
        } elseif ($type == '2') {
            $query->where($column, '>', $start);
        } else {
            $query->where($column, '>=', $start)
                ->where($column, '<', date('Y-m-d', strtotime($parsed . ' +1 day')) . ' 00:00:00');
        }
    }

    private function buildResultRows($users, User $currentUser): array
    {
        $rows = [];
        foreach ($users as $user) {
            $added = ($user->added === null || $user->added === '0000-00-00 00:00:00') ? '---' : $user->added;
            $lastAccess = ($user->last_access === null || $user->last_access === '0000-00-00 00:00:00') ? '---' : $user->last_access;

            $ipHtml = '---';
            $ip = $user->ip;
            if ($ip) {
                $ipHtml = $ip;
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $nip = ip2long($ip);
                    $banned = DB::table('bans')->where('first', '<=', $nip)->where('last', '>=', $nip)->count() > 0;
                    if ($banned) {
                        $ipHtml = "<a href='testip.php?ip=" . $ip . "'><font color='#FF0000'><b>" . $ip . "</b></font></a>";
                    }
                }
            }

            $peerSums = DB::table('peers')->where('userid', $user->id)
                ->selectRaw('SUM(uploaded) AS pul, SUM(downloaded) AS pdl')
                ->first();
            $pul = (float) ($peerSums->pul ?? 0);
            $pdl = (float) ($peerSums->pdl ?? 0);

            $postsCount = DB::table('posts as p')
                ->join('topics as t', 'p.topicid', '=', 't.id')
                ->join('forums as f', 't.forumid', '=', 'f.id')
                ->where('p.userid', $user->id)
                ->where('f.minclassread', '<=', $currentUser->class)
                ->count();
            $commentsCount = DB::table('comments')->where('user', $user->id)->count();

            $rows[] = [
                'id' => $user->id,
                'username' => get_username($user->id),
                'ratio' => $this->ratioHtml($user->uploaded, $user->downloaded),
                'ip' => $ipHtml,
                'email' => $user->email,
                'added' => $added,
                'last_access' => $lastAccess,
                'status' => $user->status,
                'enabled' => $user->enabled,
                'p_ratio' => $this->ratioHtml($pul, $pdl),
                'p_ul' => mksize($pul),
                'p_dl' => mksize($pdl),
                'posts' => $postsCount,
                'comments' => $commentsCount,
            ];
        }

        return $rows;
    }

    private function ratioHtml($up, $down): string
    {
        if ($down > 0) {
            $ratio = number_format($up / $down, 2);
            $color = get_ratio_color($ratio);
            return $color ? "<font color=\"{$color}\">{$ratio}</font>" : $ratio;
        }
        return $up > 0 ? 'Inf.' : '---';
    }
}