@extends('layouts.guest')

@section('title', $pageTitle)

@section('content')
<h1 align="center">{{ $title }}</h1>
<form id="compose" method="post" name="compose" action="fun.php?action={{ $formAction }}{{ $fun ? '&id=' . $fun->id : '' }}">
    @csrf
    <h2 align="left">{{ $langFunctions['text_new'] }}</h2>
    <table width="100%" border="1" cellspacing="0" cellpadding="10"><tr><td class="text" align="center">
        <table class="main" width="100%" border="1" cellspacing="0" cellpadding="5">
            <tr><td class="rowhead">{{ $langFunctions['row_subject'] }}</td>
                <td class="rowfollow" align="left"><input type="text" style="width: 99%;" name="subject" maxlength="100" value="{{ htmlspecialchars($subject) }}" /></td></tr>
            <tr><td class="rowhead" valign="top">{{ $langFunctions['row_body'] }}</td>
                <td class="rowfollow" align="left"><span style="display: none;" id="previewouter"></span><div id="editorouter">
                    @php textbbcode('compose', 'body', $body, false); @endphp
                </div></td></tr>
            <tr><td colspan="2" align="center"><table><tr><td class="embedded"><input id="qr" type="submit" class="btn" value="{{ $langFunctions['submit_submit'] }}" /></td><td class="embedded">
                <input type="button" class="btn2" name="previewbutton" id="previewbutton" value="{{ $langFunctions['submit_preview'] }}" onclick="javascript:preview(this.parentNode);" />
                <input type="button" class="btn2" style="display: none;" name="unpreviewbutton" id="unpreviewbutton" value="{{ $langFunctions['submit_edit'] }}" onclick="javascript:unpreview(this.parentNode);" />
            </td></tr></table></td></tr>
        </table>
    </td></tr></table>
    <p align="center"><a href="tags.php" target="_blank">{{ $langFunctions['text_tags'] }}</a> | <a href="smilies.php" target="_blank">{{ $langFunctions['text_smilies'] }}</a></p>
</form>
@endsection