<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Models\Poll;
use App\Models\PollAnswer;
use App\Models\Setting;
use App\Models\Torrent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Home page, replaces legacy public/index.php (Phase 3 P0).
 *
 * Renders news / funbox / shoutbox / latest posts & torrents / top uploader /
 * polls / tracker statistics. The legacy output caching ($Cache->new_page())
 * is replaced by data-level Laravel Cache entries; the keys reuse the legacy
 * names so the transitional public/*.php siblings keep sharing the cache.
 */
class IndexController extends Controller
{
    public const CACHE_TOP_UPLOADER_ALL = 'index_top_uploader_all';
    public const CACHE_TOP_UPLOADER_RECENTLY = 'index_top_uploader_recently';
    public const CACHE_POLL_CONTENT = 'current_poll_content';
    public const CACHE_POLL_RESULT = 'current_poll_result';
    public const CACHE_FUN_CONTENT = 'current_fun_content';
    public const CACHE_FUN_VOTE_COUNT = 'current_fun_vote_count_';
    public const CACHE_FUN_VOTE_FUNNY_COUNT = 'current_fun_vote_funny_count_';
    public const CACHE_NEWS = 'index_recent_news';
    public const CACHE_LINKS = 'index_links';
    public const CACHE_STATS_USERS = 'index_stats_users';
    public const CACHE_STATS_TORRENTS = 'index_stats_torrents';
    public const CACHE_STATS_CLASSES = 'index_stats_classes';

    /**
     * Show the home page. Mirrors legacy public/index.php GET.
     */
    public function show(Request $request)
    {
        $user = Auth::user();
        if ($user && $user->parked == 'yes') {
            abort(403, 'Your account is parked.');
        }

        $lang = get_legacy_lang_file('index');

        if ($user) {
            // legacy public/index.php footer side-effects
            DB::table('users')->where('id', $user->id)->update(['last_home' => now()]);
            Cache::forget('user_' . $user->id . '_unread_news_count');
        }

        $viewData = $this->buildHomeViewData($user, $lang, $request, [
            'showFunbox' => $this->settingYes('main.showfunbox') && (!$user || $user->showfb != 'no'),
            'showShoutbox' => $this->settingYes('main.showshoutbox'),
            'showLatestForumPosts' => $this->settingYes('main.showlastxforumposts') && (bool) $user,
            'showLatestTorrents' => $this->settingYes('main.showlastxtorrents'),
            'showTopUploader' => get_setting('main.show_top_uploader', 'no') == 'yes',
            'showPolls' => $this->settingYes('main.showpolls') && (bool) $user,
            'showStats' => $this->settingYes('main.showstats'),
            'showTrackerLoad' => $this->settingYes('main.showtrackerload'),
        ]);

        return view('index', $viewData);
    }

    private function buildHomeViewData(?User $user, array $lang, Request $request, array $flags): array
    {
        $extraModules = apply_filter('nexus_home_module', []);

        return [
            'request' => $request,
            'lang' => $lang,
            'langFunctions' => get_legacy_lang_file('functions'),
            'user' => $user,
            'pageTitle' => $lang['head_home'] ?? 'Home',
            'news' => $this->buildNews($lang),
            'funbox' => $flags['showFunbox'] ? $this->buildFunbox($user, $lang) : null,
            'shoutbox' => $flags['showShoutbox'] ? [
                'showHelpbox' => $this->settingYes('main.showhelpbox'),
                'canClearShoutBox' => $user ? user_can('sbmanage') : false,
            ] : null,
            'extraModules' => $extraModules,
            'latestForumPosts' => $flags['showLatestForumPosts'] ? $this->buildLatestForumPosts($user) : null,
            'latestTorrents' => $flags['showLatestTorrents'] ? $this->buildLatestTorrents() : null,
            'topUploader' => $flags['showTopUploader'] ? $this->buildTopUploader() : null,
            'polls' => $flags['showPolls'] ? $this->buildPoll($user, $lang) : null,
            'stats' => $flags['showStats'] ? $this->buildStats() : null,
            'trackerLoad' => $flags['showTrackerLoad'] ? $this->buildTrackerLoad() : null,
            'links' => $this->buildLinks(),
        ];
    }

    /**
     * Handle the poll vote POST, mirrors legacy public/index.php POST.
     */
    public function vote(Request $request)
    {
        $user = Auth::user();
        $lang = get_legacy_lang_file('index');

        if ($this->settingYes('main.showpolls')) {
            $choice = $request->input('choice');
            if ($user && $choice !== '' && $choice !== null && $choice < 256 && $choice == floor($choice)) {
                $poll = Poll::query()->orderByDesc('added')->first();
                if (!$poll) {
                    return back()->with('error', $lang['std_no_poll']);
                }
                if (PollAnswer::query()->where('pollid', $poll->id)->where('userid', $user->id)->exists()) {
                    return back()->with('error', $lang['std_duplicate_votes_denied']);
                }
                PollAnswer::query()->create([
                    'pollid' => $poll->id,
                    'userid' => $user->id,
                    'selection' => (int) $choice,
                ]);
                Cache::forget(self::CACHE_POLL_CONTENT);
                Cache::forget(self::CACHE_POLL_RESULT);
                $voteBonus = (float) Setting::get('bonus.pollvote', 1);
                if ($voteBonus > 0) {
                    User::query()
                        ->where('id', $user->id)
                        ->where('seedbonus', $user->seedbonus)
                        ->increment('seedbonus', $voteBonus);
                }
                return redirect('/');
            }
            return back()->with('error', $lang['std_option_unselected']);
        }

        return redirect('/');
    }

