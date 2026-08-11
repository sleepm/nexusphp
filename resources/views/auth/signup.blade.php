@extends('layouts.guest')

@php
    $lang = get_legacy_lang_file('signup');
    $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
    $ivEnabled = get_setting('security.iv') == 'yes';
    $showschool = get_setting('main.enableschool') == 'yes';
    $restrictemaildomain = get_setting('main.restrictemail') == 'yes';
    $isPreRegisterEmailAndUsername = get_setting("system.is_invite_pre_email_and_username") == "yes";
    $currentLangFolder = get_langfolder_cookie();
    $languages = langlist('site_lang', true);
    $countries = \Illuminate\Support\Facades\DB::table('countries')->orderBy('name')->get(['id', 'name']);
    $schools = $showschool ? \Illuminate\Support\Facades\DB::table('schools')->orderBy('name')->get(['id', 'name']) : collect();
    $inviter = $type == 'invite' ? htmlspecialchars($inv->inviter ?? '') : '';
    $code = $type == 'invite' ? ($inv->hash ?? ($request->query('invitenumber', ''))) : '';
    $formInputStyle = 'style="width: min(100%, 320px); min-width: 180px; border: 1px solid gray; box-sizing: border-box"';
@endphp

@section('title', $type == 'invite' ? $lang['head_invite_signup'] : $lang['head_signup'])

@push('scripts')
    <script type="text/javascript" src="js/crypto-js.js"></script>
    <script type="text/javascript">
        var jqSignupForm = jQuery("#signup-form");
        jqSignupForm.on("click", "input[type=button]", function () {
            let jqUsername = jqSignupForm.find("[name=wantusername]")
            let jqPassword = jqSignupForm.find(".wantpassword")
            let jqPasswordConfirm = jqSignupForm.find(".passagain")
            let password = jqPassword.val()
            let tipTooShort = '{{ nexus_trans('signup.password_too_short') }}'
            let tipTooLong = '{{ nexus_trans('signup.password_too_long') }}'
            let tipEqualUsername = '{{ nexus_trans('signup.password_equals_username') }}'
            let tipNotMatch = '{{ nexus_trans('signup.passwords_unmatched') }}'
            if (password.length < 6) {
                layer.alert(tipTooShort)
                return
            }
            if (password.length > 40) {
                layer.alert(tipTooLong)
                return
            }
            if (jqUsername.length > 0 && jqUsername.val() === password) {
                layer.alert(tipEqualUsername)
                return
            }
            if (jqPasswordConfirm.length > 0 && password !== jqPasswordConfirm.val()) {
                layer.alert(tipNotMatch)
                return
            }
            if (password !== "") {
                const passwordHashed = sha256(password)
                jqSignupForm.find("input[name=wantpassword]").val(passwordHashed)
                jqSignupForm.submit()
            } else {
                jqSignupForm.submit()
            }
        })
    </script>
@endpush

@if (session('error'))
    <table align="center" class="main" width="500" border="0" cellpadding="0" cellspacing="0">
        <tr><td class="embedded">
                <h2>{{ $lang['std_signup_failed'] ?? $lang['std_error'] }}</h2>
                <table width="100%" border="1" cellspacing="0" cellpadding="10"><tr><td class="text">{!! session('error') !!}</td></tr></table>
        </td></tr>
    </table>
@endif

<form method="get" action="{{ url('/signup.php') }}">
    @if ($type == 'invite')
        <input type="hidden" name="type" value="invite">
        <input type="hidden" name="invitenumber" value="{{ $code }}">
    @endif
    <div align="right" valign="top">{{ $lang['text_select_lang'] }}
        <select name="sitelanguage" onchange="submit()">
            @foreach ($languages as $row)
                <option value="{{ $row['id'] }}" @if($row['site_lang_folder'] == $currentLangFolder) selected @endif>{{ htmlspecialchars($row['lang_name']) }}</option>
            @endforeach
        </select>
    </div>
</form>

