@extends('layouts.guest')

@section('title', "Reset User's Lost Password")

@if (session('error'))
    <table align="center" class="main" width="500" border="0" cellpadding="0" cellspacing="0">
        <tr><td class="embedded">
                <h2>Error</h2>
                <table width="100%" border="1" cellspacing="0" cellpadding="10"><tr><td class="text">{!! session('error') !!}</td></tr></table>
        </td></tr>
    </table>
@elseif (session('notice'))
    <table align="center" class="main" width="500" border="0" cellpadding="0" cellspacing="0">
        <tr><td class="embedded">
                <h2>Success</h2>
                <table width="100%" border="1" cellspacing="0" cellpadding="10"><tr><td class="text">{!! session('notice') !!}</td></tr></table>
        </td></tr>
    </table>
@endif

<table border="1" cellspacing="0" cellpadding="5">
    <form method="post" action="{{ url('/reset.php') }}">
        @csrf
        <tr><td class="colhead" align="center" colspan="2">Reset User's Lost Password</td></tr>
        <tr><td class="rowhead" align="right">User Name:</td><td class="rowfollow"><input size="40" name="username"></td></tr>
        <tr><td class="rowhead" align="right">New Password:</td><td class="rowfollow"><input type="password" size="40" name="newpassword"><br /><font class="small">Minimum is 6 characters</font></td></tr>
        <tr><td class="rowhead" align="right">Confirm New Password:</td><td class="rowfollow"><input type="password" size="40" name="newpasswordagain"></td></tr>
        <tr><td class="toolbox" colspan="2" align="center"><input type="submit" class="btn" value="Reset"></td></tr>
    </form>
</table>
