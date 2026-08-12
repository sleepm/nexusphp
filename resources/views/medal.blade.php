@extends('layouts.guest')

@section('title', nexus_trans('medal.label'))

@push('scripts')
    <script type="text/javascript">
        jQuery('.buy').on('click', function (e) {
            let medalId = jQuery(this).attr('data-id')
            layer.confirm("{!! $confirm_buy_msg !!}", function (index) {
                let params = {
                    action: "buyMedal",
                    params: {medal_id: medalId}
                }
                jQuery.post('ajax.php', params, function(response) {
                    if (response.ret != 0) {
                        layer.alert(response.msg)
                        return
                    }
                    window.location.reload()
                }, 'json')
            })
        })
        jQuery('.gift').on('click', function (e) {
            let medalId = jQuery(this).attr('data-id')
            let uid = jQuery(this).prev().val()
            if (!uid) {
                layer.alert('Require UID')
                return
            }
            layer.confirm("{!! $confirm_gift_msg !!}" + uid + " ?", function (index) {
                let params = {
                    action: "giftMedal",
                    params: {medal_id: medalId, uid: uid}
                }
                jQuery.post('ajax.php', params, function(response) {
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
<h1 style="text-align: center">{{ nexus_trans('medal.label') }}</h1>

<div>
    <form id="filterForm" action="{{ $request->url() }}" method="get">
        <input id="q" type="text" name="q" value="{{ $q }}" placeholder="username">
        <input type="submit">
        <input type="reset" onclick="document.getElementById('q').value='';document.getElementById('filterForm').submit();">
    </form>
</div>

<table border="1" cellspacing="0" cellpadding="5" width="100%">
    <thead>
        <tr>
            <td class="colhead">ID</td>
            <td class="colhead">{{ nexus_trans('medal.fields.image_large') }}</td>
            <td class="colhead">{{ nexus_trans('medal.fields.description') }}</td>
            <td class="colhead" style="width: 115px">{{ nexus_trans('medal.fields.sale_begin_end_time') }}</td>
            <td class="colhead">{{ nexus_trans('medal.fields.duration') }}</td>
            <td class="colhead">{{ nexus_trans('medal.fields.bonus_addition') }}</td>
            <td class="colhead">{{ nexus_trans('medal.fields.price') }}</td>
            <td class="colhead">{{ nexus_trans('medal.fields.inventory') }}</td>
            <td class="colhead">{{ nexus_trans('medal.buy_btn') }}</td>
            <td class="colhead">{{ nexus_trans('medal.gift_btn') }}</td>
        </tr>
    </thead>
    <tbody>
        @foreach ($rows as $row)
            @php
                $medal = $row['medal'];
                $saleBegin = $medal->sale_begin_time ? $medal->sale_begin_time : nexus_trans('nexus.no_limit');
                $saleEnd = $medal->sale_end_time ? $medal->sale_end_time : nexus_trans('nexus.no_limit');
            @endphp
            <tr>
                <td>{{ $medal->id }}</td>
                <td><img src="{{ $medal->image_large }}" style="max-width: 60px;max-height: 60px;" class="preview" /></td>
                <td><h1>{{ $medal->name }}</h1>{{ $medal->description }}</td>
                <td>{{ $saleBegin }} ~<br>{{ $saleEnd }}</td>
                <td>{{ $medal->durationText }}</td>
                <td>{{ ($medal->bonus_addition_factor ?? 0) * 100 }}%</td>
                <td>{{ $row['price'] }}</td>
                <td>{{ $medal->inventory ?? nexus_trans('label.infinite') }}</td>
                <td><input type="button" class="{{ $row['buy_class'] }}" data-id="{{ $medal->id }}" value="{{ $row['buy_btn'] }}"{{ $row['buy_disabled'] }}></td>
                <td><input type="number" class="uid" {{ $row['gift_disabled'] }} style="width: 60px" placeholder="UID"><input type="button" class="{{ $row['gift_class'] }}" data-id="{{ $medal->id }}" value="{{ $row['gift_btn'] }}"{{ $row['gift_disabled'] }}><span class="nowrap">{{ nexus_trans('medal.fields.gift_fee') }}: {{ $row['gift_fee_factor'] }}%</span></td>
            </tr>
        @endforeach
    </tbody>
</table>

@include('partials.pagination', ['paginator' => $paginator])
@endsection