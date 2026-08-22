@extends('layouts.guest')

@section('title', $pageTitle)

@section('content')
@if (isset($content))
    {!! $content !!}
@else
    <h1 align="center">{{ $lang['text_reports'] }}</h1>
    <form method="post" action="reports.php">
        <table border="1" cellspacing="0" cellpadding="5" align="center">
            <tr>
                <td class="colhead"><nobr>{{ $lang['col_added'] }}</nobr></td>
                <td class="colhead">{{ $lang['col_reporter'] }}</td>
                <td class="colhead">{{ $lang['col_reporting'] }}</td>
                <td class="colhead"><nobr>{{ $lang['col_type'] }}</nobr></td>
                <td class="colhead">{{ $lang['col_reason'] }}</td>
                <td class="colhead"><nobr>{{ $lang['col_dealt_with'] }}</nobr></td>
                <td class="colhead"><nobr>{{ $lang['col_action'] }}</nobr></td>
            </tr>
            @foreach ($rows as $row)
                <tr>
                    <td class="rowfollow"><nobr>{!! gettime($row['added']) !!}</nobr></td>
                    <td class="rowfollow">{!! get_username($row['addedby']) !!}</td>
                    <td class="rowfollow">{!! $row['reporting'] !!}</td>
                    <td class="rowfollow"><nobr>{{ $row['typeLabel'] }}</nobr></td>
                    <td class="rowfollow">{{ htmlspecialchars($row['reason']) }}</td>
                    <td class="rowfollow"><nobr>{!! $row['dealtwithLabel'] !!}</nobr></td>
                    <td class="rowfollow"><input type="checkbox" name="delreport[]" value="{{ $row['id'] }}" /></td>
                </tr>
            @endforeach
            <tr>
                <td class="colhead" colspan="7" align="right">
                    <input type="submit" name="setdealt" value="{{ $lang['submit_set_dealt'] }}" />
                    <input type="submit" name="delete" value="{{ $lang['submit_delete'] }}" />
                </td>
            </tr>
        </table>
    </form>
    @php
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $rows,
            $count,
            $perpage,
            $page + 1,
            ['path' => url('reports.php')]
        );
    @endphp
    @include('partials.pagination', ['paginator' => $paginator])
@endif
@endsection