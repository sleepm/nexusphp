@extends('layouts.guest')

@section('title', nexus_trans('exam.type_task'))

@push('scripts')
    <script type="text/javascript" src="vendor/jquery-loading/jquery.loading.min.js"></script>
    <script type="text/javascript">
        jQuery('.claim').on('click', function (e) {
            let id = jQuery(this).attr('data-id')
            layer.confirm("{!! $confirm_claim_msg !!}", function (index) {
                layer.close(index)
                let params = {
                    action: "claimTask",
                    params: {exam_id: id}
                }
                jQuery('body').loading({
                    stoppable: false
                });
                jQuery.post('ajax.php', params, function(response) {
                    jQuery('body').loading('stop');
                    if (response.ret != 0) {
                        layer.alert(response.msg)
                        return
                    }
                    window.location.reload()
                }, 'json')
            })
        })
    </script>
@endpush

@section('content')
<h1 style="text-align: center">{{ nexus_trans('exam.type_task') }}</h1>

<table border="1" cellspacing="0" cellpadding="5" width="100%">
    <thead>
        <tr>
            <td class="colhead">{{ nexus_trans('label.name') }}</td>
            <td class="colhead">{{ nexus_trans('exam.index') }}</td>
            <td class="colhead">{{ nexus_trans('label.begin') }}</td>
            <td class="colhead">{{ nexus_trans('label.end') }}</td>
            <td class="colhead">{{ nexus_trans('label.exam.filter_formatted') }}</td>
            <td class="colhead">{{ nexus_trans('exam.success_reward_bonus') }}</td>
            <td class="colhead">{{ nexus_trans('exam.fail_deduct_bonus') }}</td>
            <td class="colhead">{{ nexus_trans('exam.claimed_user_count') }}</td>
            <td class="colhead">{{ nexus_trans('label.description') }}</td>
            <td class="colhead">{{ nexus_trans('exam.action_claim_task') }}</td>
        </tr>
    </thead>
    <tbody>
        @foreach ($rows as $row)
            @php
                $claimDisabled = $claimClass = '';
                $claimBtnText = nexus_trans('exam.action_claim_task');
                if ($userTasks->has($row->id)) {
                    $claimDisabled = ' disabled';
                    $claimBtnText = nexus_trans('exam.claimed_already');
                } else {
                    $claimClass = 'claim';
                }
                $claimed = sprintf(
                    '%s/%s',
                    $row->on_going_users_count ?? 0,
                    $row->max_user_count ?: nexus_trans('label.infinite')
                );
            @endphp
            <tr>
                <td class="nowrap"><strong>{{ $row->name }}</strong></td>
                <td class="nowrap">{!! $row->indexFormatted !!}</td>
                <td>{{ $row->getBeginForUser() }}</td>
                <td>{{ $row->getEndForUser() }}</td>
                <td>{!! $row->filterFormatted !!}</td>
                <td>{{ number_format($row->success_reward_bonus) }}</td>
                <td>{{ number_format($row->fail_deduct_bonus) }}</td>
                <td>{{ $claimed }}</td>
                <td>{{ $row->description }}</td>
                <td><input type="button" class="{{ $claimClass }}" data-id="{{ $row->id }}" value="{{ $claimBtnText }}"{{ $claimDisabled }}></td>
            </tr>
        @endforeach
    </tbody>
</table>

@include('partials.pagination', ['paginator' => $paginator])
@endsection
