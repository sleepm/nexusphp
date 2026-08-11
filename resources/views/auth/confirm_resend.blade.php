@extends('layouts.guest')

@php
    $lang = get_legacy_lang_file('confirm_resend');
    $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
    $ivEnabled = get_setting('security.iv') == 'yes';
    $maxloginattempts = (int) get_setting('security.maxloginattempts', 10);
    $currentLangFolder = get_langfolder_cookie();
    $languages = langlist('site_lang');
    $errorMsg = session('error') ?: ($error ?? '');
    $formInputStyle = 'style="width: min(100%, 320px); min-width: 180px; border: 1px solid gray; box-sizing: border-box"';
@endphp

@section('title', $lang['resend_confirmation_email_failed'] ?? 'Send confirmation e-mail failed')

@if ($errorMsg)
    <table align="center" class="main" width="500" border="0" cellpadding="0" cellspacing="0">
        <tr><td class="embedded">
                <h2>{{ $lang['resend_confirmation_email_failed'] ?? 'Send confirmation e-mail failed' }}</h2>
                <table width="100%" border="1" cellspacing="0" cellpadding="10"><tr><td class="text">{!! $errorMsg !!}</td></tr></table>
        </td></tr>
    </table>
@endif

<form method="get" action="{{ url('/confirm_resend.php') }}">
    <div align="right">{{ $lang['text_select_lang'] }}
        <select name="sitelanguage" onchange="submit()">
            @foreach ($languages as $row)
                <option value="{{ $row['id'] }}" @if($row['site_lang_folder'] == $currentLangFolder) selected="selected" @endif>{{ htmlspecialchars($row['lang_name']) }}</option>
            @endforeach
        </select>
    </div>
</form>
{!! sprintf($lang['text_resend_confirmation_mail_note'] ?? '', $maxloginattempts) !!}
<p>{{ $lang['text_you_have'] }}<b>{!! (new \App\Repositories\LoginAttemptRepository())->getRemainingMarkup() !!}</b>{{ $lang['text_remaining_tries'] }}</p>
<form method="post" action="{{ url('/confirm_resend.php') }}">
    @csrf
    <table border="1" cellspacing="0" cellpadding="10" style="width: min(100%, 420px);">
        <tr>
            <td class="rowhead nowrap">{{ $lang['row_registered_email'] }}</td>
            <td class="rowfollow"><input type="email" name="email" autocomplete="email" {!! $formInputStyle !!} /></td>
        </tr>
        <tr>
            <td class="rowhead nowrap">{{ $lang['row_new_password'] }}</td>
            <td class="rowfollow" align="left">
                <input type="password" name="wantpassword" autocomplete="new-password" {!! $formInputStyle !!} /><br />
                <font class="small">{{ $lang['text_password_note'] }}</font>
            </td>
        </tr>
        <tr>
            <td class="rowhead nowrap">{{ $lang['row_enter_password_again'] }}</td>
            <td class="rowfollow" align="left"><input type="password" name="passagain" autocomplete="new-password" {!! $formInputStyle !!} /></td>
        </tr>
        @if ($ivEnabled)
            {!! image_code_markup() !!}
        @endif
        <tr>
            <td class="toolbox" colspan="2" align="center"><input type="submit" class="btn" value="{{ $lang['submit_send_it'] }}" /></td>
        </tr>
    </table>
</form>
