{{-- File list fragment, replaces legacy public/viewfilelist.php output --}}
<table class="main" border="1" cellspacing=0 cellpadding="5">
    <tr><td class="colhead">{{ $lang['col_path'] }}</td><td class="colhead" align="center"><img class="size" src="pic/trans.gif" alt="size" /></td></tr>
@foreach ($rows as $row)
    <tr><td class="rowfollow">{{ $row['filename'] }}</td><td class="rowfollow" align="right">{{ $row['size'] }}</td></tr>
@endforeach
</table>
