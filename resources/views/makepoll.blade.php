@extends('layouts.guest')

@section('title', $pageTitle)

@section('content')
@if (session('error'))
    <table width="100%" border="1" cellspacing="0" cellpadding="10">
        <tr><td class="rowhead" align="center"><b><font class="striking">{{ session('error') }}</font></b></td></tr>
    </table>
    <br />
@endif

@if ($poll)
    <h1>{{ $lang['text_edit_poll'] }}</h1>
@else
    @if ($lastPollWarning)
        <p><font class="striking"><b>{{ $lang['text_current_poll'] }}(<i>{{ $lastPollWarning['question'] }}</i>){{ $lang['text_is_only'] }}{{ $lastPollWarning['age'] }}{{ $lang['text_old'] }}</b></font></p>
    @endif
    <h1>{{ $lang['text_make_poll'] }}</h1>
@endif

<style type="text/css">
input.mp
{
    width: 450px;
}
</style>

<form method="post" action="{{ url('makepoll.php') }}">
    @csrf
    <table border="1" cellspacing="0" cellpadding="5">
        <tr>
            <td class="rowhead">{{ $lang['text_question'] }} <font color="red">*</font></td>
            <td align="left"><input name="question" class="mp" maxlength="255" value="{{ old('question', $poll->question ?? '') }}"></td>
        </tr>
        @for ($i = 0; $i <= \App\Models\Poll::MAX_OPTION_INDEX; $i++)
            <tr>
                <td class="rowhead">{{ $lang['text_option'] }}{{ $i + 1 }}@if ($i < 2) <font color="red">*</font>@endif</td>
                <td align="left"><input name="option{{ $i }}" class="mp" maxlength="40" value="{{ old('option' . $i, $poll->{'option' . $i} ?? '') }}"><br /></td>
            </tr>
        @endfor
        <tr><td colspan="2" align="center"><input type="submit" value="{{ $poll ? $lang['submit_edit_poll'] : $lang['submit_create_poll'] }}" style="height: 20pt"></td></tr>
    </table>
    <p><font color="red">*</font>{{ $lang['text_required'] }}</p>
    @if ($poll)
        <input type="hidden" name="pollid" value="{{ $poll->id }}" />
    @endif
    <input type="hidden" name="returnto" value="{{ $returnto }}" />
</form>
@endsection