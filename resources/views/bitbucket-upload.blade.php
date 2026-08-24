@extends('layouts.guest')

@section('title', $pageTitle)

@section('content')
<h1>{{ $lang['text_avatar_upload'] ?? 'AVATAR Upload' }}</h1>
<form method="post" action="bitbucket-upload.php" enctype="multipart/form-data">
    <table border="1" cellspacing="0" cellpadding="5">
        @if (!$uploadDirWritable)
            <tr><td align="left" colspan="2">{!! $lang['text_upload_directory_unwritable'] ?? '' !!}</td></tr>
        @endif
        <tr>
            <td align="left" colspan="2">
                {!! $lang['text_disclaimer'] ?? '' !!}{{ $scaleh }}{!! $lang['text_disclaimer_two'] ?? '' !!}{{ $scalew }}{!! $lang['text_disclaimer_three'] ?? '' !!}{{ number_format($maxfilesize) }}{!! $lang['text_disclaimer_four'] ?? '' !!}
            </td>
        </tr>
        <tr>
            <td class="rowhead">{{ $lang['row_file'] ?? 'File' }}</td>
            <td class="rowfollow"><input type="file" name="file" size="60"></td>
        </tr>
        <tr>
            <td colspan="2" align="left" class="toolbox">
                <input class="checkbox" type="checkbox" name="public" value="yes"> {{ $lang['checkbox_avatar_shared'] ?? '' }}
                <input type="submit" value="{{ $lang['submit_upload'] ?? 'Upload' }}">
            </td>
        </tr>
    </table>
</form>
@endsection
