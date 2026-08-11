@extends('layouts.guest')

@php
    $title = nexus_trans('self-enable.title');
    $elapsedDay = $elapsedDay ?? 0;
    $total = $total ?? 0;
    $bonusEnough = $bonusEnough ?? false;
@endphp

@section('title', $title)

@if (session('error'))
    <table align="center" class="main" width="500" border="0" cellpadding="0" cellspacing="0">
        <tr><td class="embedded">
                <h2>Error</h2>
                <table width="100%" border="1" cellspacing="0" cellpadding="10"><tr><td class="text">{!! session('error') !!}</td></tr></table>
        </td></tr>
    </table>
@endif

@if ($unit <= 0)
    <h3>{{ nexus_trans('self-enable.feature_disabled') }}</h3>
@elseif ($enabledNormal)
    <h3>{{ nexus_trans('self-enable.enable_status_normal') }}</h3>
@elseif (!$latestBanLog)
    <h3>{{ nexus_trans('self-enable.no_ban_info') }}</h3>
@else
    <h3>{{ nexus_trans('self-enable.latest_ban_info') }}</h3>
    <table id="ban-info" border="1" cellpadding="5" cellspacing="0">
        <tr><th>UID：</th><td>{{ $latestBanLog->uid }}</td></tr>
        <tr><th>Username：</th><td>{{ $latestBanLog->username }}</td></tr>
        <tr><th>Reason：</th><td>{{ $latestBanLog->reason }}</td></tr>
        <tr><th>CreatedAt：</th><td>{{ $latestBanLog->created_at }}</td></tr>
    </table>
    <p>{{ nexus_trans('self-enable.deduct_bonus_per_day', ['unit' => number_format($unit)]) }}</p>
    <p>{{ nexus_trans('self-enable.deduct_bonus_total', ['days' => number_format($elapsedDay), 'total' => number_format($total)]) }}</p>
    @if ($bonusEnough)
        <p>{{ nexus_trans('self-enable.enable_desc') }}</p>
        <form method="post" action="{{ url('/self-enable.php') }}">
            @csrf
            <input type="submit" value="{{ nexus_trans('self-enable.enable_button') }}">
        </form>
    @else
        <p>{{ nexus_trans('self-enable.bonus_not_enough', ['bonus' => $user->seedbonus]) }}</p>
    @endif
@endif
