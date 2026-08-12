@extends('layouts.guest')

@section('title', $pageTitle)

@section('content')
<h1>{{ $pageTitle }}</h1>

@if ($showHelp)
    <table width="65%" border="0" align="center"><tr><td class="embedded" bgcolor="#F5F4EA"><div align="left">
        Fields left blank will be ignored;<br />
        Wildcards * and ? may be used in Name, Email and Comments, as well as multiple values<br />
        separated by spaces (e.g. 'wyz Max*' in Name will list both users named<br />
        'wyz' and those whose names start by 'Max'. Similarly '~' can be used for<br />
        negation, e.g. '~alfiest' in comments will restrict the search to users<br />
        that do not have 'alfiest' in their comments).<br /><br />
        The Ratio field accepts 'Inf' and '---' besides the usual numeric values.<br /><br />
        The subnet mask may be entered either in dotted decimal or CIDR notation<br />
        (e.g. 255.255.255.0 is the same as /24).<br /><br />
        Uploaded and Downloaded should be entered in GB.<br /><br />
        For search parameters with multiple text fields the second will be<br />
        ignored unless relevant for the type of search chosen.<br /><br />
        'Active only' restricts the search to users currently leeching or seeding,<br />
        'Disabled IPs' to those whose IPs also show up in disabled accounts.<br /><br />
        The 'p' columns in the results show partial stats, that is, those<br />
        of the torrents in progress.<br /><br />
        The History column lists the number of forum posts and torrent comments,<br />
        respectively, as well as linking to the history page.
    </div></td></tr></table><br /><br />
@else
    <p align="center">(<a href="?h=1">Instructions</a>)&nbsp;-&nbsp;(<a href="{{ url()->current() }}">Reset</a>)</p>
@endif

@php $highlight = ' bgcolor="#BBAF9B"'; @endphp

<form method="get" action="{{ url()->current() }}">
<table border="1" cellspacing="0" cellpadding="5">
<tr>
    <td valign="middle" class="rowhead">Name:</td>
    <td{!! $request->filled('n') ? $highlight : '' !!}><input name="n" type="text" value="{{ htmlspecialchars($request->get('n', '')) }}" size="35"></td>

    <td valign="middle" class="rowhead">Ratio:</td>
    <td{!! $request->filled('r') ? $highlight : '' !!}><select name="rt">
        @foreach (['equal', 'above', 'below', 'between'] as $i => $option)
            <option value="{{ $i }}" @if ($request->get('rt') == "$i") selected @endif>{{ $option }}</option>
        @endforeach
    </select>
    <input name="r" type="text" value="{{ htmlspecialchars($request->get('r', '')) }}" size="5" maxlength="4">
    <input name="r2" type="text" value="{{ htmlspecialchars($request->get('r2', '')) }}" size="5" maxlength="4"></td>

    <td valign="middle" class="rowhead">Member status:</td>
    <td{!! $request->filled('st') ? $highlight : '' !!}><select name="st">
        @foreach (['(any)', 'confirmed', 'pending'] as $i => $option)
            <option value="{{ $i }}" @if ($request->get('st') == "$i") selected @endif>{{ $option }}</option>
        @endforeach
    </select></td></tr>
<tr>
    <td valign="middle" class="rowhead">Email:</td>
    <td{!! $request->filled('em') ? $highlight : '' !!}><input name="em" type="text" value="{{ htmlspecialchars($request->get('em', '')) }}" size="35"></td>
    <td valign="middle" class="rowhead">IP:</td>
    <td{!! $request->filled('ip') ? $highlight : '' !!}><input name="ip" type="text" value="{{ htmlspecialchars($request->get('ip', '')) }}" maxlength="64"></td>

    <td valign="middle" class="rowhead">Account status:</td>
    <td{!! $request->filled('as') ? $highlight : '' !!}><select name="as">
        @foreach (['(any)', 'enabled', 'disabled'] as $i => $option)
            <option value="{{ $i }}" @if ($request->get('as') == "$i") selected @endif>{{ $option }}</option>
        @endforeach
    </select></td></tr>
<tr>
    <td valign="middle" class="rowhead">Mask:</td>
    <td{!! $request->filled('ma') ? $highlight : '' !!}><input name="ma" type="text" value="{{ htmlspecialchars($request->get('ma', '')) }}" maxlength="17"></td>
    <td valign="middle" class="rowhead">Class:</td>
    <td{!! $request->filled('c') && $request->get('c') != 1 ? $highlight : '' !!}><select name="c">
        <option value="1" @if ($request->get('c') == '1' || !$request->filled('c')) selected @endif>(any)</option>
        @for ($i = 2; $i <= \App\Models\User::CLASS_STAFF_LEADER + 2; $i++)
            @if ($classText = \App\Models\User::getClassText($i - 2))
                <option value="{{ $i }}" @if ($request->get('c') == $i) selected @endif>{{ $classText }}</option>
            @endif
        @endfor
    </select></td></tr>
