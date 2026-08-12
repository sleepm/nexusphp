@extends('layouts.guest')

@section('title', $pageTitle)

@push('scripts')
    <script type="text/javascript">
        jQuery("#reset").on('click', function () {
            jQuery("select[name=sort]").val('')
            jQuery("select[name=order]").val('')
        })
    </script>
@endpush

@section('content')
<h1 align="center">{!! $pageTitle !!}
@if (!empty($torrent))
    <a href="details.php?id={{ htmlspecialchars($torrent->id) }}"><b>&nbsp;{{ htmlspecialchars($torrent->name) }}</b></a>
@else
    <a href="userdetails.php?id={{ htmlspecialchars($user->id) }}"><b>&nbsp;{{ htmlspecialchars($user->username) }}</b></a>
@endif
</h1>

<div>
    <form action="{{ $request->fullUrl() }}" method="get">
        <input type="hidden" name="uid" value="{{ $uid }}" />
        <input type="hidden" name="torrent_id" value="{{ $torrentId }}" />
        <span>{{ nexus_trans('nexus.sort') }}:</span>
        <select name="sort">
            <option value="">-{{ nexus_trans('nexus.select_one_please') }}-</option>
            @foreach ($sortOptions as $name => $text)
                <option value="{{ $name }}"{{ $sort == $name ? ' selected' : '' }}>{{ $text }}</option>
            @endforeach
        </select>
        &nbsp;&nbsp;
        <span>{{ nexus_trans('nexus.order') }}:</span>
        <select name="order">
            <option value="">-{{ nexus_trans('nexus.select_one_please') }}-</option>
            @foreach ($orderOptions as $name => $text)
                <option value="{{ $name }}"{{ $order == $name ? ' selected' : '' }}>{{ $text }}</option>
            @endforeach
        </select>
        &nbsp;&nbsp;
        <input type="submit" value="{{ nexus_trans('label.submit') }}">
        <input type="button" id="reset" value="{{ nexus_trans('label.reset') }}">
    </form>
</div>

<table id="claim-table" width="100%" cellpadding="5">
    <tr>
        <td class="colhead" align="center">{{ nexus_trans('claim.th_id') }}</td>
        <td class="colhead" align="center">{{ nexus_trans('claim.th_username') }}</td>
        <td class="colhead" align="center">{{ nexus_trans('claim.th_torrent_name') }}</td>
        <td class="colhead" align="center">{{ nexus_trans('claim.th_torrent_size') }}</td>
        <td class="colhead" align="center">{{ nexus_trans('claim.th_torrent_ttl') }}</td>
        <td class="colhead" align="center">{{ nexus_trans('claim.th_claim_at') }}</td>
        <td class="colhead" align="center">{{ nexus_trans('claim.th_last_settle') }}</td>
        <td class="colhead" align="center">{{ nexus_trans('claim.th_seed_time_this_month') }}</td>
        <td class="colhead" align="center">{{ nexus_trans('claim.th_uploaded_this_month') }}</td>
        <td class="colhead" align="center">{{ nexus_trans('claim.th_reached_or_not') }}</td>
        @if ($showAction)
            <td class="colhead" align="center">{{ nexus_trans('claim.th_action') }}</td>
        @endif
    </tr>
    @foreach ($rows as $row)
        <tr>
            <td class="rowfollow nowrap" align="center">{{ $row['id'] }}</td>
            <td class="rowfollow" align="left"><a href="userdetails.php?id={{ $row['uid'] }}">{{ $row['username'] }}</a></td>
            <td class="rowfollow" align="left"><a href="details.php?id={{ $row['torrent_id'] }}">{!! $row['torrent_name'] !!}</a></td>
            <td class="rowfollow nowrap" align="center">{{ $row['torrent_size'] }}</td>
            <td class="rowfollow nowrap" align="center">{{ $row['torrent_ttl'] }}</td>
            <td class="rowfollow nowrap" align="center">{{ $row['created_at'] }}</td>
            <td class="rowfollow nowrap" align="center">{{ $row['last_settle_at'] }}</td>
            <td class="rowfollow nowrap" align="center">{{ $row['seed_time_this_month'] }}</td>
            <td class="rowfollow nowrap" align="center">{{ $row['uploaded_this_month'] }}</td>
            <td class="rowfollow nowrap" align="center">{{ $row['reached'] }}</td>
            @if ($showAction)
                <td class="rowfollow nowrap" align="center">{!! $row['action_buttons'] !!}</td>
            @endif
        </tr>
    @endforeach
</table>

@include('partials.pagination', ['paginator' => $paginator])
@endsection