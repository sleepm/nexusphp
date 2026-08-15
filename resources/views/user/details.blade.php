@extends('layouts.guest')

@section('title', $pageTitle)

@section('content')
{!! $content !!}
@endsection

@push('scripts')
@foreach ($scripts as $js)
<script type="text/javascript">{!! $js !!}</script>
@endforeach
@endpush