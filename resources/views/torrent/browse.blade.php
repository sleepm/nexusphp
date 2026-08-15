@extends('layouts.guest')

@section('title', $pageTitle)

@section('content')
<table width="97%" class="main" border="0" cellspacing="0" cellpadding="0"><tr><td class="embedded">

{!! $hotAndClassicHtml !!}

@if ($allsec != 1 || $enablespecial != 'yes')
<form method="get" name="searchbox" action="?">
	<table border="1" class="searchbox" cellspacing="0" cellpadding="5" width="100%">
		<tbody>
		<tr>
		<td class="colhead" align="center" colspan="2"><a href="javascript: klappe_news('searchboxmain')"><img class="plus" src="pic/trans.gif" id="picsearchboxmain" alt="Show/Hide" />{!! $lang_torrents['text_search_box'] !!}</a></td>
		</tr></tbody>
		<tbody id="ksearchboxmain" style="display:none">
		<tr>
			<td class="rowfollow" align="left">
                {!! build_search_box_category_table($sectiontype, '1', '?', '?', 0, $queryString, ['select_unselect' => true, 'user_notifs' => $curUser['notifs']]) !!}
			</td>

			<td class="rowfollow" valign="middle">
				<table>
					<tr>
						<td class="bottom" style="padding: 1px;padding-left: 10px">
							<font class="medium">{!! $lang_torrents['text_show_dead_active'] !!}</font>
						</td>
				 	</tr>
					<tr>
						<td class="bottom" style="padding: 1px;padding-left: 10px">
							<select class="med" name="incldead" style="width: 100px;">
								<option value="0">{!! $lang_torrents['select_including_dead'] !!}</option>
								<option value="1"{!! $include_dead == 1 ? ' selected="selected"' : '' !!}>{!! $lang_torrents['select_active'] !!} </option>
								<option value="2"{!! $include_dead == 2 ? ' selected="selected"' : '' !!}>{!! $lang_torrents['select_dead'] !!}</option>
							</select>
						</td>
				 	</tr>
					<tr>
						<td class="bottom" style="padding: 1px;padding-left: 10px">
							<font class="medium">{!! $lang_torrents['text_show_special_torrents'] !!}</font>
						</td>
				 	</tr>
				 	<tr>
						<td class="bottom" style="padding: 1px;padding-left: 10px">
							<select class="med" name="spstate" style="width: 100px;">
								<option value="0">{!! $lang_torrents['select_all'] !!}</option>
{!! promotion_selection($special_state, 0) !!}
							</select>
						</td>
					</tr>
					<tr>
						<td class="bottom" style="padding: 1px;padding-left: 10px">
							<font class="medium">{!! $lang_torrents['text_show_bookmarked'] !!}</font>
						</td>
				 	</tr>
				 	<tr>
						<td class="bottom" style="padding: 1px;padding-left: 10px">
							<select class="med" name="inclbookmarked" style="width: 100px;">
								<option value="0">{!! $lang_torrents['select_all'] !!}</option>
								<option value="1"{!! $inclbookmarked == 1 ? ' selected="selected"' : '' !!}>{!! $lang_torrents['select_bookmarked'] !!}</option>
								<option value="2"{!! $inclbookmarked == 2 ? ' selected="selected"' : '' !!}>{!! $lang_torrents['select_bookmarked_exclude'] !!}</option>
							</select>
						</td>
					</tr>
                    @if ($showApprovalStatusFilter)
                    <tr>
                        <td class="bottom" style="padding: 1px;padding-left: 10px">
                            <font class="medium">{!! $lang_torrents['text_approval_status'] !!}</font>
                        </td>
                    </tr>
                    <tr>
                        <td class="bottom" style="padding: 1px;padding-left: 10px">
                            <select class="med" name="approval_status" style="width: 100px;">
                                <option value="">{!! $lang_torrents['select_all'] !!}</option>
                                @foreach (\App\Models\Torrent::listApprovalStatus(true) as $key => $value)
                                    <option value="{{ $key }}"@if (isset($approvalStatus) && (string) $approvalStatus === (string) $key) selected @endif>{{ $value }}</option>
                                @endforeach
                            </select>
                        </td>
                    </tr>
                    @endif
                    <tr>
                        <td class="bottom" style="{{ $searchBoxRightTdStyle }}">
                            <font class="medium">{!! $lang_torrents['size_range'] !!}</font>
                        </td>
                    </tr>
                    <tr>
                        <td class="bottom" style="{{ $searchBoxRightTdStyle }}">
                            <input type="number" min="1" name="size_begin" style="width: {{ $filterInputWidth }}px" value="{{ request('size_begin') }}"/> ~ <input type="number" min="1" name="size_end" style="width: {{ $filterInputWidth }}px" value="{{ request('size_end') }}"/>
                        </td>
                    </tr>

                    <tr>
                        <td class="bottom" style="{{ $searchBoxRightTdStyle }}">
                            <font class="medium">{!! $lang_torrents['seeders_range'] !!}</font>
                        </td>
                    </tr>
                    <tr>
                        <td class="bottom" style="{{ $searchBoxRightTdStyle }}">
                            <input type="number" min="1" name="seeders_begin" style="width: {{ $filterInputWidth }}px" value="{{ request('seeders_begin') }}"/> ~ <input type="number" min="1" name="seeders_end" style="width: {{ $filterInputWidth }}px" value="{{ request('seeders_end') }}"/>
                        </td>
                    </tr>

                    <tr>
                        <td class="bottom" style="{{ $searchBoxRightTdStyle }}">
                            <font class="medium">{!! $lang_torrents['leechers_range'] !!}</font>
                        </td>
                    </tr>
                    <tr>
                        <td class="bottom" style="{{ $searchBoxRightTdStyle }}">
                            <input type="number" min="1" name="leechers_begin" style="width: {{ $filterInputWidth }}px" value="{{ request('leechers_begin') }}"/> ~ <input type="number" min="1" name="leechers_end" style="width: {{ $filterInputWidth }}px" value="{{ request('leechers_end') }}"/>
                        </td>
                    </tr>

                    <tr>
                        <td class="bottom" style="{{ $searchBoxRightTdStyle }}">
                            <font class="medium">{!! $lang_torrents['times_completed_range'] !!}</font>
                        </td>
                    </tr>
                    <tr>
                        <td class="bottom" style="{{ $searchBoxRightTdStyle }}">
                            <input type="number" min="1" name="times_completed_begin" style="width: {{ $filterInputWidth }}px" value="{{ request('times_completed_begin') }}"/> ~ <input type="number" min="1" name="times_completed_end" style="width: {{ $filterInputWidth }}px" value="{{ request('times_completed_end') }}"/>
                        </td>
                    </tr>

                    <tr>
                        <td class="bottom" style="{{ $searchBoxRightTdStyle }}">
                            <font class="medium">{!! $lang_torrents['added_range'] !!}</font>
                        </td>
                    </tr>
                    <tr>
                        <td class="bottom" style="{{ $searchBoxRightTdStyle }}">
                            {!! sprintf(
                                '%s ~ %s',
                                datetimepicker_input('added_begin', htmlspecialchars((string) request('added_begin')), '', ['require_files' => true, 'format' => 'Y-m-d', 'style' => 'width: '.$filterInputWidth.'px']),
                                datetimepicker_input('added_end', htmlspecialchars((string) request('added_end')), '', ['require_files' => false, 'format' => 'Y-m-d', 'style' => 'width: '.$filterInputWidth.'px']),
                            ) !!}
                        </td>
                    </tr>

				</table>
			</td>
		</tr>
		</tbody>
		<tbody>
		<tr>
			<td class="rowfollow" align="center">
				<table>
					<tr>
						<td class="embedded">
							{!! $lang_torrents['text_search'] !!}&nbsp;&nbsp;
						</td>
						<td class="embedded">
							<table>
								<tr>
									<td class="embedded">
										<input id="searchinput" name="search" type="text" value="{{ $searchstr_ori }}" autocomplete="off" style="width: 200px" ondblclick="suggest(event.keyCode,this.value);" onkeyup="suggest(event.keyCode,this.value);" onkeypress="return noenter(event.keyCode);"/>
										<script src="js/suggest.js" type="text/javascript"></script>
										<div id="suggcontainer" style="text-align: left; width:100px;  display: none;">
											<div id="suggestions" style="width:204px; border: 1px solid rgb(119, 119, 119); cursor: default; position: absolute; color: rgb(0,0,0); background-color: rgb(255, 255, 255);"></div>
										</div>
									</td>
								</tr>
							</table>
						</td>
						<td class="embedded">
							{!! '&nbsp;' . $lang_torrents['text_in'] !!}

							<select name="search_area">
								<option value="0">{!! $lang_torrents['select_title'] !!}</option>
								<option value="1"{!! (int) request('search_area') == 1 ? ' selected="selected"' : '' !!}>{!! $lang_torrents['select_description'] !!}</option>
								<option value="3"{!! (int) request('search_area') == 3 ? ' selected="selected"' : '' !!}>{!! $lang_torrents['select_uploader'] !!}</option>
								<option value="4"{!! (int) request('search_area') == 4 ? ' selected="selected"' : '' !!}>{!! $lang_torrents['select_imdb_url'] !!}</option>
							</select>

							{!! $lang_torrents['text_with'] !!}

							<select name="search_mode" style="width: 60px;">
                                {!! \App\Models\SearchBox::listSelectModeOptions(request('search_mode')) !!}
							</select>

							{!! $lang_torrents['text_mode'] !!}
						</td>
					</tr>
{!! $hotSearchRow !!}
@if ($allTags->isNotEmpty())
    <tr><td colspan="3" class="embedded" style="padding-top: 4px">{!! $tagRep->renderSpan($sectiontype, ['*'], true) !!}</td></tr>
