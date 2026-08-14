@extends('layouts.guest')

@section('title', $pageTitle)

@section('content')
@if (session('error'))
    <table width="100%" border="1" cellspacing="0" cellpadding="10">
        <tr><td class="rowhead" align="center"><b><font class="striking">{{ session('error') }}</font></b></td></tr>
    </table>
    <br />
@endif

{{-- ================= start: recent news ================= --}}
@if ($news && count($news) > 0)
    <h2>{{ $lang['text_recent_news'] }}
        @if (user_can('newsmanage'))
            - <font class="small">[<a class="altlink" href="news.php"><b>{{ $lang['text_news_page'] }}</b></a>]</font>
        @endif
    </h2>
    <table width="100%"><tr><td class="text"><div style="margin-left: 16pt;">
        @foreach ($news as $index => $item)
            @php $expanded = $index == 0; @endphp
            <a href="javascript: klappe_news('a{{ $item->id }}')">
                <img class="{{ $expanded ? 'minus' : 'plus' }}" src="pic/trans.gif" id="pica{{ $item->id }}"
                     alt="Show/Hide" title="{{ $lang['title_show_or_hide'] }}" />&nbsp;
                {{ $item->added->format('Y.m.d') }} - <b>{{ $item->title }}</b>
            </a>
            <div id="ka{{ $item->id }}" style="display: {{ $expanded ? 'block' : 'none' }};">{!! format_comment($item->body, 0) !!}</div>
            @if (user_can('newsmanage'))
                &nbsp; [<a class="faqlink" href="news.php?action=edit&amp;newsid={{ $item->id }}"><b>{{ $lang['text_e'] }}</b></a>]
                [<a class="faqlink" href="news.php?action=delete&amp;newsid={{ $item->id }}"><b>{{ $lang['text_d'] }}</b></a>]
            @endif
            <br />
        @endforeach
    </div></td></tr></table>
@endif
{{-- ================= end: recent news ================= --}}

{{-- ================= start: funbox ================= --}}
@if ($funbox)
    @if (!$funbox['row'])
        <h2>{{ $lang['text_funbox'] }}
            @if (user_can('newfunitem'))<font class="small"> - [<a class="altlink" href="fun.php?action=new"><b>{{ $lang['text_new_fun'] }}</b></a>]</font>@endif
        </h2>
    @else
        @php $funRow = $funbox['row']; @endphp
        <h2>{{ $lang['text_funbox'] }}
            @if ($user)
                <font class="small">
                    @if (user_can('log'))
                        - [<a class="altlink" href="log.php?action=funbox"><b>{{ $lang['text_more_fun'] }}</b></a>]
                    @endif
                    @if ($funbox['needNew'] && user_can('newfunitem'))
                        - [<a class="altlink" href="fun.php?action=new"><b>{{ $lang['text_new_fun'] }}</b></a>]
                    @endif
                    @if ($user->id == $funRow->userid || user_can('funmanage'))
                        - [<a class="altlink" href="fun.php?action=edit&amp;id={{ $funRow->id }}&amp;returnto=index.php"><b>{{ $lang['text_edit'] }}</b></a>]
                    @endif
                    @if (user_can('funmanage'))
                        - [<a class="altlink" href="fun.php?action=delete&amp;id={{ $funRow->id }}&amp;returnto=index.php"><b>{{ $lang['text_delete'] }}</b></a>]
                        - [<a class="altlink" href="fun.php?action=ban&amp;id={{ $funRow->id }}&amp;returnto=index.php"><b>{{ $lang['text_ban'] }}</b></a>]
                    @endif
                </font>
            @endif
        </h2>
        <table width="100%"><tr><td class="text">
            <iframe src="fun.php?action=view" width='100%' height='300' frameborder='0' name='funbox' marginwidth='0' marginheight='0'></iframe><br /><br />
            @if ($user)
                <span id="funvote">
                    <b>{{ $funbox['funVote'] }}</b>{{ $lang['text_out_of'] }}{{ $funbox['totalVote'] }}{{ $lang['text_people_found_it'] }}
                    @if (!$funbox['funVoted'])
                        <font class="striking">{{ $lang['text_your_opinion'] }}</font>&nbsp;&nbsp;
                        <input type="button" class='btn' name='fun' id='fun' onclick="funvote({{ $funRow->id }},'fun')" value="{{ $lang['submit_fun'] }}" />&nbsp;
                        <input type="button" class='btn' name='dull' id='dull' onclick="funvote({{ $funRow->id }},'dull')" value="{{ $lang['submit_dull'] }}" />
                    @endif
                </span>
                <span id="voteaccept" style="display: none;">{{ $lang['text_vote_accepted'] }}</span>
            @endif
        </td></tr></table>
    @endif
