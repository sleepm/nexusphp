@extends('layouts.guest')

@section('title', $mode == 'add' ? $lang['head_add_comment_to'] . $targetName
    : ($mode == 'edit' ? $lang['head_edit_comment_to'] . '"' . $targetName . '"'
    : ($mode == 'vieworiginal' ? $lang['head_original_comment'] : $lang['std_delete_comment'])))

@if ($mode == 'add' || $mode == 'edit')
    <h1>{!! $mode == 'add' ? $lang['text_add_comment_to'] : $lang['text_edit_comment_to'] !!}
        <a href="{{ url($targetUrl) }}">{{ htmlspecialchars($targetName) }}</a>
    </h1>
    <form id="compose" method="post" name="compose" action="{{ url('/comment.php?action=' . $mode . '&type=' . $type . ($mode == 'edit' ? '&cid=' . $comment->id : '')) }}">
        @csrf
        @if ($mode == 'add')
            <input type="hidden" name="pid" value="{{ $target->id }}" />
        @endif
        @if ($mode == 'edit')
            <input type="hidden" name="returnto" value="{{ request()->headers->get('referer') }}" />
        @endif
        <div class="embedded">
            <h2>{{ $mode == 'add' ? $lang['text_compose'] : $lang['text_edit'] }}</h2>
            <table border="1" cellspacing="0" cellpadding="5">
                <tr>
                    <td class="rowhead">{{ $lang['text_body'] }}</td>
                    <td class="rowfollow">
                        <textarea name="body" id="body" rows="8" cols="80" style="width: 100%" maxlength="600">{!! $mode == 'add' && !empty($quoteContent)
                            ? htmlspecialchars('[quote=' . $quoteUser . ']' . $quoteContent . '[/quote]')
                            : ($mode == 'edit' ? htmlspecialchars($comment->text) : '') !!}</textarea>
                    </td>
                </tr>
                <tr><td class="toolbox" colspan="2" align="center">
                        <input type="submit" value="{{ $lang['submit_okay'] }}" class="btn" />
                    </td></tr>
            </table>
        </div>
    </form>
@elseif ($mode == 'delete')
    <h1>{{ $lang['std_delete_comment'] }}</h1>
    <p>{!! $lang['std_delete_comment_note'] !!}<a class="altlink" href="{{ url($confirmUrl) }}"{!! $lang['std_here_if_sure'] !!}</p>
@elseif ($mode == 'vieworiginal')
    <h1>{{ $lang['text_original_content_of_comment'] }}#{{ $comment->id }}</h1>
    <table width="737" border="1" cellspacing="0" cellpadding="5">
        <tr><td class="text">
                {!! format_comment($comment->ori_text ?: $comment->text) !!}
            </td></tr>
    </table>
    @if (!empty($request->headers->get('referer')))
        <p><font size="small">(<a href="{{ $request->headers->get('referer') }}">{{ $lang['text_back'] }}</a>)</font></p>
    @endif
@endif