    // ---------------------------------------------------------- module builders

    private function buildNews(array $lang)
    {
        $maxNewsNum = (int) get_setting('main.maxnewsnum', 3);
        return Cache::remember(self::CACHE_NEWS, 86400, function () use ($maxNewsNum) {
            return News::query()->orderByDesc('added')->limit($maxNewsNum)->get();
        });
    }

    private function buildFunbox(?User $user, array $lang): ?array
    {
        if ($user && $user->showfb == 'no') {
            return null;
        }
        $row = Cache::remember(self::CACHE_FUN_CONTENT, 1043, function () {
            return DB::table('fun')
                ->whereNotIn('status', ['banned', 'dull'])
                ->orderByDesc('added')
                ->first();
        });
        if (!$row) {
            return ['row' => null];
        }
        $funId = (int) $row->id;
        $totalVote = Cache::remember(self::CACHE_FUN_VOTE_COUNT . $funId, 756, function () use ($funId) {
            return DB::table('funvotes')->where('funid', $funId)->count();
        });
        $funVote = Cache::remember(self::CACHE_FUN_VOTE_FUNNY_COUNT . $funId, 756, function () use ($funId) {
            return DB::table('funvotes')->where('funid', $funId)->where('vote', 'fun')->count();
        });
        $funVoted = $user
            ? DB::table('funvotes')->where('funid', $funId)->where('userid', $user->id)->count() > 0
            : false;

        return [
            'row' => $row,
            'totalVote' => (int) $totalVote,
            'funVote' => (int) $funVote,
            'funVoted' => $funVoted,
            'needNew' => Carbon::parse($row->added)->addDay()->lt(Carbon::now()),
        ];
    }

    private function buildLatestForumPosts(?User $user)
    {
        if (!$user) {
            return null;
        }
        return DB::table('posts')
            ->join('topics', 'posts.topicid', '=', 'topics.id')
            ->join('forums', 'topics.forumid', '=', 'forums.id')
            ->where('forums.minclassread', '<=', $user->class)
            ->orderByDesc('posts.id')
            ->limit(5)
            ->get([
                'posts.id as pid',
                'posts.userid as userpost',
                'posts.added',
                'topics.id as tid',
                'topics.subject',
                'topics.forumid',
                'topics.views',
                'forums.name',
            ]);
    }