<p>
<form method="post" action="{{ url('/takesignup.php') }}" id="signup-form">
    @csrf
    @if ($type == 'invite')
        <input type="hidden" name="inviter" value="{{ $inviter }}">
        <input type="hidden" name="type" value="invite">
    @endif
    <table border="1" cellspacing="0" cellpadding="10">
        <tr><td class="text" align="center" colspan="2">{!! $lang['text_cookies_note'] !!}</td></tr>
        <tr>
            <td class="rowhead">{{ $lang['row_desired_username'] }}</td>
            <td class="rowfollow" align="left">
                @if ($isPreRegisterEmailAndUsername && $type == 'invite' && !empty($inv->pre_register_username))
                    <input type="text" {!! $formInputStyle !!} name="wantusername" value="{{ htmlspecialchars($inv->pre_register_username, ENT_QUOTES) }}" readonly autocomplete="username" />
                @else
                    <input type="text" {!! $formInputStyle !!} name="wantusername" autocomplete="username" />
                @endif
                <br /><font class="small">{{ $lang['text_allowed_characters'] }}</font>
            </td>
        </tr>
        <tr>
            <td class="rowhead">{{ $lang['row_pick_a_password'] }}</td>
            <td class="rowfollow" align="left">
                <input type="password" {!! $formInputStyle !!} class="wantpassword" autocomplete="new-password" />
                <br /><font class="small">{{ $lang['text_minimum_six_characters'] }}</font>
            </td>
        </tr>
        <tr>
            <td class="rowhead">{{ $lang['row_enter_password_again'] }}</td>
            <td class="rowfollow" align="left"><input type="password" {!! $formInputStyle !!} class="passagain" autocomplete="new-password" /></td>
        </tr>
        @if ($ivEnabled)
            {!! image_code_markup() !!}
        @endif
        <tr>
            <td class="rowhead">{{ $lang['row_email_address'] }}</td>
            <td class="rowfollow" align="left">
                @if ($isPreRegisterEmailAndUsername && $type == 'invite' && !empty($inv->pre_register_email))
                    <input type="email" {!! $formInputStyle !!} name="email" value="{{ htmlspecialchars($inv->pre_register_email, ENT_QUOTES) }}" readonly autocomplete="email" />
                @else
                    <input type="email" {!! $formInputStyle !!} name="email" autocomplete="email" />
                @endif
                @if ($restrictemaildomain)
                    <table width="250" border="0" cellspacing="0" cellpadding="0"><tr><td class="embedded"><font class="small">{{ $lang['text_email_note'] }}{{ allowedemails() }}</font></td></tr></table>
                @endif
            </td>
        </tr>
        <tr>
            <td class="rowhead">{{ $lang['row_country'] }}</td>
            <td class="rowfollow" align="left">
                <select name="country">
                    <option value="8">---- {{ $lang['select_none_selected'] }} ----</option>
                    @foreach ($countries as $country)
                        <option value="{{ $country->id }}" @if($country->id == 8) selected @endif>{{ $country->name }}</option>
                    @endforeach
                </select>
            </td>
        </tr>
        @if ($showschool)
            <tr>
                <td class="rowhead">{{ $lang['row_school'] }}</td>
                <td class="rowfollow" align="left">
                    <select name="school">
                        <option value="35">---- {{ $lang['select_none_selected'] }} ----</option>
                        @foreach ($schools as $school)
                            <option value="{{ $school->id }}" @if($school->id == 35) selected @endif>{{ $school->name }}</option>
                        @endforeach
                    </select>
                </td>
            </tr>
        @endif
        <tr>
            <td class="rowhead">{{ $lang['row_gender'] }}</td>
            <td class="rowfollow" align="left">
                <input type="radio" name="gender" value="Male">{{ $lang['radio_male'] }}
                <input type="radio" name="gender" value="Female">{{ $lang['radio_female'] }}
            </td>
        </tr>
        <tr>
            <td class="rowhead">{{ $lang['row_verification'] }}</td>
            <td class="rowfollow" align="left">
                <input type="checkbox" name="rulesverify" value="yes">{!! $lang['checkbox_read_rules'] !!}<br />
                <input type="checkbox" name="faqverify" value="yes">{!! $lang['checkbox_read_faq'] !!}<br />
                <input type="checkbox" name="ageverify" value="yes">{!! $lang['checkbox_age'] !!}
            </td>
        </tr>
        <input type="hidden" name="hash" value="{{ $code }}">
        <input type="hidden" name="wantpassword" />
        <tr>
            <td class="toolbox" colspan="2" align="center">
                <font color="red"><b>{!! $lang['text_all_fields_required'] !!}</b><p></font>
                <input id="submit-btn" type="button" value="{!! $lang['submit_sign_up'] !!}" style="height: 25px">
            </td>
        </tr>
    </table>
</form>
</p>