@endif
{{-- ================= end: funbox ================= --}}

{{-- ================= start: shoutbox ================= --}}
@if ($shoutbox)
    <h2>{{ $lang['text_shoutbox'] }} - <font class="small">{{ $lang['text_auto_refresh_after'] }}</font>
        <font class='striking' id="countdown"></font><font class="small">{{ $lang['text_seconds'] }}</font>
        @if ($shoutbox['canClearShoutBox'])
            - <font class="small" id="clear-shout-box">[<a class="altlink" href="javascript:;"><b>{{ $lang['clear_shout_box'] }}</b></a>]</font>
        @endif
    </h2>
    <table width="100%"><tr><td class="text">
        <iframe id='iframe-shout-box' src='shoutbox.php?type=shoutbox' width='100%' height='180' frameborder='0' name='sbox' marginwidth='0' marginheight='0'></iframe><br /><br />
        <form action='shoutbox.php' method='get' target='sbox' name='shbox'>
            <div style="display: flex">
                <label for='shbox_text'>{{ $lang['text_message'] }}</label>
                <input type='text' name='shbox_text' id='shbox_text' size='100' style='flex-grow: 1; border: 1px solid gray;' />  <input type='submit' id='hbsubmit' class='btn' name='shout' value="{{ $lang['sumbit_shout'] }}" />
                @if ($user && $user->hidehb != 'yes' && $shoutbox['showHelpbox'])
                    <input type='submit' class='btn' name='toguest' value="{{ $lang['sumbit_to_guest'] }}" />
                @endif
                <input type='reset' class='btn' value="{{ $lang['submit_clear'] }}" />
                <input type='hidden' name='sent' value='yes' /><input type='hidden' name='type' value='shoutbox' />
            </div>
            @php smile_row("shbox", "shbox_text"); @endphp
        </form>
    </td></tr></table>
    @if ($shoutbox['canClearShoutBox'])
        @push('scripts')
        <script type="text/javascript">
            jQuery('#clear-shout-box').on("click", function () {
                layer.confirm("{{ $lang['sure_to_clear_shout_box'] }}", {title: "Info", btn: ['Yes', "Cancel"], btnAlign: 'c'}, function (layerIndex) {
                    jQuery.post("ajax.php", {"action": "clearShoutBox"}, function (response) {
                        layer.close(layerIndex)
                        if (response.ret != 0) {
                            layer.alert(response.msg, {title: "Info", btn: ['OK', 'Cancel'], btnAlign: 'c'})
                        } else {
                            document.getElementById('iframe-shout-box').src = 'shoutbox.php?type=shoutbox';
                        }
                    }, "json")
                })
            })
        </script>
        @endpush
    @endif
@endif
{{-- ================= end: shoutbox ================= --}}

{{-- plugin / extra modules --}}
{!! implode('', $extraModules) !!}

{{-- ================= start: latest forum posts ================= --}}
@if ($latestForumPosts && $latestForumPosts->isNotEmpty())
    <h2>{{ $lang['text_last_five_posts'] }}</h2>
    <table width="100%" border="1" cellspacing="0" cellpadding="5">
        <tr>
            <td class="colhead" width="100%" align="left">{{ $lang['col_topic_title'] }}</td>
            <td class="colhead" align="center">{{ $lang['col_view'] }}</td>
            <td class="colhead" align="center">{{ $lang['col_author'] }}</td>
            <td class="colhead" align="left">{{ $lang['col_posted_at'] }}</td>
        </tr>
        @foreach ($latestForumPosts as $post)
            <tr>
                <td><a href="forums.php?action=viewtopic&amp;topicid={{ $post->tid }}&amp;page=p{{ $post->pid }}#pid{{ $post->pid }}"><b>{{ htmlspecialchars($post->subject) }}</b></a><br />
                    {{ $lang['text_in'] }}<a href="forums.php?action=viewforum&amp;forumid={{ $post->forumid }}">{{ htmlspecialchars($post->name) }}</a>
                </td>
                <td align="center">{{ $post->views }}</td>
                <td align="center">{!! get_username($post->userpost) !!}</td>
                <td>{!! gettime($post->added) !!}</td>
            </tr>
        @endforeach
    </table>
