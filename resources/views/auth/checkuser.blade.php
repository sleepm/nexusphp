@extends('layouts.guest')

@php
    $lang = get_legacy_lang_file('checkuser');
    $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
    $operator = $operator ?? auth()->user();
    $gender = '';
    if ($user->gender == 'Male') {
        $gender = '<img class="male" src="pic/trans.gif" alt="Male" title="Male" style="margin-left: 4pt">';
    } elseif ($user->gender == 'Female') {
        $gender = '<img class="female" src="pic/trans.gif" alt="Female" title="Female" style="margin-left: 4pt">';
    } elseif ($user->gender == 'N/A') {
        $gender = '<img class="no_gender" src="pic/trans.gif" alt="N/A" title="No gender" style="margin-left: 4pt">';
    }
    $joindate = ($user->added == '0000-00-00 00:00:00' || $user->added == null)
        ? 'N/A'
        : $user->added . ' (' . get_elapsed_time(strtotime($user->added)) . ' ago)';
@endphp

@section('title', ($lang['head_detail_for'] ?? 'Details for ') . $user->username)

@if ($user->enabled != 'yes')
    {!! $lang['text_account_disabled'] ?? '' !!}
@endif
<table width="737" border="1" cellspacing="0" cellpadding="5">
    <tr><td class="rowhead" width="1%">{{ $lang['row_join_date'] ?? 'Join date' }}</td><td align="left" width="99%">{{ $joindate }}</td></tr>
    <tr><td class="rowhead" width="1%">{{ $lang['row_gender'] ?? 'Gender' }}</td><td align="left" width="99%">{!! $gender !!}</td></tr>
    <tr><td class="rowhead" width="1%">{{ $lang['row_email'] ?? 'E-Mail' }}</td><td align="left" width="99%"><a href="mailto:{{ $user->email }}">{{ $user->email }}</a></td></tr>
    @if (($operator->class ?? '0') >= \App\Models\User::CLASS_MODERATOR && !empty($user->ip))
        <tr><td class="rowhead" width="1%">{{ $lang['row_ip'] ?? 'IP' }}</td><td align="left" width="99%">{{ $user->ip }}</td></tr>
    @endif
</table>
<form method="post" action="{{ url('/takeconfirm.php?id=' . $user->id) }}">
    @csrf
    <input type="hidden" name="email" value="{{ $user->email }}">
    <input type="checkbox" name="conusr[]" value="{{ $user->id }}" checked />
    <input type="submit" value="{{ $lang['submit_confirm_this_user'] ?? 'Confirm this user' }}" />
</form>
