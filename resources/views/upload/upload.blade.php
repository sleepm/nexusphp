@extends('layouts.guest')

@section('title', $pageTitle)

@section('content')
{!! $content !!}
@endsection

@push('styles')
{!! $headerAssets !!}
@endpush

@push('scripts')
@foreach ($externalScripts as $src)
<script type="text/javascript" src="{{ $src }}"></script>
@endforeach
@foreach ($scripts as $js)
<script type="text/javascript">{!! $js !!}</script>
@endforeach
{!! $footerAssets !!}
@endpush