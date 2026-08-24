@extends('layouts.guest')

@php
    $lang = get_legacy_lang_file('login');
    $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
    $useChallengeResponse = \App\Models\Setting::getIsUseChallengeResponseAuthentication();
    $ivEnabled = get_setting('security.iv') == 'yes';
    $smtpType = get_setting('smtp.smtptype');
    $showHelpbox = get_setting('main.showhelpbox') != 'no';
    $baseUrl = \App\Models\Setting::getBaseUrl();
    $returnto = $request->query('returnto', '');
    $secret = $request->query('secret', '');
    $languages = langlist('site_lang', true);
    $currentLangFolder = get_langfolder_cookie();
    $maxloginattempts = (int) get_setting('security.maxloginattempts', 10);
    $oauthProviders = \App\Models\OauthProvider::query()
        ->orderBy("priority", 'desc')
        ->where('enabled', '=', 1)
        ->get();
    $formInputStyle = 'style="width: min(100%, 320px); min-width: 180px; border: 1px solid gray; box-sizing: border-box"';
@endphp

@section('title', $lang['head_login'])

@push('scripts')
    <script type="text/javascript" src="vendor/jquery-loading/jquery.loading.min.js"></script>
    <script type="text/javascript" src="js/crypto-js.js"></script>
    <script type="text/javascript" src="js/passkey.js"></script>
    <script type="text/javascript">
        var jqLoginForm = jQuery("#login-form");
        jqLoginForm.on("click", "input[type=button]", function () {
            let useChallengeResponseAuthentication = jqLoginForm.find("input[name=response]").length > 0
            if (!useChallengeResponseAuthentication) {
                return jqLoginForm.submit()
            }
            let jqUsername = jqLoginForm.find("[name=username]")
            let jqPassword = jqLoginForm.find(".password")
            let username = jqUsername.val()
            let password = jqPassword.val()
            login(username, password, jqLoginForm)
        })
        async function login(username, password, jqForm) {
            try {
                jQuery('body').loading({stoppable: false});
                const challengeResponse = await fetch('/api/challenge', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({username: username})
                });
                jQuery('body').loading('stop');

                const challengeData = await challengeResponse.json();
                if (challengeData.ret !== 0) {
                    layer.alert(challengeData.msg)
                    return
                }

                const clientHashedPassword = sha256(password);

                const serverSideHash = sha256(challengeData.data.secret + clientHashedPassword);

                const clientResponse = hmacSha256(challengeData.data.challenge, serverSideHash);
                jqForm.find("input[name=response]").val(clientResponse)
                jqForm.submit()
            } catch (error) {
                console.error(error);
                layer.alert(error.toString())
            }
        }
    </script>
@endpush

<form method="get" action="{{ url('/login.php') }}">
    <input type="hidden" name="secret" value="{{ $secret }}">
    <div align="right">{{ $lang['text_select_lang'] }}
        <select name="sitelanguage" onchange="submit()">
            @foreach ($languages as $row)
                <option value="{{ $row['id'] }}" @if($row['site_lang_folder'] == $currentLangFolder) selected="selected" @endif>{{ htmlspecialchars($row['lang_name']) }}</option>
            @endforeach
        </select>
    </div>
</form>

@if (!empty($returnto))
    @if (empty($request->query('nowarn')))
        <h1>{{ $lang['h1_not_logged_in'] }}</h1>
        <p><b>{{ $lang['p_error'] }}</b> {{ $lang['p_after_logged_in'] }}</p>
    @endif
@endif

