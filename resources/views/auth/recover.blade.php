@extends('layouts.guest')

@php
    $lang = get_legacy_lang_file('recover');
    $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
    $ivEnabled = get_setting('security.iv') == 'yes';
    $maxloginattempts = (int) get_setting('security.maxloginattempts', 10);
    $currentLangFolder = get_langfolder_cookie();
    $languages = langlist('site_lang');
    $formInputStyle = 'style="width: min(100%, 320px); min-width: 180px; border: 1px solid gray; box-sizing: border-box"';
@endphp

@section('title', $lang['text_recover_user'])

@if (session('error'))
    <table align="center" class="main" width="500" border="0" cellpadding="0" cellspacing="0">
        <tr><td class="embedded">
                <h2>{{ $lang['std_recover_failed'] }}</h2>
                <table width="100%" border="1" cellspacing="0" cellpadding="10"><tr><td class="text">{!! session('error') !!}</td></tr></table>
        </td></tr>
    </table>
@elseif (session('notice'))
    <table align="center" class="main" width="500" border="0" cellpadding="0" cellspacing="0">
        <tr><td class="embedded">
                <h2>{{ $lang['std_recover_failed'] }}</h2>
                <table width="100%" border="1" cellspacing="0" cellpadding="10"><tr><td class="text">{!! session('notice') !!}</td></tr></table>
        </td></tr>
    </table>
@endif

<form method="get" action="{{ url('/recover.php') }}">
    <div align="right">{{ $lang['text_select_lang'] }}
        <select name="sitelanguage" onchange="submit()">
            @foreach ($languages as $row)
                <option value="{{ $row['id'] }}" @if($row['site_lang_folder'] == $currentLangFolder) selected="selected" @endif>{{ htmlspecialchars($row['lang_name']) }}</option>
            @endforeach
        </select>
    </div>
</form>
<h1>{{ $lang['text_recover_user'] }}</h1>
<p>{{ $lang['text_use_form_below'] }}</p>
<p>{{ $lang['text_reply_to_confirmation_email'] }}</p>
<p><b>{{ $lang['text_note'] }}{{ $maxloginattempts }}</b>{{ $lang['text_ban_ip'] }}</p>
<p>{{ $lang['text_you_have'] }}<b>{!! (new \App\Repositories\LoginAttemptRepository())->getRemainingMarkup() !!}</b>{{ $lang['text_remaining_tries'] }}</p>
<form method="post" action="{{ url('/recover.php') }}">
    @csrf
    <table border="1" cellspacing="0" cellpadding="10">
        <tr>
            <td class="rowhead">{{ $lang['row_registered_email'] }}</td>
            <td class="rowfollow"><input type="email" {!! $formInputStyle !!} name="email" autocomplete="email" /></td>
        </tr>
        @if ($ivEnabled)
            {!! image_code_markup() !!}
        @endif
        <tr>
            <td class="toolbox" colspan="2" align="center"><input type="submit" value="{{ $lang['submit_recover_it'] }}" class="btn" /></td>
        </tr>
    </table>
</form>
