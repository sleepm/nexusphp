{{-- Basic Blade layout for guest / auth pages, replaces legacy stdhead()/stdfoot() --}}
@php
    $siteName = \App\Models\Setting::getSiteName();
    $slogan = get_setting('main.SLOGAN', '');
    $logoMain = get_setting('main.logo', '');
    $baseUrl = \App\Models\Setting::getBaseUrl();
    $styleAddicode = get_style_addicode();
    $cssUri = get_css_uri('theme.css');
    $fontCssUri = get_font_css_uri();
    $forumPicFolder = get_forum_pic_folder();
    $pageTitle = isset($title) ? $title : $siteName;
    $yearFounded = substr(get_setting('tweak.datefounded', '2007'), 0, 4);
    $langFunctions = get_legacy_lang_file('functions');
@endphp
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta name="generator" content="{{ \Nexus\Nexus::PLATFORM_USER ? constant('PROJECTNAME') : constant('PROJECTNAME') }}" />
{!! $styleAddicode !!}
<title>{{ $siteName }} :: {{ htmlspecialchars($pageTitle) }} - Powered by {{ constant('PROJECTNAME') }}</title>
<link rel="shortcut icon" href="favicon.ico" type="image/x-icon" />
<link rel="search" type="application/opensearchdescription+xml" title="{{ $siteName }} Torrents" href="opensearch.php" />
<link rel="stylesheet" href="{{ $fontCssUri }}" type="text/css" />
<link rel="stylesheet" href="styles/sprites.css" type="text/css" />
<link rel="stylesheet" href="{{ $forumPicFolder }}/forumsprites.css" type="text/css" />
<link rel="stylesheet" href="{{ $cssUri }}" type="text/css" />
<link rel="stylesheet" href="styles/nexus.css" type="text/css" />
@stack('styles')
<script type="text/javascript" src="js/curtain_imageresizer.js"></script>
<script type="text/javascript" src="js/ajaxbasic.js"></script>
<script type="text/javascript" src="js/common.js"></script>
<script type="text/javascript" src="js/domLib.js"></script>
<script type="text/javascript" src="js/domTT.js"></script>
<script type="text/javascript" src="js/domTT_drag.js"></script>
<script type="text/javascript" src="js/fadomatic.js"></script>
<script type="text/javascript" src="js/jquery-1.12.4.min.js"></script>
<script type="text/javascript">
    jQuery.noConflict();
    window.nexusLayerOptions = {
        confirm: {btnAlign: 'c', title: 'Confirm', btn: ['OK', 'Cancel']},
        alert: {btnAlign: 'c', title: 'Info', btn: ['OK', 'Cancel']}
    }
</script>
<script type="text/javascript" src="vendor/layer-v3.5.1/layer/layer.js"></script>
</head>
<body>
<table class="head" cellspacing="0" cellpadding="0" align="center" style="width: {{ constant('CONTENT_WIDTH') }}px">
    <tr>
        <td class="clear">
@if ($logoMain != "")
            <div class="logo_img"><img src="{{ $logoMain }}" alt="{{ htmlspecialchars($siteName) }}" title="{{ htmlspecialchars($siteName) }} - {{ htmlspecialchars($slogan) }}" /></div>
@else
            <div class="logo">{{ htmlspecialchars($siteName) }}</div>
            <div class="slogan">{{ htmlspecialchars($slogan) }}</div>
@endif
        </td>
    </tr>
</table>

<table class="mainouter" width="{{ constant('CONTENT_WIDTH') }}" cellspacing="0" cellpadding="5" align="center">
    <tr><td id="nav_block" class="text" align="center">
@if (!Auth::check())
            <a href="{{ url('/login.php') }}"><font class="big"><b>{{ $langFunctions['text_login'] }}</b></font></a> / <a href="{{ url('/signup.php') }}"><font class="big"><b>{{ $langFunctions['text_signup'] }}</b></font></a>
@else
            <a href="{{ url('/') }}"><font class="big"><b>{{ $langFunctions['text_home'] }}</b></font></a>
@endif
    </td></tr>
    <tr><td class="text">
@yield('content')
    </td></tr>
</table>

<div id="footer">
    <div style="margin-top: 10px; margin-bottom: 30px;" align="center">
        &copy; <a href="{{ $baseUrl }}" target="_self">{{ $siteName }}</a> @if (date('Y') != $yearFounded){{ $yearFounded }}-@endif{{ date('Y') }} - Powered by <a href="{{ constant('NEXUSPHPURL') }}" target="_blank">{{ constant('PROJECTNAME') }}</a>
    </div>
</div>
@stack('scripts')
<script type="application/javascript" src="js/nexus.js"></script>
<script type="application/javascript" src="js/medium-zoom.min.js"></script>
<script type="application/javascript" src="vendor/jquery-goup-1.1.3/jquery.goup.min.js"></script>
<script>
    jQuery(document).ready(function () {
        jQuery.goup();
        mediumZoom('[data-zoomable]');
    });
</script>
<img id="nexus-preview" style="display: none; position: absolute" src="" />
</body>
</html>