@endif
{{-- ================= end: latest forum posts ================= --}}

{{-- ================= start: latest torrents ================= --}}
@if ($latestTorrents && $latestTorrents->isNotEmpty())
    <h2>{{ $lang['text_last_five_torrent'] }}</h2>
    <table width="100%" border="1" cellspacing="0" cellpadding="5">
        <tr>
            <td class="colhead" width="100%">{{ $lang['col_name'] }}</td>
            <td class="colhead" align="center">{{ $lang['col_seeder'] }}</td>
            <td class="colhead" align="center">{{ $lang['col_leecher'] }}</td>
        </tr>
        @foreach ($latestTorrents as $torrent)
            <tr>
                <td><a href="details.php?id={{ $torrent->id }}&amp;hit=1"><b>{{ htmlspecialchars($torrent->name) }}</b><br />{{ htmlspecialchars($torrent->small_descr) }}</a></td>
                <td align="center">{{ $torrent->seeders }}</td>
                <td align="center">{{ $torrent->leechers }}</td>
            </tr>
        @endforeach
    </table>
@endif
{{-- ================= end: latest torrents ================= --}}

{{-- ================= start: top uploader ================= --}}
@if ($topUploader)
    @push('styles')
        <style>.tr-top-uploader-tab>td {cursor: pointer}</style>
    @endpush
    <h2>{{ $lang['top_uploader_title'] }}</h2>
    <table width='100%'>
        <tr class='tr-top-uploader-tab' title="{{ $lang['top_uploader_toggle_time_range_tab'] }}">
            <td class='colhead' align='center' data-table='top-uploader-recently'>{{ $lang['top_uploader_toggle_time_range_recently'] }}</td>
            <td align='center' data-table='top-uploader-all'>{{ $lang['top_uploader_toggle_time_range_all'] }}</td>
        </tr>
    </table>
    <table class='top-uploader top-uploader-all' width="100%" border="1" cellspacing="0" cellpadding="5" style='display: none'>
        <tr>
            <td class="colhead" width="">{{ $lang['col_author'] }}</td>
            <td class="colhead" align="center">{{ $lang['col_counts'] }}</td>
            <td class="colhead" align="center">{{ $lang['col_ranking'] }}</td>
        </tr>
        @foreach ($topUploader['all'] as $row)
            <tr>
                <td>{!! $row['username'] !!}</td>
                <td align="center">{{ $row['counts'] }}</td>
                <td align="center">{{ $row['ranking'] }}</td>
            </tr>
        @endforeach
    </table>
    <table class='top-uploader top-uploader-recently' width="100%" border="1" cellspacing="0" cellpadding="5">
        <tr>
            <td class="colhead" width="">{{ $lang['col_author'] }}</td>
            <td class="colhead" align="center">{{ $lang['col_counts'] }}</td>
            <td class="colhead" align="center">{{ $lang['col_ranking'] }}</td>
        </tr>
        @foreach ($topUploader['recently'] as $row)
            <tr>
                <td>{!! $row['username'] !!}</td>
                <td align="center">{{ $row['counts'] }}</td>
                <td align="center">{{ $row['ranking'] }}</td>
            </tr>
        @endforeach
    </table>
    @push('scripts')
        <script type="text/javascript">
            jQuery(".tr-top-uploader-tab").on("click", "td", function () {
                let _this = jQuery(this)
                if (_this.hasClass("colhead")) {
                    return
                }
                _this.parent().children().removeClass("colhead")
                _this.addClass("colhead")
                jQuery(".top-uploader").hide()
                jQuery("." + _this.attr("data-table")).fadeIn()
            })
        </script>
    @endpush
@endif
{{-- ================= end: top uploader ================= --}}

