@extends('layouts.guest')

@section('title', $pageTitle)

@section('content')
    <h1 align="center">{{ $lang['text_rss_feeds'] }}</h1>

    @if (!empty($error))
        <div class="error" align="center">{{ $error }}</div>
    @endif

    @if (!empty($message))
        <div class="success" align="center">
            <h2>{{ $lang['std_done'] }}</h2>
            <pre>{{ $message }}</pre>
        </div>
    @endif

    <form method="post" action="{{ url('/getrss.php') }}">
        @csrf
        <table cellspacing="1" cellpadding="5" width="97%">
            <tr>
                <td class="rowhead">{{ $lang['row_categories_to_retrieve'] }}</td>
                <td class="rowfollow" align="left">
                    {!! $categoriesHtml !!}
                    @if ($enableSpecial)
                        <div style="height: 1px;background-color: #eee;margin: 10px 0"></div>
                        {!! $categoriesSpecialHtml !!}
                    @endif
                </td>
            </tr>
            <tr>
                <td class="rowhead">{{ $lang['row_show_bookmarked'] }}</td>
                <td class="rowfollow" align="left">
                    <input type="radio" name="inclbookmarked" id="inclbookmarked0" value="0" checked="checked" /><label for="inclbookmarked0">{{ $lang['text_all'] }}</label>&nbsp;<input type="radio" name="inclbookmarked" id="inclbookmarked1" value="1" /><label for="inclbookmarked1">{{ $lang['text_only_bookmarked'] }}</label><div>{{ $lang['text_show_bookmarked_note'] }}</div>
                </td>
            </tr>
            <tr>
                <td class="rowhead">{{ $lang['row_show_description'] }}</td>
                <td class="rowfollow" align="left">
                    <input type="radio" name="incldesc" id="incldesc1" value="1" /><label for="incldesc1">{{ $lang['text_yes'] }}</label>&nbsp;<input type="radio" name="incldesc" id="incldesc0" value="0" checked="checked" /><label for="incldesc0">{{ $lang['text_no'] }}</label>
                </td>
            </tr>
            <tr>
                <td class="rowhead">{{ $lang['row_sticky'] }}</td>
                <td class="rowfollow" align="left">
                    @foreach ($stickyTypes as $stickyKey => $stickyValue)
                        <label><input type="checkbox" name="sticky[]" value="{{ $stickyKey }}" />{{ $stickyValue }}</label>
                    @endforeach
                </td>
            </tr>
            @if ($paidTorrentEnabled)
                <tr>
                    <td class="rowhead">{{ $lang['row_paid'] }}</td>
                    <td class="rowfollow" align="left">
                        <label><input type="radio" name="paid" value="0" checked />{{ $lang['paid_no'] }}</label>
                        <label><input type="radio" name="paid" value="1" />{{ $lang['paid_yes'] }}</label>
                        <label><input type="radio" name="paid" value="2" />{{ $lang['paid_all'] }}</label>
                        <div>{{ $lang['row_paid_help'] }}</div>
                    </td>
                </tr>
            @endif
            <tr>
                <td class="rowhead">{{ $lang['row_item_title_type'] }}</td>
                <td class="rowfollow" align="left">
                    <input type="checkbox" name="itemcategory" value="1" />{{ $lang['text_item_category'] }}&nbsp;<input type="checkbox" name="itemtitle" checked="checked" disabled="disabled" />{{ $lang['text_item_title'] }}&nbsp;<input type="checkbox" name="itemsmalldescr" value="1" />{{ $lang['text_item_small_description'] }}&nbsp;<input type="checkbox" name="itemsize" value="1" />{{ $lang['text_item_size'] }}&nbsp;<input type="checkbox" name="itemuploader" value="1" />{{ $lang['text_item_uploader'] }}
                </td>
            </tr>
            <tr>
                <td class="rowhead">{{ $lang['row_rows_per_page'] }}</td>
                <td class="rowfollow" align="left">
                    <select name="showrows">
                        @foreach (['10', '50'] as $showrow)
                            <option value="{{ $showrow }}">{{ $showrow }}</option>
                        @endforeach
                    </select>
                </td>
            </tr>
            <tr>
                <td colspan="2" align="center">
                    <input type="submit" value="{{ $lang['submit_generatte_rss_link'] }}" />
                </td>
            </tr>
        </table>
    </form>
@endsection