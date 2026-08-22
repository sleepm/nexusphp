@extends('layouts.guest')

@section('title', $pageTitle)

@section('content')
@if (isset($content))
    {!! $content !!}
@else
    <div class="sheet">
        <table class="main" width="737" border="0" cellspacing="0" cellpadding="0"><tr><td class="embedded">
            <h1 style="margin:0px">{{ $heading }}</h1>
            <br />
            <table width="100%" border="1" cellspacing="0" cellpadding="5">
                <tr><td class="rowfollow">{!! $text !!}</td></tr>
            </table>
            <br />
            <form method="post" action="report.php">
                <input type="hidden" name="{{ $field }}" value="{{ $value }}" />
                <table border="0" cellpadding="5">
                    <tr>
                        <td class="rowhead">{!! $lang['text_reason_is'] !!}</td>
                        <td class="rowfollow" align="left"><input type="text" style="width: 200px" name="reason" /></td>
                    </tr>
                    <tr>
                        <td class="toolbox" colspan="2" align="center"><input type="submit" value="{{ $lang['submit_confirm'] }}" class="btn" /></td>
                    </tr>
                </table>
            </form>
        </td></tr></table>
    </div>
@endif
@endsection