@extends('layouts.guest')

@section('title', $pageTitle)

@section('content')
<h1 align="center">{{ $lang['text_snatch_detail_for'] }}<a href="details.php?id={{ $torrentId }}"><b>{{ htmlspecialchars($torrentName) }}</b></a></h1>
@if ($hasRows)
    <p align="center">{{ $lang['text_users_top_finished_recently'] }}</p>
    @if ($paginator->lastPage() > 1)
        @include('partials.pagination', ['paginator' => $paginator, 'position' => 'top'])
    @endif
    <table border=1 cellspacing=0 cellpadding=5 align=center width=940>
        <tr>
            <td class="colhead" align="center">{{ $lang['col_username'] }}</td>
            @if ($showIp)
                <td class="colhead" align="center">{{ $lang['col_ip'] }}</td>
            @endif
            <td class="colhead" align="center">{{ $lang['col_uploaded'] }}/{{ $lang['col_downloaded'] }}</td>
            <td class="colhead" align="center">{{ $lang['col_ratio'] }}</td>
            <td class="colhead" align="center">{{ $lang['col_se_time'] }}</td>
            <td class="colhead" align="center">{{ $lang['col_le_time'] }}</td>
            <td class="colhead" align="center">{{ $lang['col_when_completed'] }}</td>
            <td class="colhead" align="center">{{ $lang['col_last_action'] }}</td>
            <td class="colhead" align="center">{{ $lang['col_report_user'] }}</td>
        </tr>
        @foreach ($rows as $row)
        <tr{{ $row['highlight'] }}>
            <td class="rowfollow" align="center">{!! $row['username'] !!}</td>
            @if ($showIp)
                <td class="rowfollow" align="center">{!! $row['ip'] !!}</td>
            @endif
            <td class="rowfollow" align="center">{!! $row['uploaded'] !!}</td>
            <td class="rowfollow" align="center">{!! $row['ratio'] !!}</td>
            <td class="rowfollow" align="center">{!! $row['seedtime'] !!}</td>
            <td class="rowfollow" align="center">{!! $row['leechtime'] !!}</td>
            <td class="rowfollow" align="center">{!! $row['completedat'] !!}</td>
            <td class="rowfollow" align="center">{!! $row['last_action'] !!}</td>
            <td class="rowfollow" align="center" style="padding: 0px">
                @if ($row['can_report'])
                    <a href="report.php?user={{ $row['userid'] }}">{!! $row['report_image'] !!}</a>
                @else
                    {!! $row['report_image'] !!}
                @endif
            </td>
        </tr>
        @endforeach
    </table>
    @if ($paginator->lastPage() > 1)
        @include('partials.pagination', ['paginator' => $paginator, 'position' => 'bottom'])
    @endif
@else
    <table align="center" class="main" width="500" border="0" cellpadding="0" cellspacing="0"><tr><td class="embedded">
        <h2>{{ $lang['std_sorry'] }}</h2>
        <table width="100%" border="1" cellspacing="0" cellpadding="10"><tr><td class="text">{{ $lang['std_no_snatched_users'] ?? $lang['text_no_snatched_users'] ?? 'No user has snatched this torrent yet.' }}</td></tr></table>
    </td></tr></table>
@endif
@endsection
