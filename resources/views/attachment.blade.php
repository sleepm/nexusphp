{{-- Attachment upload iframe (mirrors legacy public/attachment.php). --}}
@php
    $css_uri = get_css_uri();
@endphp
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<link rel="stylesheet" href="{{ get_font_css_uri() }}" type="text/css">
<link rel="stylesheet" href="{{ $css_uri . 'theme.css' }}" type="text/css">
</head>
<body class="inframe">
<table width="100%">
@if ($attachmentEnabled)
    {!! $callbackHtml !!}
    <form enctype="multipart/form-data" name="attachment" method="post" action="attachment.php?callback_func={{ $callbackFunc }}">
    <tr>
    <td class="embedded" colspan="2" align="left">
    <input type="file" name="file"{{ $countLeft ? '' : ' disabled="disabled"' }} />&nbsp;
    <input type="checkbox" name="altsize" value="yes"{{ $altsize == 'yes' ? ' checked="checked"' : '' }} />{{ $lang['text_small_thumbnail'] }}&nbsp;
    <input type="submit" name="submit" value="{{ $lang['submit_upload'] }}"{{ $countLeft ? '' : ' disabled="disabled"' }} /> 
    @if ($warning)
        <span class="striking">{{ $warning }}</span>
    @else
        <b>{{ $lang['text_left'] }}</b><font color="red">{{ $countLeft }}</font>{{ $lang['text_of'] }}{{ $countLimit }}&nbsp;&nbsp;&nbsp;<b>{{ $lang['text_size_limit'] }}</b>{{ mksize($sizeLimit) }}&nbsp;&nbsp;&nbsp;<b>{{ $lang['text_file_extensions'] }}</b>
        @php
            $allowedextsblock = '';
            foreach ($allowedExts as $ext) {
                $allowedextsblock .= $ext . '/';
            }
            $allowedextsblock = rtrim(trim($allowedextsblock), '/');
            if (!$allowedextsblock) {
                $allowedextsblock = 'N/A';
            }
        @endphp
        <span title="{{ htmlspecialchars($allowedextsblock) }}"><i>{{ $lang['text_mouse_over_here'] }}</i></span>
    @endif
    </td>
    </tr>
    </form>
@endif
</table>
</body>
</html>
