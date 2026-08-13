@extends('layouts.guest')

@section('title', $pageTitle)

@section('content')
<h1>{{ $pageTitle }}</h1>
{{ $lang['text_nfo_for'] }}<a href="details.php?id={{ $torrentId }}">{{ htmlspecialchars($torrentName) }}</a>

<table border="1" cellspacing="0" cellpadding="10" align="center">
    <tr>
        <td align="center" width="50%">
            <a href="viewnfo.php?id={{ $torrentId }}&view=magic" title="{{ $lang['title_dos_vy'] }}"><b>{{ $lang['text_dos_vy'] }}</b></a>
        </td>
        <td align="center" width="50%">
            <a href="viewnfo.php?id={{ $torrentId }}&view=latin-1" title="{{ $lang['title_windows_vy'] }}"><b>{{ $lang['text_windows_vy'] }}</b></a>
        </td>
    </tr>
    <tr>
        <td colspan="3">
            <table border=1 cellspacing=0 cellpadding=5><tr><td class=text>
@if ($view == 'fonthack')
<pre style="font-size:10pt; font-family: 'MS LineDraw', 'Terminal', monospace;white-space: break-spaces">{!! format_urls($nfo) !!}</pre>
@else
<pre style="font-size:10pt; font-family: 'Courier New', monospace;white-space: break-spaces">{!! format_urls($nfo) !!}</pre>
@endif
            </td></tr></table>
        </td>
    </tr>
</table>
@endsection
