<?php

namespace App\Http\Controllers;

use App\Models\Bookmark;
use App\Models\SearchBox;
use App\Models\Setting;
use App\Models\Torrent;
use App\Models\User;
use App\Repositories\TorrentRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Nexus\Database\NexusDB;

/**
 * RSS pages, replaces legacy public/getrss.php + public/torrentrss.php (Phase 2 P2).
 *
 * - index(): the "RSS subscription" form (GET renders, POST builds the feed URL).
 * - feed():  passkey-gated RSS 2.0 XML, ported from public/torrentrss.php; the
 *   category/subcategory bindings are validated against the DB instead of the
 *   legacy $Cache-backed searchbox_item_list().
 */
class RssController extends Controller
{
    private const EXACT_PARAMS = ['inclbookmarked', 'incldesc', 'paid', 'rows', 'icat', 'ismalldescr', 'isize', 'iuplder', 'search', 'search_mode', 'sticky', 'linktype'];

    private const TAXONOMY_COLUMNS = [
        'cat' => ['table' => 'categories', 'column' => 'category'],
        'sou' => ['table' => 'sources', 'column' => 'source'],
        'med' => ['table' => 'media', 'column' => 'medium'],
        'cod' => ['table' => 'codecs', 'column' => 'codec'],
        'sta' => ['table' => 'standards', 'column' => 'standard'],
        'pro' => ['table' => 'processings', 'column' => 'processing'],
        'tea' => ['table' => 'teams', 'column' => 'team'],
        'aud' => ['table' => 'audiocodecs', 'column' => 'audiocodec'],
    ];

    /**
     * getrss.php — the RSS subscription form.
     */
    public function index(Request $request)
    {
        $currentUser = Auth::user();
        $lang = get_legacy_lang_file('getrss');

        $browseMode = SearchBox::getBrowseMode();
        $specialMode = SearchBox::getSpecialMode();
        $enableSpecial = get_setting('main.spsct', 'no') == 'yes';
        $allowSpecial = $enableSpecial && user_can('view_special_torrent');

        $browseSearchBox = SearchBox::get($browseMode);
        $specialSearchBox = $allowSpecial ? SearchBox::get($specialMode) : null;
        $showSubcat = $allowSpecial
            ? ((bool) ($browseSearchBox->showsubcat ?? false) || (bool) ($specialSearchBox->showsubcat ?? false))
            : (bool) ($browseSearchBox->showsubcat ?? false);

        $stickyTypes = [
            0 => nexus_trans('torrent.pos_state_normal'),
            1 => nexus_trans('torrent.pos_state_sticky'),
            2 => nexus_trans('torrent.pos_state_r_sticky'),
        ];

        $link = '';
        $error = '';
        $message = '';

        if ($request->isMethod('post')) {
            $allowedShowRows = ['10', '50'];
            $showRows = $request->input('showrows');
            $query = [];
            $query[] = 'passkey=' . $currentUser->passkey;
            if (in_array($showRows, $allowedShowRows, true)) {
                $query[] = 'rows=' . (int) $showRows;
                // categories are always collected
                foreach (NexusDB::table('categories')->pluck('id') as $itemId) {
                    if ($request->input("cat{$itemId}")) {
                        $query[] = "cat{$itemId}=1";
                    }
                }
                // taxonomy params only when subcategories are shown
                if ($showSubcat) {
                    foreach (self::TAXONOMY_COLUMNS as $prefix => $conf) {
                        if ($prefix === 'cat') {
                            continue;
                        }
                        foreach (NexusDB::table($conf['table'])->pluck('id') as $itemId) {
                            if ($request->input("{$prefix}{$itemId}")) {
                                $query[] = "{$prefix}{$itemId}=1";
                            }
                        }
                    }
                }
                if ($request->input('itemcategory')) {
                    $query[] = 'icat=1';
                }
                if ($request->input('itemsmalldescr')) {
                    $query[] = 'ismalldescr=1';
                }
                if ($request->input('itemsize')) {
                    $query[] = 'isize=1';
                }
                if ($request->input('itemuploader')) {
                    $query[] = 'iuplder=1';
                }
                $includeDesc = (int) ($request->input('incldesc', 0));
                if ($includeDesc === 1) {
                    $query[] = 'incldesc=1';
                }
                $searchString = trim((string) $request->input('search', ''));
                if ($searchString !== '') {
                    $query[] = 'search=' . rawurlencode($searchString);
                    $searchMode = (int) ($request->input('search_mode', 0));
                    if (!in_array($searchMode, [0, 2], true)) {
                        $searchMode = 0;
                    }
                    $query[] = 'search_mode=' . $searchMode;
                }
                if ($request->input('sticky') && is_array($request->input('sticky'))) {
                    $query[] = 'sticky=' . implode(',', $request->input('sticky'));
                }
                if ($request->has('paid')) {
                    $query[] = 'paid=' . $request->input('paid');
                }
                $includeBookmarked = (int) ($request->input('inclbookmarked', 0));
                if (!in_array($includeBookmarked, [0, 1], true)) {
                    $includeBookmarked = 0;
                }
                $addIncludeBm = $includeBookmarked ? '&inclbookmarked=1' : '';

                $link = get_protocol_prefix() . Setting::getBaseUrl() . '/torrentrss.php';
                $queries = implode('&', $query);
                if ($queries) {
                    $link .= '?' . $queries;
                }
                $message = $lang['std_use_following_url'] . "\n" . $link . "\n\n"
                    . $lang['std_utorrent_feed_url'] . "\n" . $link . '&linktype=dl' . $addIncludeBm;
            } else {
                $error = $lang['std_no_row'];
            }
        }

        $categoriesHtml = build_search_box_category_table($browseMode, 'yes', 'torrents.php?allsec=1&', false, 3, '', ['section_name' => true]);
        $categoriesSpecialHtml = '';
        if ($enableSpecial) {
            $categoriesSpecialHtml = build_search_box_category_table($specialMode, 'yes', 'special.php?allsec=1&', false, 3, '', ['section_name' => true]);
        }

        return view('getrss', [
            'lang' => $lang,
            'langFunctions' => get_legacy_lang_file('functions'),
            'pageTitle' => $lang['head_rss_feeds'],
            'categoriesHtml' => $categoriesHtml,
            'categoriesSpecialHtml' => $categoriesSpecialHtml,
            'enableSpecial' => $enableSpecial,
            'showSubcat' => $showSubcat,
            'stickyTypes' => $stickyTypes,
            'paidTorrentEnabled' => get_setting('torrent.paid_torrent_enabled', 'no') == 'yes',
            'link' => $link,
            'error' => $error,
            'message' => $message,
        ]);
    }

