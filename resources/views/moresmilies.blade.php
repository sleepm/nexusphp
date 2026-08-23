<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<title>{{ $lang['head_more_smilies'] }}</title>
<style type="text/css">
img {border: none;}
body {color: #000000; background-color: #ffffff}
</style>
</head>
<body>
<script type="text/javascript">
function SmileIT(smile,form,text){
   window.opener.document.forms[form].elements[text].value = window.opener.document.forms[form].elements[text].value+" "+smile+" ";
   window.opener.document.forms[form].elements[text].focus();
   window.close();
}
</script>

<table class="lista" width="100%" cellpadding="1" cellspacing="1">
{!! $content !!}
</table>
<div align="center">
 <a href="javascript: window.close()">{{ $lang['text_close'] }}</a>
</div>
</body>
</html>