<tr>
    <td valign="middle" class="rowhead">Joined:</td>
    <td{!! $request->filled('d') ? $highlight : '' !!}><select name="dt">
        @foreach (['on', 'before', 'after', 'between'] as $i => $option)
            <option value="{{ $i }}" @if ($request->get('dt') == "$i") selected @endif>{{ $option }}</option>
        @endforeach
    </select>
    <input name="d" type="text" value="{{ htmlspecialchars($request->get('d', '')) }}" size="12" maxlength="10">
    <input name="d2" type="text" value="{{ htmlspecialchars($request->get('d2', '')) }}" size="12" maxlength="10"></td>

    <td valign="middle" class="rowhead">Uploaded:</td>
    <td{!! $request->filled('ul') ? $highlight : '' !!}><select name="ult" id="ult">
        @foreach (['equal', 'above', 'below', 'between'] as $i => $option)
            <option value="{{ $i }}" @if ($request->get('ult') == "$i") selected @endif>{{ $option }}</option>
        @endforeach
    </select>
    <input name="ul" type="text" id="ul" size="8" maxlength="7" value="{{ htmlspecialchars($request->get('ul', '')) }}">
    <input name="ul2" type="text" id="ul2" size="8" maxlength="7" value="{{ htmlspecialchars($request->get('ul2', '')) }}"></td>

    <td valign="middle" class="rowhead">Donor:</td>
    <td{!! $request->filled('do') ? $highlight : '' !!}><select name="do">
        @foreach (['(any)', 'Yes', 'No'] as $i => $option)
            <option value="{{ $i }}" @if ($request->get('do') == "$i") selected @endif>{{ $option }}</option>
        @endforeach
    </select></td></tr>
<tr>
    <td valign="middle" class="rowhead">Last seen:</td>
    <td{!! $request->filled('ls') ? $highlight : '' !!}><select name="lst">
        @foreach (['on', 'before', 'after', 'between'] as $i => $option)
            <option value="{{ $i }}" @if ($request->get('lst') == "$i") selected @endif>{{ $option }}</option>
        @endforeach
    </select>
    <input name="ls" type="text" value="{{ htmlspecialchars($request->get('ls', '')) }}" size="12" maxlength="10">
    <input name="ls2" type="text" value="{{ htmlspecialchars($request->get('ls2', '')) }}" size="12" maxlength="10"></td>

    <td valign="middle" class="rowhead">Downloaded:</td>
    <td{!! $request->filled('dl') ? $highlight : '' !!}><select name="dlt" id="dlt">
        @foreach (['equal', 'above', 'below', 'between'] as $i => $option)
            <option value="{{ $i }}" @if ($request->get('dlt') == "$i") selected @endif>{{ $option }}</option>
        @endforeach
    </select>
    <input name="dl" type="text" id="dl" size="8" maxlength="7" value="{{ htmlspecialchars($request->get('dl', '')) }}">
    <input name="dl2" type="text" id="dl2" size="8" maxlength="7" value="{{ htmlspecialchars($request->get('dl2', '')) }}"></td>

    <td valign="middle" class="rowhead">Warned:</td>
    <td{!! $request->filled('w') ? $highlight : '' !!}><select name="w">
        @foreach (['(any)', 'Yes', 'No'] as $i => $option)
            <option value="{{ $i }}" @if ($request->get('w') == "$i") selected @endif>{{ $option }}</option>
        @endforeach
    </select></td></tr>

<tr>
    <td class="rowhead"></td><td></td>
    <td valign="middle" class="rowhead">Active only:</td>
    <td{!! $request->filled('ac') ? $highlight : '' !!}><input name="ac" type="checkbox" value="1" @if ($request->get('ac')) checked @endif></td>
    <td valign="middle" class="rowhead">Disabled IP:</td>
    <td{!! $request->filled('dip') ? $highlight : '' !!}><input name="dip" type="checkbox" value="1" @if ($request->get('dip')) checked @endif></td>
</tr>
<tr><td colspan="6" align="center"><input name="submit" type="submit" class="btn"></td></tr>
</table>
<br /><br />
</form>

@if ($hasSearch)
    @if ($total > 0)
    @if ($total > $paginator->perPage())
        @include('partials.pagination', ['paginator' => $paginator, 'position' => 'top'])
    @endif
    <table border="1" cellspacing="0" cellpadding="5">
        <tr>
            <td class="colhead" align="left">Name</td>
            <td class="colhead" align="left">Ratio</td>
            <td class="colhead" align="left">IP</td>
            <td class="colhead" align="left">Email</td>
            <td class="colhead" align="left">Joined:</td>
            <td class="colhead" align="left">Last seen:</td>
            <td class="colhead" align="left">Status</td>
            <td class="colhead" align="left">Enabled</td>
            <td class="colhead">pR</td>
            <td class="colhead">pUL</td>
            <td class="colhead">pDL</td>
            <td class="colhead">History</td>
        </tr>
        @foreach ($rows as $user)
            <tr>
                <td>{!! $user['username'] !!}</td>
                <td>{!! $user['ratio'] !!}</td>
                <td>{!! $user['ip'] !!}</td>
                <td>{{ $user['email'] }}</td>
                <td><div align="center">{{ $user['added'] }}</div></td>
                <td><div align="center">{{ $user['last_access'] }}</div></td>
                <td><div align="center">{{ $user['status'] }}</div></td>
                <td><div align="center">{{ $user['enabled'] }}</div></td>
                <td><div align="center">{!! $user['p_ratio'] !!}</div></td>
                <td><div align="right">{{ $user['p_ul'] }}</div></td>
                <td><div align="right">{{ $user['p_dl'] }}</div></td>
                <td><div align="center">
                    @if ($user['posts'])<a href="userhistory.php?action=viewposts&id={{ $user['id'] }}">{{ $user['posts'] }}</a>@else{{ $user['posts'] }}@endif
                    |@if ($user['comments'])<a href="userhistory.php?action=viewcomments&id={{ $user['id'] }}">{{ $user['comments'] }}</a>@else{{ $user['comments'] }}@endif
                </div></td>
            </tr>
        @endforeach
    </table>
    @if ($total > $paginator->perPage())
        @include('partials.pagination', ['paginator' => $paginator, 'position' => 'bottom'])
    @endif
    @else
    <div align="center"><br /><table border="0" class="main" cellspacing="0" cellpadding="0"><tr><td class="embedded"><b>No user was found.</b></td></tr></table><br /></div>
    @endif
@endif

@endsection