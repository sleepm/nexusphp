@extends('layouts.guest')

@section('title', $pageTitle)

@section('content')
    <table width="97%" class="main" border="0" cellspacing="0" cellpadding="0">
        <tr>
            <td class="embedded">
                @if ($showNoResult)
                    <br />
                    <table class="main" width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                            <td class="embedded">
                                <b>{{ $lang['std_search_results_for'] }}{{ htmlspecialchars($search) }}"</b><br />
                                {{ $lang['std_try_again'] }}
                            </td>
                        </tr>
                    </table>
                @else
                    {!! $pagertop !!}
                    {!! $table !!}
                    {!! $pagerbottom !!}
                @endif
            </td>
        </tr>
    </table>
@endsection