@extends('layouts.guest')

@section('title', $pageTitle)

@push('styles')
<style type="text/css">
/* list styles */
ul ul { margin-left: 15px; }
ul, li { padding: 0px; margin: 0px; list-style-type: none; color: #000; font-weight: normal;}
ul a, li a { color: #009; text-decoration: none; font-weight: normal; }
li { display: inline; } /* fix for IE blank line bug */
ul > li { display: list-item; }

li div.string  {padding: 3px;}
li div.integer {padding: 3px;}
li div.dictionary {padding: 3px;}
li div.list {padding: 3px;}
li div.string span.icon {color:#090;padding: 2px;}
li div.integer span.icon {color:#990;padding: 2px;}
li div.dictionary span.icon {color:#909;padding: 2px;}
li div.list span.icon {color:#009;padding: 2px;}

li span.title {font-weight: bold;}
</style>
@endpush

@section('content')
<div align="center"><h1>{{ htmlspecialchars($torrentName) }}</h1></div>
<table width="750" border="1" cellspacing="0" cellpadding="5"><tr><td>
<ul id="torrent-structure">
{!! $structure !!}
</ul>
</td></tr></table>
@endsection