<form id="login-form" method="post" action="{{ url('/takelogin.php') }}">
    @csrf
    <input type="hidden" name="secret" value="{{ $secret }}">
    <p>{{ $lang['p_need_cookies_enables'] }}<br /> [<b>{{ $maxloginattempts }}</b>] {{ $lang['p_fail_ban'] }}</p>
    <p>{{ $lang['p_you_have'] }} <b>{!! remaining() !!}</b> {{ $lang['p_remaining_tries'] }}</p>
    <table border="0" cellpadding="5">
        <tr>
            <td class="rowhead">{{ $lang['rowhead_username'] }}</td>
            <td class="rowfollow" align="left"><input type="text" class="username" name="username" autocomplete="username" {!! $formInputStyle !!} /></td>
        </tr>
        <tr>
            <td class="rowhead">{{ $lang['rowhead_password'] }}</td>
            <td class="rowfollow" align="left">
                <input type="password" class="password" @if(!$useChallengeResponse) name="password" @endif autocomplete="current-password" {!! $formInputStyle !!} />
            </td>
        </tr>
        <tr>
            <td class="rowhead">{{ $lang['rowhead_two_step_code'] }}</td>
            <td class="rowfollow" align="left"><input type="text" name="two_step_code" inputmode="numeric" pattern="[0-9]*" placeholder="{{ $lang['two_step_code_tooltip'] }}" {!! $formInputStyle !!} /></td>
        </tr>
        @if ($ivEnabled)
            {!! image_code_markup() !!}
        @endif
        <tr><td class="toolbox" colspan="2" align="left">{{ $lang['text_advanced_options'] }}</td></tr>
        <tr>
            <td class="rowhead">{{ $lang['text_auto_logout'] }}</td>
            <td class="rowfollow" align="left">
                <input class="checkbox" type="checkbox" name="logout" value="yes" />{{ $lang['checkbox_auto_logout'] }}
            </td>
        </tr>
        <tr>
            <td class="toolbox" colspan="2" align="right">
                <input id="submit-btn" type="button" value="{{ $lang['button_login'] }}" class="btn" />
                <input type="reset" value="{{ $lang['button_reset'] }}" class="btn" />
            </td>
        </tr>
    </table>
    @if (!empty($returnto))
        <input type="hidden" name="returnto" value="{{ htmlspecialchars($returnto) }}" />
    @endif
    @if ($useChallengeResponse)
        <input type="hidden" name="response" />
    @endif
    {!! render_passkey_login() !!}
</form>

@if ($oauthProviders->isNotEmpty())
    <p>{{ $lang['other_methods'] }}:
        @foreach ($oauthProviders as $oauthProvider)
            [<b><a href="{{ url('oauth/redirect/' . $oauthProvider->uuid) }}">{{ $oauthProvider->name }}</a></b>]
        @endforeach
    </p>
@endif
@if (\App\Models\Setting::getIsComplainEnabled())
    <p>[<b><a href="{{ url('/complains.php') }}">{{ $lang['text_complain'] }}</a></b>]</p>
@endif
<p>{!! $lang['p_no_account_signup'] !!}</p>
@if ($smtpType != 'none')
    <p>{!! $lang['p_forget_pass_recover'] !!}</p>
    <p>{!! $lang['p_account_banned'] !!}</p>
    <p>{!! $lang['p_resend_confirm'] !!}</p>
@endif

@if ($showHelpbox)
    <table width="100%" class="main" border="0" cellspacing="0" cellpadding="0">
        <tr><td class="embedded">
                <h2>{{ $lang['text_helpbox'] }}<font class="small"> - {{ $lang['text_helpbox_note'] }}<font id="waittime" color="red"></font></h2>
                <table width="100%" border="1" cellspacing="0" cellpadding="1">
                    <tr><td class="text">
                            <iframe src="{{ get_protocol_prefix() }}{{ $baseUrl }}/shoutbox.php?type=helpbox" width="100%" height="180" frameborder="0" name="sbox" marginwidth="0" marginheight="0"></iframe>
                            <br /><br />
                            <form action="{{ get_protocol_prefix() }}{{ $baseUrl }}/shoutbox.php" id="helpbox" method="get" target="sbox" name="shbox">
                                <div style="display: flex">
                                    {{ $lang['text_message'] }}
                                    <input type="text" id="hbtext" name="shbox_text" autocomplete="off" style="flex-grow: 1; width: 500px; border: 1px solid gray">
                                    <input type="submit" id="hbsubmit" class="btn" name="shout" value="{{ $lang['sumbit_shout'] }}" />
                                    <input type="reset" class="btn" value="{{ $lang['submit_clear'] }}" />
                                    <input type="hidden" name="sent" value="yes">
                                    <input type="hidden" name="type" value="helpbox" />
                                </div>
                            </form>
                    </td></tr>
                </table>
        </td></tr>
    </table>
@endif
