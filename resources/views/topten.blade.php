@extends('layouts.guest')

@section('title', $pageTitle)

@section('content')
<h1>{{ $pageTitle }}</h1>

<p align="center">
    @foreach ([1 => $lang['text_users'], 2 => $lang['text_torrents'], 3 => $lang['text_countries'], 5 => $lang['text_community'], 6 => $lang['text_other']] as $tabType => $tabText)
        @if ($type == $tabType)
            <b>{{ $tabText }}</b>
        @else
            <a href="topten.php?type={{ $tabType }}">{{ $tabText }}</a>
        @endif
        @if (!$loop->last) | @endif
    @endforeach
</p>

<table class="main" width="100%" border="0" cellspacing="0" cellpadding="0">
    <tr><td class="embedded">
        @foreach ($tables as $table)
            <h2 align="left">{!! $table['caption'] !!}</h2>
            <table width="100%" border="1" cellspacing="0" cellpadding="10">
                <tr><td class="text" align="center">
                    <table class="main" border="1" cellspacing="0" cellpadding="5">
                        <tr>
                            @foreach ($table['headers'] as $header)
                                <td class="colhead"@if (!empty($header['align'])) align="{{ $header['align'] }}"@endif>{!! $header['label'] !!}</td>
                            @endforeach
                        </tr>
                        @foreach ($table['rows'] as $row)
                            <tr>
                                @foreach ($row as $cell)
                                    <td class="rowfollow" align="{{ $cell['align'] }}">{!! $cell['html'] !!}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </table>
                </td></tr>
            </table>
        @endforeach
    </td></tr>
</table>

<p><font class="small">{{ $lang['text_this_page_last_updated'] }}{{ $lastUpdated }}, {{ $lang['text_started_recording_date'] }}{{ $recordStart }}{{ $lang['text_update_interval'] }}</font></p>
@endsection