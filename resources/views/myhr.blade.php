@extends('layouts.guest')

@section('title', $pageTitle)

@if ($hasActionRemove)
@push('scripts')
    <script type="text/javascript">
        jQuery('#hr-table').on('click', '.remove-hr', function () {
            var id = jQuery(this).attr('data-id')
            layer.confirm('{!! $removeConfirmMsg !!}', function (index) {
                jQuery.post('ajax.php', {"action": "removeHitAndRun", "params": {"id": id}}, function (response) {
                    if (response.ret != 0) {
                        layer.alert(response.msg)
                        return
                    }
                    window.location.reload()
                }, 'json')
            })
        })
    </script>
@endpush
@endif

@section('content')
<h1>{{ $pageTitle }}</h1>

<p>
    @foreach ($allStatus as $key => $value)
        @php
            $filterParams = $pagerParams;
            $filterParams['status'] = $key;
        @endphp
        <a href="?{{ http_build_query($filterParams) }}" class="{{ $key == $status ? 'faqlink' : '' }}"><b>{{ $value['text'] }}</b></a>
        @if (!$loop->last) | @endif
    @endforeach
</p>

<form id="filterForm" action="{{ $request->url() }}" method="get">
    <input id="q" type="text" name="q" value="{{ $q }}" placeholder="{{ $lang['th_hr_id'] }}">
    <input type="submit">
    <input type="reset" onclick="document.getElementById('q').value='';document.getElementById('filterForm').submit();">
</form>

<table width="100%" id="hr-table">
    <tr>
        <td class="colhead" align="center">{{ $lang['th_hr_id'] }}</td>
        <td class="colhead" align="center">{{ $lang['th_torrent_name'] }}</td>
        <td class="colhead" align="center">{{ $lang['th_uploaded'] }}</td>
        <td class="colhead" align="center">{{ $lang['th_downloaded'] }}</td>
        <td class="colhead" align="center">{{ $lang['th_share_ratio'] }}</td>
        <td class="colhead" align="center">{{ $lang['th_seed_time_required'] }}</td>
        <td class="colhead" align="center">{{ $lang['th_completed_at'] }}</td>
        <td class="colhead" align="center">{{ $lang['th_ttl'] }}</td>
        <td class="colhead" align="center">{{ $lang['th_comment'] }}</td>
        <td class="colhead" align="center">{{ $langFunctions['std_action'] }}</td>
    </tr>
    @foreach ($rows as $row)
        <tr>
            <td class="rowfollow nowrap" align="center">{{ $row['id'] }}</td>
            <td class="rowfollow" align="left"><a href="details.php?id={{ $row['torrent_id'] }}">{{ $row['torrent_name'] }}</a></td>
            <td class="rowfollow nowrap" align="center">{{ $row['uploaded'] }}</td>
            <td class="rowfollow nowrap" align="center">{{ $row['downloaded'] }}</td>
            <td class="rowfollow nowrap" align="center">{!! $row['share_ratio'] !!}</td>
            <td class="rowfollow nowrap" align="center">{{ $row['seed_time_required'] }}</td>
            <td class="rowfollow nowrap" align="center">{{ $row['completed_at'] }}</td>
            <td class="rowfollow nowrap" align="center">{{ $row['inspect_time_left'] }}</td>
            <td class="rowfollow nowrap" align="left" style="padding-left: 10px">{!! $row['comment'] !!}</td>
            <td class="rowfollow nowrap" align="center">
                @if ($row['can_remove'])
                    <input class="remove-hr" type="button" value="{{ $lang['action_remove'] }}" data-id="{{ $row['id'] }}">
                @endif
            </td>
        </tr>
    @endforeach
</table>

@include('partials.pagination', ['paginator' => $paginator])
@endsection