{{-- ================= start: polls ================= --}}
@if ($polls)
    <h2>{{ $lang['text_polls'] }}
        @if (user_can('pollmanage'))
            <font class="small"> - [<a class="altlink" href="makepoll.php?returnto=main"><b>{{ $lang['text_new'] }}</b></a>]
                @if ($polls['exists'])
                    - [<a class="altlink" href="makepoll.php?action=edit&amp;pollid={{ $polls['poll']['id'] }}&amp;returnto=main"><b>{{ $lang['text_edit'] }}</b></a>]
                    - [<a class="altlink" href="log.php?action=poll&amp;do=delete&amp;pollid={{ $polls['poll']['id'] }}&amp;returnto=main"><b>{{ $lang['text_delete'] }}</b></a>]
                    - [<a class="altlink" href="polloverview.php?id={{ $polls['poll']['id'] }}"><b>{{ $lang['text_detail'] }}</b></a>]
                @endif
            </font>
        @endif
    </h2>
    @if ($polls['exists'])
        <table width="100%"><tr><td class="text" align="center">
            <table width="59%" class="main" border="1" cellspacing="0" cellpadding="5"><tr><td class="text" align="left">
                <p align="center"><b>{{ $polls['poll']['question'] }}</b></p>
                @if ($polls['hasVoted'])
                    <table class="main" width="100%" border="0" cellspacing="0" cellpadding="0">
                        @foreach ($polls['results']['rows'] as $i => $result)
                            <tr>
                                <td width="1%" class="embedded nowrap">{{ $result['option'] }}&nbsp;&nbsp;</td>
                                <td width="99%" class="embedded nowrap"><img class="bar_end" src="pic/trans.gif" alt="" />
                                    <img class="{{ $result['index'] == $polls['userVote'] ? 'sltbar' : 'unsltbar' }}" src="pic/trans.gif" style="width: {{ $result['percent'] * 3 }}px;" alt="" />
                                    <img class="bar_end" src="pic/trans.gif" alt="" /> {{ $result['percent'] }}%
                                </td>
                            </tr>
                        @endforeach
                    </table>
                    <p align="center">{{ $lang['text_votes'] }} {{ number_format($polls['results']['totalVotes']) }}</p>
                @else
                    <form method="post" action="index.php">
                        @csrf
                        @foreach ($polls['options'] as $i => $option)
                            <input type="radio" name="choice" value="{{ $i }}">{{ $option }}<br />
                        @endforeach
                        <br />
                        <input type="radio" name="choice" value="255">{{ $lang['radio_blank_vote'] }}<br />
                        <p align="center"><input type="submit" class="btn" value="{{ $lang['submit_vote'] }}" /></p>
                    </form>
                @endif
            </td></tr></table>
            @if ($polls['hasVoted'] && user_can('log'))
                <p align="center"><a href="log.php?action=poll">{{ $lang['text_previous_polls'] }}</a></p>
            @endif
        </td></tr></table>
    @endif
@endif
{{-- ================= end: polls ================= --}}

