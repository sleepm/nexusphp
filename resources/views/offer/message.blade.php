@extends('layouts.guest')

@section('title', $pageTitle)

@section('content')
<table align="center" class="main" width="500" border="0" cellpadding="0" cellspacing="0"><tr><td class="embedded">
@if ($heading)
<h2>{!! $heading !!}</h2>
@endif
<table width="100%" border="1" cellspacing="0" cellpadding="10"><tr><td class="text">{!! $message !!}</td></tr></table>
</td></tr></table>
@endsection
