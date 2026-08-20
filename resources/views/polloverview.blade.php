@extends('layouts.guest')

@section('title', $pageTitle)

@section('content')
@if ($poll)
    <h1 align="center">{{ $lang['text_polls_overview'] }}</h1>

    <table width="737" border="1" cellspacing="0" cellpadding="5">
        <tr>
            <td class="colhead" align="center"><nobr>{{ $lang['col_id'] }}</nobr></td>
            <td class="colhead"><nobr>{{ $lang['col_added'] }}</nobr></td>
            <td class="colhead"><nobr>{{ $lang['col_question'] }}</nobr></td>
        </tr>
        <tr>
            <td align="center"><a href="polloverview.php?id={{ $poll->id }}">{{ $poll->id }}</a></td>
            <td>{!! gettime($poll->added) !!}</td>
            <td><a href="polloverview.php?id={{ $poll->id }}">{{ $poll->question }}</a></td>
        </tr>
    </table>

    <h1 align="center">{{ $lang['text_poll_question'] }}</h1><br />
    <table width="737" border="1" cellspacing="0" cellpadding="5">
        <tr><td class="colhead">{{ $lang['col_option_no'] }}</td><td class="colhead">{{ $lang['col_options'] }}</td></tr>
        @foreach ($options as $optionIndex => $optionValue)
            <tr><td>{{ $optionIndex }}</td><td>{{ $optionValue }}</td></tr>
        @endforeach
    </table>

    <h1 align="center">{{ $lang['text_polls_user_overview'] }}</h1>
    @if ($votes->total() == 0)
        <p align="center">{{ $lang['text_no_users_voted'] }}</p>
    @else
        @include('partials.pagination', ['paginator' => $votes, 'position' => 'top'])
        <table width="737" border="1" cellspacing="0" cellpadding="5">
            <tr>
                <td class="colhead" align="center"><nobr>{{ $lang['col_username'] }}</nobr></td>
                <td class="colhead" align="center"><nobr>{{ $lang['col_selection'] }}</nobr></td>
            </tr>
            @foreach ($votes as $vote)
                <tr>
                    <td>{!! get_username($vote->userid) !!}</td>
                    <td>{{ $options[$vote->selection] ?? '' }}</td>
                </tr>
            @endforeach
        </table>
        @include('partials.pagination', ['paginator' => $votes])
    @endif
@else
    <h1 align="center">{{ $lang['text_polls_overview'] }}</h1>

    <table width="737" border="1" cellspacing="0" cellpadding="5">
        <tr>
            <td class="colhead" align="center"><nobr>{{ $lang['col_id'] }}</nobr></td>
            <td class="colhead"><nobr>{{ $lang['col_added'] }}</nobr></td>
            <td class="colhead"><nobr>{{ $lang['col_question'] }}</nobr></td>
        </tr>
        @foreach ($pollList as $listRow)
            <tr>
                <td align="center"><a href="polloverview.php?id={{ $listRow->id }}">{{ $listRow->id }}</a></td>
                <td>{!! gettime($listRow->added) !!}</td>
                <td><a href="polloverview.php?id={{ $listRow->id }}">{{ $listRow->question }}</a></td>
            </tr>
        @endforeach
    </table>
@endif
@endsection