{{-- ================= start: stats ================= --}}
@if ($stats)
    <h2>{{ $lang['text_tracker_statistics'] }}</h2>
    <table width="100%"><tr><td class="text" align="center">
        <table width="60%" class="main" border="1" cellspacing="0" cellpadding="10">
            <tr>
                @php twotd($lang['row_users_active_today'], number_format($stats['users']['activeToday'])); @endphp
                @php twotd($lang['row_users_active_this_week'], number_format($stats['users']['activeWeek'])); @endphp
            </tr>
            <tr>
                @php twotd($lang['row_registered_users'], number_format($stats['users']['registered']) . ' / ' . number_format($stats['users']['maxusers'])); @endphp
                @php twotd($lang['row_unconfirmed_users'], number_format($stats['users']['unverified'])); @endphp
            </tr>
            <tr>
                @php twotd(get_user_class_name(\App\Models\User::CLASS_VIP, false, false, true), number_format($stats['users']['vip'])); @endphp
                @php twotd($lang['row_donors'] . ' <img class="star" src="pic/trans.gif" alt="Donor" />', number_format($stats['users']['donors'])); @endphp
            </tr>
            <tr>
                @php twotd($lang['row_warned_users'] . ' <img class="warned" src="pic/trans.gif" alt="warned" />', number_format($stats['users']['warned'])); @endphp
                @php twotd($lang['row_banned_users'] . ' <img class="disabled" src="pic/trans.gif" alt="disabled" />', number_format($stats['users']['disabled'])); @endphp
            </tr>
            <tr>
                @php twotd($lang['row_male_users'], number_format($stats['users']['male'])); @endphp
                @php twotd($lang['row_female_users'], number_format($stats['users']['female'])); @endphp
            </tr>
            <tr><td colspan="4" class="rowhead">&nbsp;</td></tr>
            <tr>
                @php twotd($lang['row_torrents'], number_format($stats['torrents']['torrents'])); @endphp
                @php twotd($lang['row_dead_torrents'], number_format($stats['torrents']['dead'])); @endphp
            </tr>
            <tr>
                @php twotd($lang['row_seeders'], number_format($stats['torrents']['seeders'])); @endphp
                @php twotd($lang['row_leechers'], number_format($stats['torrents']['leechers'])); @endphp
            </tr>
            <tr>
                @php twotd($lang['row_peers'], number_format($stats['torrents']['seeders'] + $stats['torrents']['leechers'])); @endphp
                @php twotd($lang['row_seeder_leecher_ratio'], $stats['torrents']['ratio'] . '%'); @endphp
            </tr>
            <tr>
                @php twotd($lang['row_active_browsing_users'], number_format($stats['torrents']['activeBrowsing'])); @endphp
                @php twotd($lang['row_tracker_active_users'], number_format($stats['torrents']['trackerActiveUsers'])); @endphp
            </tr>
            <tr>
                @php twotd($lang['row_total_size_of_torrents'], mksize($stats['torrents']['totalSize'])); @endphp
                @php twotd($lang['row_total_uploaded'], mksize($stats['torrents']['totalUploaded'])); @endphp
            </tr>
            <tr>
                @php twotd($lang['row_total_downloaded'], mksize($stats['torrents']['totalDownloaded'])); @endphp
                @php twotd($lang['row_total_data'], mksize($stats['torrents']['totalUploaded'] + $stats['torrents']['totalDownloaded'])); @endphp
            </tr>
            <tr><td colspan="4" class="rowhead">&nbsp;</td></tr>
            @foreach (collect($stats['classes'])->chunk(2) as $classChunk)
                <tr>
                    @foreach ($classChunk as $classRow)
                        @php
                            $className = get_user_class_name($classRow['class'], false, false, true);
                            if ($classRow['class'] == \App\Models\User::CLASS_PEASANT) {
                                $className .= ' <img class="leechwarned" src="pic/trans.gif" alt="leechwarned" />';
                            }
                        @endphp
                        @php twotd($className, number_format($classRow['num'])); @endphp
                    @endforeach
                </tr>
            @endforeach
        </table>
    </td></tr></table>
@endif
{{-- ================= end: stats ================= --}}

{{-- ================= start: tracker load ================= --}}
@if ($trackerLoad)
    <h2>{{ $lang['text_tracker_load'] }}</h2>
    <table width="100%" border="1" cellspacing="0" cellpadding="10"><tr><td class="text" align="center">
        <div align="center">{{ $trackerLoad }}</div>
    </td></tr></table>
@endif
{{-- ================= end: tracker load ================= --}}

{{-- ================= start: disclaimer ================= --}}
<h2>{{ $lang['text_disclaimer'] }}</h2>
<table width="100%"><tr><td class="text">
    {!! sprintf($lang['text_disclaimer_content'], \App\Models\Setting::getSiteName(), \App\Models\Setting::getSiteName()) !!}
</td></tr></table>
{{-- ================= end: disclaimer ================= --}}

{{-- ================= start: links ================= --}}
<h2>{{ $lang['text_links'] }}
    @if (user_can('applylink'))
        <font class="small"> - [<a class="altlink" href="linksmanage.php?action=apply"><b>{{ $lang['text_apply_for_link'] }}</b></a>]</font>
    @endif
    @if (user_can('linkmanage'))
        <font class="small"> - [<a class="altlink" href="linksmanage.php"><b>{{ $lang['text_manage_links'] }}</b></a>]</font>
    @endif
</h2>
@if ($links && $links->isNotEmpty())
    <table width="100%"><tr><td class="text">
        @foreach ($links as $link)
            <a href="{{ $link->url }}" title="{{ $link->title }}" target="_blank">{{ $link->name }}</a>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
        @endforeach
    </td></tr></table>
@endif
{{-- ================= end: links ================= --}}

{{-- ================= start: browser and code note ================= --}}
<table width="100%" class="main" border="0" cellspacing="0" cellpadding="0"><tr><td class="embedded">
    <div align="center"><br /><font class="medium">{!! $lang['text_browser_note'] !!}</font></div>
    <div align="center"><a href="{{ constant('NEXUSPHPURL') }}" title="{{ constant('PROJECTNAME') }}" target="_blank"><img src="pic/nexus.png" alt="{{ constant('PROJECTNAME') }}" /></a></div>
</td></tr></table>
{{-- ================= end: browser and code note ================= --}}
@endsection