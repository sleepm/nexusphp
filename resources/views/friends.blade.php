@extends('layouts.guest')

@section('title', $pageTitle)

@section('content')
@if (isset($content))
    {!! $content !!}
@else
<div class="sheet">
    <p><table class="main" border="0" cellspacing="0" cellpadding="0"><tr><td class="embedded"><h1 style="margin:0px">{{ $lang['text_personallist'] }} {!! $ownerName !!}</h1></td></tr></table></p>

    <table class="main" width="737" border="0" cellspacing="0" cellpadding="0"><tr><td class="embedded">
        <br />
        <h2 align="left"><a name="friends">{{ $lang['text_friendlist'] }}</a></h2>
        <table width="737" border="1" cellspacing="0" cellpadding="5"><tr class="tablea"><td>
        @if (empty($friends))
            {!! $lang['text_friends_empty'] !!}
        @else
            <table width="100%" style="padding: 0px">
                @foreach ($friends as $i => $friend)
                    @if ($i % 2 == 0)
                    <tr><td class="bottom" style="padding: 5px" width="50%" align="center">
                    @else
                    <td class="bottom" style="padding: 5px" width="50%" align="center">
                    @endif
                    <table class="main" width="100%" height="75px">
                        <tr valign="top"><td width="75" align="center" style="padding: 0px">
                            <div style="width:75px;height:75px;overflow: hidden"><img width="75px" src="{{ $friend['avatar'] }}" /></div>
                        </td><td>
                            <table class="main">
                                <tr>
                                    <td class="embedded" style="padding: 5px" width="80%">{!! $friend['body1'] !!}</td>
                                    <td class="embedded" style="padding: 5px" width="20%">{!! $friend['body2'] !!}</td>
                                </tr>
                            </table>
                        </td></tr>
                    </table>
                    @if ($i % 2 == 1)
                    </td></tr>
                    @else
                    </td>
                    @endif
                @endforeach
                @if (count($friends) % 2 == 1)
                    <td class="bottom" width="50%">&nbsp;</td></tr>
                @endif
            </table>
        @endif
        </td></tr></table><br />
    </td></tr></table>

    <br /><br />
    <table class="main" width="737" border="0" cellspacing="0" cellpadding="5"><tr><td class="embedded">
        <h2 align="left"><a name="blocks">{{ $lang['text_blocked_users'] }}</a></h2>
    </td></tr>
    <tr class="tableb"><td style="padding: 10px;">
        @if (empty($blocks))
            {!! $lang['text_blocklist_empty'] !!}
        @else
            <table width="100%" cellspacing="0" cellpadding="0">
                @foreach ($blocks as $i => $block)
                    @if ($i % 6 == 0)
                    <tr>
                    @endif
                    <td style="border: none; padding: 4px; spacing: 0px;">[<font class="small"><a href="friends.php?action=delete&amp;type=block&amp;targetid={{ $block['id'] }}">D</a></font>] {!! $block['username'] !!}</td>
                    @if ($i % 6 == 5)
                    </tr>
                    @endif
                @endforeach
            </table>
        @endif
    </td></tr></table>

    @if ($canViewUserList)
        <p><a href="users.php"><b>{{ $lang['text_find_user'] }}</b></a></p>
    @endif
</div>
@endif
@endsection