@extends('layouts.guest')

@section('title', 'Max. Login Attempts')

@if (!empty($update))
    <h3><b>{{ htmlspecialchars($update) }} Successful!</b></h3>
@endif
<h1>Failed Login Attempts</h1>

@if ($action == 'showlist' || $action == 'searchip')
    <table border="1" cellspacing="0" cellpadding="5" width="100%">
        <tr>
            <td class="colhead"><a href="{{ url('/maxlogin.php?order=id') }}">ID</a></td>
            <td class="colhead" align="left"><a href="{{ url('/maxlogin.php?order=ip') }}">Ip Address</a></td>
            <td class="colhead" align="left"><a href="{{ url('/maxlogin.php?order=added') }}">Action Time</a></td>
            <td class="colhead" align="left"><a href="{{ url('/maxlogin.php?order=attempts') }}">Attempts</a></td>
            <td class="colhead" align="left"><a href="{{ url('/maxlogin.php?order=type') }}">Attempt Type</a></td>
            <td class="colhead" align="left"><a href="{{ url('/maxlogin.php?order=status') }}">Status</a></td>
        </tr>
        @forelse ($list as $arr)
            <tr>
                <td>{{ $arr['id'] }}</td>
                <td align="left">{{ $arr['ip'] }}</td>
                <td align="left">{{ $arr['added'] }}</td>
                <td align="left">{{ $arr['attempts'] }}</td>
                <td align="left">{{ $arr['type'] == 'recover' ? 'Recover Password Attempt!' : 'Login Attempt!' }}</td>
                <td align="left">
                    @if ($arr['banned'] == 'yes')
                        <font color="red"><b>banned</b></font>
                        <form method="post" action="{{ url('/maxlogin.php') }}" style="display:inline">
                            @csrf
                            <input type="hidden" name="action" value="unban">
                            <input type="hidden" name="id" value="{{ $arr['id'] }}">
                            <button type="submit" class="altlink" style="border:0;background:none;color:green;cursor:pointer;padding:0">[<b>unban</b>]</button>
                        </form>
                    @else
                        <font color="green"><b>not banned</b></font>
                        <form method="post" action="{{ url('/maxlogin.php') }}" style="display:inline">
                            @csrf
                            <input type="hidden" name="action" value="ban">
                            <input type="hidden" name="id" value="{{ $arr['id'] }}">
                            <button type="submit" class="altlink" style="border:0;background:none;color:red;cursor:pointer;padding:0">[<b>ban</b>]</button>
                        </form>
                    @endif
                    <form method="post" action="{{ url('/maxlogin.php') }}" style="display:inline" onsubmit="return confirm('Are you wish to delete this attempt?')">
                        @csrf
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="{{ $arr['id'] }}">
                        <button type="submit" class="altlink" style="border:0;background:none;color:blue;cursor:pointer;padding:0">[<b>delete</b>]</button>
                    </form>
                    <a href="{{ url('/maxlogin.php?action=edit&id=' . $arr['id']) }}"><font color="blue">[<b>edit</b>]</font></a>
                </td>
            </tr>
        @empty
            <tr><td colspan="6"><b>Nothing found</b></td></tr>
        @endforelse
    </table>
    @if ($action == 'showlist' && method_exists($list, 'hasPages') && $list->hasPages())
        {{ $list->links() }}
    @endif
    <form method="post" name="search" action="{{ url('/maxlogin.php') }}">
        @csrf
        <input type="hidden" name="action" value="searchip">
        <p class="success" align="center">Search IP <input type="text" name="ip" size="25"> <input type="submit" name="submit" value="Search IP" class="btn"></p>
    </form>
@elseif ($action == 'edit')
    <table border="1" cellspacing="0" cellpadding="5" width="100%">
        <tr><td>
                <p>IP Address: <b>{{ $attempt->ip }}</b></p>
                <p>Action Time: <b>{{ $attempt->added }}</b></p>
        </td></tr>
        <form method="post" action="{{ url('/maxlogin.php') }}">
            @csrf
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="{{ $attempt->id }}">
            <input type="hidden" name="ip" value="{{ $attempt->ip }}">
            <tr><td>Attempts <input type="text" size="33" name="attempts" value="{{ $attempt->attempts }}"></td></tr>
            <tr><td>Attempt Type <select name="type">
                        <option value="login" @if($attempt->type == 'login') selected @endif>Login Attempt</option>
                        <option value="recover" @if($attempt->type == 'recover') selected @endif>Recover Password Attempts</option>
                    </select></td></tr>
            <tr><td>Current Status <select name="banned">
                        <option value="yes" @if($attempt->banned == 'yes') selected @endif>Banned!</option>
                        <option value="no" @if($attempt->banned == 'no') selected @endif>Not Banned!</option>
                    </select></td></tr>
            <tr><td><input type="submit" name="submit" value="Save" class="btn"></td></tr>
        </form>
    </table>
@endif
