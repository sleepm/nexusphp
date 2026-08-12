@extends('layouts.guest')

@section('title', $pageTitle)

@section('content')
<h1>{{ $heading }}</h1>

@if ($paginator->total() > $paginator->perPage())
    @include('partials.pagination', ['paginator' => $paginator, 'position' => 'top'])
@endif

<table class="main" width="100%" border="0" cellspacing="0" cellpadding="0">
    <tr><td class="embedded">
        <table width="100%" border="1" cellspacing="0" cellpadding="10">
            <tr><td class="text">
                @if ($action == 'viewposts')
                    @foreach ($rows as $row)
                        <p class="sub"><table border="0" cellspacing="0" cellpadding="0"><tr><td class="embedded">
                            {!! $row['added'] !!}&nbsp;--&nbsp;{!! $lang['text_forum'] !!}
                            <a href="forums.php?action=viewforum&forumid={{ $row['forum_id'] }}">{{ $row['forum_name'] }}</a>
                            &nbsp;--&nbsp;{!! $lang['text_topic'] !!}
                            <a href="forums.php?action=viewtopic&topicid={{ $row['topic_id'] }}">{{ $row['topic_name'] }}</a>
                            &nbsp;--&nbsp;{!! $lang['text_post'] !!}
                            <a href="forums.php?action=viewtopic&topicid={{ $row['topic_id'] }}&page=p{{ $row['id'] }}#pid{{ $row['id'] }}">#{{ $row['id'] }}</a>
                            @if ($row['new']) &nbsp;<b>(<font class="new">{!! $lang['text_new'] !!}</font>)</b>@endif
                        </td></tr></table></p>
                        <br />
                        <table class="main" width="100%" border="1" cellspacing="0" cellpadding="5">
                            <tr valign="top"><td class="comment">{!! $row['body'] !!}</td></tr>
                        </table>
                        <br />
                    @endforeach
                @else
                    @foreach ($rows as $row)
                        <p class="sub"><table border="0" cellspacing="0" cellpadding="0"><tr><td class="embedded">
                            {!! $row['added'] !!}&nbsp;---&nbsp;{!! $lang['text_torrent'] !!}
                            @if ($row['torrent_name'] !== null)
                                <a href="details.php?id={{ $row['torrent_id'] }}&tocomm=1&hit=1">{{ $row['torrent_name'] }}</a>
                            @else
                                [Deleted]
                            @endif
                            &nbsp;---&nbsp;{!! $lang['text_comment'] !!}</b>#<a href="details.php?id={{ $row['torrent_id'] }}&tocomm=1&hit=1{{ $row['comment_page'] ? '&page=' . $row['comment_page'] : '' }}">{{ $row['id'] }}</a>
                        </td></tr></table></p>
                        <br />
                        <table class="main" width="100%" border="1" cellspacing="0" cellpadding="5">
                            <tr valign="top"><td class="comment">{!! $row['body'] !!}</td></tr>
                        </table>
                        <br />
                    @endforeach
                @endif
            </td></tr>
        </table>
    </td></tr>
</table>

@if ($paginator->total() > $paginator->perPage())
    @include('partials.pagination', ['paginator' => $paginator, 'position' => 'bottom'])
@endif
@endsection