@endif

				</table>
			</td>
			<td class="rowfollow" align="center">
				<input type="submit" class="btn" value="{!! $lang_torrents['submit_go'] !!}" />
			</td>
		</tr>
		</tbody>
	</table>
	</form>
@endif

@if ($Advertisement->enable_ad())
    @php $belowsearchboxad = $Advertisement->get_ad('belowsearchbox'); @endphp
    @if (! empty($belowsearchboxad[0]))
        <div align="center" style="margin-top: 10px" id="">{{ $belowsearchboxad[0] }}</div>
    @endif
@endif

@if ($inclbookmarked == 1)
	<h1 align="center">{!! get_username($curUser['id']) !!}{!! $lang_torrents['text_s_bookmarked_torrent'] !!}</h1>
@elseif ($inclbookmarked == 2)
	<h1 align="center">{!! get_username($curUser['id']) !!}{!! $lang_torrents['text_s_not_bookmarked_torrent'] !!}</h1>
@endif

@if ($count)
	{!! $pagertop !!}
	{!! $torrentTableHtml !!}
	{!! $pagerbottom !!}
@else
	{!! $noResultsHtml !!}
@endif

</td></tr></table>

@push('scripts')
{!! \Nexus\Nexus::getAppendFooters() ? implode("\n", \Nexus\Nexus::getAppendFooters()) : '' !!}
@endpush
@endsection
