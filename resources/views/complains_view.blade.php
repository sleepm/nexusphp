@extends('layouts.guest')

@section('title', $pageTitle)

@section('content')
<div class="sheet">
    <p><table class="main" border="0" cellspacing="0" cellpadding="0"><tr><td class="embedded"><h1 style="margin:0px">{{ $lang['text_complain'] }}</h1></td></tr></table></p>

    @if (!$isLogin)
        <table class="main" width="737" border="0" cellspacing="0" cellpadding="0"><tr><td class="embedded">
            <p style="font-weight: bold; color: red">{{ $lang['text_created_note'] }}</p>
        </td></tr></table>
    @endif

    <table class="main" width="737" border="0" cellspacing="0" cellpadding="5"><tr><td class="embedded">
        <h2 align="left">{{ $lang['text_new_body'] }}</h2>
        {{ $lang['text_added'] }}：{!! gettime($complain->added, true, false) !!}<br />
        {{ $lang['text_new_email'] }} {{ htmlspecialchars($complain->email) }}
        @if ($isAdmin)
            @if ($user)
                [<a href="userdetails.php?id={{ $user->id }}" class="faqlink" target="_blank">{{ $user->username }}</a>]
                [<a href="user-ban-log.php?q={{ urlencode($user->username) }}" class="faqlink" target="_blank">{{ $lang['text_view_band_log'] }}</a>]
            @else
                [<a href="usersearch.php?em={{ urlencode($complain->email) }}" class="faqlink" target="_blank">{{ $lang['text_search_account'] }}</a>]
            @endif
            <br />IP: {{ htmlspecialchars($complain->ip) }}
        @endif
        <hr />
        {!! format_comment($complain->body) !!}
    </td></tr></table>

    <table class="main" width="737" border="0" cellspacing="0" cellpadding="5"><tr><td class="embedded">
        <h2 align="left">{{ $lang['text_replies'] }}</h2>
        @forelse ($replies as $row)
            <b>{!! $row->userid ? get_plain_username($row->userid) : $lang['text_complainer'] !!} @ {!! gettime($row->added, true, false) !!}
            @if ($isAdmin)
                ({{ htmlspecialchars($row->ip) }})
            @endif
            : </b>
            {!! format_comment($row->body) !!}<hr />
        @empty
            <p align="center">{{ $lang['text_no_replies'] }}</p>
        @endforelse
    </td></tr></table>

    @if ($complain->answered)
        <p align="center">{{ $lang['text_closed'] }}</p>
    @else
        <br /><br />
        <table style="border:1px solid #000000;" align="center"><tr><td class="text" align="center">
            <b>{{ $lang['text_reply'] }}</b><br /><br />
            <form id="reply" method="post" action="" onsubmit="return postvalid(this);">
                <input type="hidden" name="action" value="reply" />
                <input type="hidden" name="id" value="{{ $complain->id }}" />
                <br />
                {!! quickreply('reply', 'body', $lang['text_reply']) !!}
            </form>
        </td></tr></table>
    @endif

    @if ($isAdmin)
        <form action="" method="post" style="text-align: center; margin-top: 2em">
            <input type="hidden" name="action" value="{{ $complain->answered ? 'unanswered' : 'answered' }}" />
            <input type="hidden" name="id" value="{{ $complain->id }}" />
            <button>{{ $complain->answered ? $lang['text_unanswer_it'] : $lang['text_answer_it'] }}</button>
        </form>
    @endif
</div>
@endsection