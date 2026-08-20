<html><head>
<title>{{ $lang['head_fun'] }}</title>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<link rel="stylesheet" href="{{ get_font_css_uri() }}" type="text/css">
<link rel="stylesheet" href="{{ get_css_uri() }}theme.css" type="text/css">
<link rel="stylesheet" href="styles/curtain_imageresizer.css" type="text/css">
<script src="js/curtain_imageresizer.js" type="text/javascript"></script><style type="text/css">body {overflow-y:scroll; overflow-x: hidden}</style>
</head><body class='inframe'>
{!! get_style_addicode() !!}
<table border="0" cellspacing="0" cellpadding="2" width="100%"><tr><td class="shoutrow" align="center"><font class="big">{!! $row->title !!}</font><font class="small">{{ $lang['text_posted_by'] }}{!! $username !!}{!! $time !!}</font></td></tr><tr><td class="shoutrow">
{!! format_comment($row->body, true, true, true) !!}</td></tr></table>
</body></html>