    private function buildLatestTorrents()
    {
        return DB::table('torrents')
            ->where('visible', 'yes')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'name', 'small_descr', 'leechers', 'seeders']);
    }

    private function buildTopUploader(): ?array
    {
        $baseQuery = Torrent::query()
            ->selectRaw('owner, count(*) as counts')
            ->groupBy('owner')
            ->orderByDesc('counts')
            ->take(10);

        $allRows = Cache::remember(self::CACHE_TOP_UPLOADER_ALL, 60, function () use ($baseQuery) {
            return (clone $baseQuery)->get();
        });
        if ($allRows->isEmpty()) {
            return null;
        }
        $recentRows = Cache::remember(self::CACHE_TOP_UPLOADER_RECENTLY, 60, function () use ($baseQuery) {
            return (clone $baseQuery)->where('added', '>=', Carbon::today()->subDays(30))->get();
        });

        $buildRanking = function ($rows) {
            $counts = $rows->pluck('counts', 'owner');
            $uidArr = $rows->pluck('owner')->toArray() ?: [0];
            $users = User::query()
                ->whereIn('id', $uidArr)
                ->orderByRaw(sprintf('field(id,%s)', implode(',', $uidArr)))
                ->get(['id', 'username']);
            $result = [];
            foreach ($users as $ranking => $row) {
                $result[] = [
                    'username' => get_username($row->id),
                    'counts' => (int) $counts->get($row->id, 0),
                    'ranking' => $ranking + 1,
                ];
            }
            return $result;
        };

        return [
            'all' => $buildRanking($allRows),
            'recently' => $buildRanking($recentRows),
        ];
    }

    private function buildPoll(User $user, array $lang): array
    {
        $poll = Cache::remember(self::CACHE_POLL_CONTENT, 7226, function () {
            return Poll::query()->orderByDesc('id')->first();
        });
        if (!$poll) {
            return ['exists' => false];
        }
        $options = [];
        for ($i = 0; $i <= Poll::MAX_OPTION_INDEX; ++$i) {
            $key = 'option' . $i;
            if (!empty($poll->{$key})) {
                $options[$i] = $poll->{$key};
            }
        }
        $answer = PollAnswer::query()
            ->where('pollid', $poll->id)
            ->where('userid', $user->id)
            ->first(['selection']);
        $hasVoted = (bool) $answer;
        $userVote = $hasVoted ? (int) $answer->selection : null;

        $results = null;
        if ($hasVoted) {
            $results = Cache::remember(self::CACHE_POLL_RESULT, 3652, function () use ($poll, $options) {
                $counts = PollAnswer::query()
                    ->where('pollid', $poll->id)
                    ->where('selection', '<', 20)
                    ->get(['selection'])
                    ->pluck('selection')
                    ->countBy()
                    ->all();
                $totalVotes = array_sum($counts);
                $rows = [];
                foreach ($options as $index => $option) {
                    $votes = $counts[$index] ?? 0;
                    $rows[] = [
                        'index' => $index,
                        'option' => $option,
                        'votes' => $votes,
                        'percent' => $totalVotes ? round($votes / $totalVotes * 100) : 0,
                    ];
                }
                return ['totalVotes' => $totalVotes, 'rows' => $rows];
            });
        }

        return [
            'exists' => true,
            'poll' => ['id' => (int) $poll->id, 'question' => $poll->question],
            'options' => $options,
            'hasVoted' => $hasVoted,
            'userVote' => $userVote,
            'results' => $results,
        ];
    }

    private function buildStats(): ?array
    {
        $users = Cache::remember(self::CACHE_STATS_USERS, 3000, function () {
            return [
                'registered' => DB::table('users')->count(),
                'maxusers' => (int) get_setting('main.maxusers', 50000),
                'unverified' => DB::table('users')->where('status', 'pending')->where('enabled', 'yes')->count(),
                'activeToday' => DB::table('users')->where('last_access', '>=', Carbon::now()->subDay())->count(),
                'activeWeek' => DB::table('users')->where('last_access', '>=', Carbon::now()->subWeek())->count(),
                'vip' => DB::table('users')->where('class', User::CLASS_VIP)->count(),
                'donors' => DB::table('users')->where('donor', 'yes')->count(),
                'warned' => DB::table('users')->where('warned', 'yes')->count(),
                'disabled' => DB::table('users')->where('enabled', 'no')->count(),
                'male' => DB::table('users')->where('gender', User::GENDER_MALE)->count(),
                'female' => DB::table('users')->where('gender', User::GENDER_FEMALE)->count(),
            ];
        });
        $torrents = Cache::remember(self::CACHE_STATS_TORRENTS, 1800, function () {
            $seeders = DB::table('peers')->where('seeder', 'yes')->count();
            $leechers = DB::table('peers')->where('seeder', 'no')->count();
            return [
                'torrents' => DB::table('torrents')->count(),
                'dead' => DB::table('torrents')->where('visible', 'no')->count(),
                'seeders' => $seeders,
                'leechers' => $leechers,
                'ratio' => $leechers ? (int) round($seeders / $leechers * 100) : 0,
                'activeBrowsing' => DB::table('users')->where('last_access', '>=', Carbon::now()->subMinutes(15))->count(),
                'trackerActiveUsers' => DB::table('peers')->distinct()->count('userid'),
                'totalSize' => DB::table('torrents')->sum('size'),
                'totalUploaded' => DB::table('users')->sum('uploaded'),
                'totalDownloaded' => DB::table('users')->sum('downloaded'),
            ];
        });
        $classes = Cache::remember(self::CACHE_STATS_CLASSES, 4535, function () {
            $classCounts = DB::table('users')->selectRaw('class, COUNT(*) AS num')->groupBy('class')->pluck('num', 'class');
            $order = [
                User::CLASS_PEASANT,
                User::CLASS_USER,
                User::CLASS_POWER_USER,
                User::CLASS_ELITE_USER,
                User::CLASS_CRAZY_USER,
                User::CLASS_INSANE_USER,
                User::CLASS_VETERAN_USER,
                User::CLASS_EXTREME_USER,
                User::CLASS_ULTIMATE_USER,
                User::CLASS_NEXUS_MASTER,
            ];
            $result = [];
            foreach ($order as $class) {
                $result[] = [
                    'class' => $class,
                    'num' => (int) ($classCounts->get($class) ?? 0),
                ];
            }
            return $result;
        });
        return ['users' => $users, 'torrents' => $torrents, 'classes' => $classes];
    }

    private function buildTrackerLoad(): ?string
    {
        $uptimeResult = @exec('uptime');
        return $uptimeResult !== false ? trim($uptimeResult) : null;
    }

    private function buildLinks()
    {
        return Cache::remember(self::CACHE_LINKS, 86400, function () {
            return DB::table('links')->orderBy('id')->get(['name', 'url', 'title']);
        });
    }

    private function settingYes(string $name): bool
    {
        return (string) get_setting($name, 'no') === 'yes';
    }
}