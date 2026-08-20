@php
    $baseUrl = \App\Models\Setting::getBaseUrl();
    $refreshUrl = get_protocol_prefix() . $baseUrl . '/shoutbox.php?type=' . htmlspecialchars($where);
    $onloadExpression = $where === 'helpbox' ? 'hbquota()' : 'startcountdown(' . $refresh . ')';
@endphp
<html><head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta http-equiv="Refresh" content="{{ $refresh }}; url={{ $refreshUrl }}">
<link rel="stylesheet" href="{{ get_font_css_uri() }}" type="text/css">
<link rel="stylesheet" href="{{ get_css_uri() }}theme.css" type="text/css">
<link rel="stylesheet" href="styles/curtain_imageresizer.css" type="text/css">
<link rel="stylesheet" href="styles/nexus.css" type="text/css">
<script src="js/curtain_imageresizer.js" type="text/javascript"></script><style type="text/css">body {overflow-y:scroll; overflow-x: hidden}</style>
{!! get_style_addicode() !!}
<script type="text/javascript">
//<![CDATA[
var t;
function startcountdown(time)
{
parent.document.getElementById('countdown').innerHTML=time;
time=time-1;
t=setTimeout("startcountdown("+time+")",1000);
}
function countdown(time)
{
	if (time <= 0){
	parent.document.getElementById("hbtext").disabled=false;
	parent.document.getElementById("hbsubmit").disabled=false;
	parent.document.getElementById("hbsubmit").value=parent.document.getElementById("sbword").innerHTML;
	}
	else {
	parent.document.getElementById("hbsubmit").value=time;
	time=time-1;
	setTimeout("countdown("+time+")", 1000);
	}
}
function hbquota(){
parent.document.getElementById("hbtext").disabled=true;
parent.document.getElementById("hbsubmit").disabled=true;
var time=10;
countdown(time);
//]]>
}
</script>
</head>
<body class='inframe' onload="{{ $onloadExpression }}">
{!! $sentScript !!}
@if ($rows->isEmpty())
&nbsp;
@else
<table border='0' cellspacing='0' cellpadding='2' width='100%' align='left'>
@foreach ($rows as $row)
    @php
        $del = '';
        if ($canManage) {
            $del .= "[<a href=\"shoutbox.php?del=" . $row->id . "\">" . $lang['text_del'] . "</a>]";
        }
        if ($row->userid) {
            $username = get_username($row->userid, false, true, true, true, false, false, "", true);
            if ($row->type == 'hb' && $where != 'helpbox') {
                $username .= $lang['text_to_guest'];
            }
        } else {
            $username = $lang['text_guest'];
        }
        $time = ($curTimetype ?? '') != 'timealive'
            ? date('m.d H:i', $row->date)
            : get_elapsed_time($row->date) . $lang['text_ago'];
    @endphp
    <tr><td class="shoutrow"><span class='date'>[{{ $time }}]</span> {!! $del !!} {!! $username !!} {!! format_comment($row->text, true, false, true, true, 600, false, false) !!}
    </td></tr>
@endforeach
</table>
@endif
</body>
</html>