    /**
     * torrentrss.php — the RSS 2.0 XML feed, gated by passkey (no session).
     */
    public function feed(Request $request)
    {
        $passkey = $request->get('passkey');
        if (!$passkey && Auth::guard('nexus')->check()) {
            $passkey = Auth::guard('nexus')->user()->passkey;
        }
        if (!$passkey) {
            return response('require passkey');
        }

        $getParams = $request->query();
        foreach ($getParams as $key => $value) {
            if (in_array($key, self::EXACT_PARAMS, true)) {
                continue;
            }
            if (preg_match('/^(cat|sou|med|cod|sta|pro|tea|aud)\d+$/', $key)) {
                continue;
            }
            unset($getParams[$key]);
        }

        $cacheKey = "nexus_rss:$passkey:" . md5(http_build_query($getParams));
        $cacheData = NexusDB::cache_get($cacheKey);
        if ($cacheData && nexus_env('APP_ENV') != 'local') {
            do_log('rss get from cache');
            return response($cacheData)->header('Content-Type', 'text/xml');
        }

        $useDownloadLink = false;
        $where = [];

        $user = NexusDB::remember('user_passkey_' . $passkey . '_rss', 3600, function () use ($passkey) {
            $row = User::query()->where('passkey', $passkey)->first(['id', 'enabled', 'parked', 'passkey']);
            return $row ? $row->makeVisible('passkey')->toArray() : null;
        });
        if (!$user) {
            return response('invalid passkey');
        }
        if ($user['enabled'] == 'no' || $user['parked'] == 'yes') {
            return response('account disabed or parked');
        }
        if ($request->get('linktype') == 'dl') {
            $useDownloadLink = true;
        }
        $includeBookmarked = (int) $request->get('inclbookmarked', 0);
        if ($includeBookmarked == 1) {
            $bookmarkIdArr = Bookmark::query()->where('userid', $user['id'])->pluck('torrentid')->toArray();
            if ($bookmarkIdArr) {
                $where[] = ['torrents.id', 'in', $bookmarkIdArr];
            }
        }

        // ---- taxonomy param clauses (validated against the DB tables) ----
        foreach (self::TAXONOMY_COLUMNS as $prefix => $conf) {
            $selectedIds = [];
            foreach (NexusDB::table($conf['table'])->pluck('id') as $itemId) {
                if ($request->input($prefix . $itemId)) {
                    $selectedIds[] = $itemId;
                }
            }
            if (count($selectedIds) >= 1) {
                $where[] = ['torrents.' . $conf['column'], 'in', $selectedIds];
            }
        }

        // ---- approval status ----
        $approvalStatusNoneVisible = get_setting('torrent.approval_status_none_visible');
        if ($approvalStatusNoneVisible == 'no' && !user_can('staffmem', false, $user['id'])) {
            $where[] = ['torrents.approval_status', Torrent::APPROVAL_STATUS_ALLOW];
        }
        // ---- section permission ----
        $browseMode = get_setting('main.browsecat');
        $onlyBrowseSection = get_setting('main.spsct', 'no') != 'yes' || !user_can('view_special_torrent', false, $user['id']);
        if ($onlyBrowseSection) {
            $allBrowseCategoryId = \App\Models\Category::query()->where('mode', $browseMode)->pluck('id')->toArray();
            $where[] = ['torrents.category', 'in', $allBrowseCategoryId];
        }
        // ---- visible ----
        $where[] = ['torrents.visible', 'yes'];
        // ---- paid ----
        $paidFilter = $request->get('paid');
        if (!in_array($paidFilter, ['0', '1', '2'], true)) {
            $paidFilter = '0';
        }
        if ($paidFilter === '0') {
            $where[] = ['torrents.price', 0];
        } elseif ($paidFilter === '1') {
            $where[] = ['torrents.price', '>', 0];
        }

        $showRows = (int) $request->get('rows', 0);
        if ($showRows < 1 || $showRows > 50) {
            $showRows = 50;
        }

        $baseQuery = DB::table('torrents')
            ->leftJoin('categories', 'torrents.category', '=', 'categories.id')
            ->select('torrents.id', 'torrents.category', 'torrents.name', 'torrents.small_descr', 'torrents.info_hash', 'torrents.size', 'torrents.added', 'torrents.anonymous', 'torrents.owner', 'categories.name AS category_name');
        $includeDescription = (int) $request->get('incldesc', 0) === 1;
        if ($includeDescription) {
            $baseQuery->addSelect('torrent_extras.descr');
            $baseQuery->leftJoin('torrent_extras', 'torrent_extras.torrent_id', '=', 'torrents.id');
        }
        foreach ($where as $clause) {
            if ($clause[1] === 'in') {
                $baseQuery->whereIn($clause[0], $clause[2]);
            } else {
                $baseQuery->where(...$clause);
            }
        }

        $hasStickyFirst = $hasStickySecond = $hasStickyNormal = false;
        $prependIdArr = [];
        if (isset($getParams['sticky']) && $includeBookmarked == 0) {
            $stickyArr = explode(',', $getParams['sticky']);
            $posStates = [];
            if (in_array('0', $stickyArr, true)) {
                $hasStickyNormal = true;
            }
            if (in_array('1', $stickyArr, true)) {
                $hasStickyFirst = true;
                $posStates[] = Torrent::POS_STATE_STICKY_FIRST;
            }
            if (in_array('2', $stickyArr, true)) {
                $hasStickySecond = true;
                $posStates[] = Torrent::POS_STATE_STICKY_SECOND;
            }
            if (!empty($posStates)) {
                $prependIdArr = Torrent::query()->whereIn('pos_state', $posStates)->pluck('id')->toArray();
            }
        }
        $prependIdArr = apply_filter('sticky_promotion_torrent_ids', $prependIdArr);

        $prepareBase = function () use ($baseQuery) {
            return clone $baseQuery;
        };

        $normalQuery = $prepareBase();
        $noNormalResults = false;
        if ($hasStickyNormal) {
            $normalQuery->where('torrents.pos_state', Torrent::POS_STATE_STICKY_NONE);
        } elseif ($hasStickyFirst || $hasStickySecond) {
            $noNormalResults = true;
        }
        $normalRows = [];
        if (!$noNormalResults) {
            $normalRows = NexusDB::remember(sprintf('nexus_rss:normal:%s', md5($normalQuery->toSql() . serialize($normalQuery->getBindings()))), 300, function () use ($normalQuery, $showRows) {
                return $normalQuery->orderBy('torrents.id', 'desc')->limit($showRows)->get()
                    ->map(function ($row) {
                        return (array) $row;
                    })
                    ->all();
            });
        }

        $prependRows = [];
        if (!empty($prependIdArr)) {
            $prependQuery = $prepareBase();
            $prependQuery->whereIn('torrents.id', $prependIdArr);
            $prependIdStr = implode(',', $prependIdArr);
            $prependRows = NexusDB::remember(sprintf('nexus_rss:prepend:%s', md5($prependQuery->toSql() . serialize($prependQuery->getBindings()))), 300, function () use ($prependQuery, $prependIdStr) {
                return $prependQuery->orderByRaw("field(torrents.id, $prependIdStr)")->get()
                    ->map(function ($row) {
                        return (array) $row;
                    })
                    ->all();
            });
        }

        $list = [];
        foreach ($prependRows as $row) {
            $list[$row['id']] = $row;
        }
        foreach ($normalRows as $row) {
            if (!isset($list[$row['id']])) {
                $list[$row['id']] = $row;
            }
        }

        $torrentRep = new TorrentRepository();
        $url = get_protocol_prefix() . Setting::getBaseUrl();
        $siteName = Setting::getSiteName();
        $slogan = get_setting('main.SLOGAN', '');
        $siteEmail = get_setting('main.SITEEMAIL', '');
        $year = substr(get_setting('tweak.datefounded', '2007'), 0, 4);
        $yearFounded = ($year ? $year : 2007);
        $copyright = 'Copyright (c) ' . $siteName . ' ' . (date('Y') != $yearFounded ? $yearFounded . '-' : '') . date('Y') . ', all rights reserved';

        $xml = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n";
        $xml .= '<rss version="2.0">';
        $xml .= '<channel>
		<title>' . addslashes($siteName . ' Torrents') . '</title>
		<link><![CDATA[' . $url . ']]></link>
		<description><![CDATA[' . addslashes('Latest torrents from ' . $siteName . ' - ' . htmlspecialchars($slogan)) . ']]></description>
		<language>zh-cn</language>
		<copyright>' . $copyright . '</copyright>
		<managingEditor>' . $siteEmail . ' (' . $siteName . ' Admin)</managingEditor>
		<webMaster>' . $siteEmail . ' (' . $siteName . ' Webmaster)</webMaster>
		<pubDate>' . date('r') . '</pubDate>
		<generator>' . constant('PROJECTNAME') . ' RSS Generator</generator>
		<docs><![CDATA[http://www.rssboard.org/rss-specification]]></docs>
		<ttl>60</ttl>
		<image>
			<url><![CDATA[' . $url . '/pic/rss_logo.jpg' . ']]></url>
			<title>' . addslashes($siteName . ' Torrents') . '</title>
			<link><![CDATA[' . $url . ']]></link>
			<width>100</width>
			<height>100</height>
			<description>' . addslashes($siteName . ' Torrents') . '</description>
		</image>';

        foreach ($list as $row) {
            $title = '';
            if ($row['anonymous'] == 'yes') {
                $author = 'anonymous';
            } else {
                $ownerInfo = get_user_row($row['owner']);
                $author = !empty($ownerInfo) ? $ownerInfo['username'] : nexus_trans('nexus.user_not_exists');
            }
            $itemUrl = $url . '/details.php?id=' . $row['id'];
            if ($useDownloadLink) {
                $itemDlUrl = $torrentRep->getDownloadUrl($row['id'], $user);
            } else {
                $itemDlUrl = $url . '/download.php?id=' . $row['id'];
            }
            if (!empty($getParams['icat'])) {
                $title .= '[' . $row['category_name'] . ']';
            }
            $title .= $row['name'];
            if (!empty($getParams['ismalldescr']) && !empty($row['small_descr'])) {
                $title .= '[' . $row['small_descr'] . ']';
            }
            if (!empty($getParams['isize'])) {
                $title .= '[' . mksize($row['size']) . ']';
            }
            if (!empty($getParams['iuplder'])) {
                $title .= '[' . $author . ']';
            }
            $xml .= '<item>
			<title><![CDATA[' . $title . ']]></title>
			<link>' . $itemUrl . '</link>
';
            if ($includeDescription) {
                $content = format_comment($row['descr'], true, false, false, false);
                $xml .= "\t\t\t<description><![CDATA[" . $content . "]]></description>\n";
            }
            $xml .= '<author>' . $author . '@' . $request->getHost() . ' (' . $author . ')</author>';
            $xml .= '<category domain="' . $url . '/torrents.php?cat=' . $row['category'] . '">' . $row['category_name'] . '</category>
			<comments><![CDATA[' . $url . '/details.php?id=' . $row['id'] . '&cmtpage=0#startcomments]]></comments>
			<enclosure url="' . $itemDlUrl . '" length="' . $row['size'] . '" type="application/x-bittorrent" />
			<guid isPermaLink="false">' . preg_replace_callback('/./s', function ($matches) {
                    return sprintf('%02x', ord($matches[0]));
                }, hash_pad($row['info_hash'])) . '</guid>
			<pubDate>' . date('r', strtotime($row['added'])) . '</pubDate>
		</item>
';
        }
        $xml .= '</channel>
</rss>';
        do_log('rss cache generated');
        NexusDB::cache_put($cacheKey, $xml, 300);
        return response($xml)->header('Content-Type', 'text/xml');
    }
}