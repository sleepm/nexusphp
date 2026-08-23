@extends('layouts.guest')

@section('title', $pageTitle)

@section('content')
@if (!empty($heading))
<h2>{!! $heading !!}</h2>
@endif
{!! $message !!}
@endsection
