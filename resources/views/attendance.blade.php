@extends('layouts.guest')

@section('title', $lang['title'])

@push('styles')
    <link rel="stylesheet" href="vendor/fullcalendar-5.10.2/main.min.css" type="text/css" />
@endpush

@push('scripts')
    <script type="text/javascript" src="vendor/fullcalendar-5.10.2/main.min.js"></script>
    <script type="text/javascript" src="vendor/fullcalendar-5.10.2/locales/{{ $localeJs }}.js"></script>
@endpush

@if ($hasAttendedToday)
    @php
        $count = $attendance->total_days;
        $cdays = $attendance->days;
        $points = $attendance->points;
        $headerLeft = sprintf($lang['attend_info'] . $lang['retroactive_description'], $count, $cdays, $points, $user->attendance_card);
        $headerRight = nexus_trans('attendance.ranking', ['ranking' => $myRanking, 'counts' => $todayCounts]);
    @endphp
    <h2>{{ $lang['success'] }}</h2>
    <p>{!! $headerLeft !!}<span style="float:right">{!! $headerRight !!}</span></p>

    <div style="display: flex;justify-content: center;padding: 20px 0"><div id="calendar" style="width: 60%"></div></div>
    <ul>
        <li>{!! sprintf($lang['initial'], $bonus['initial']) !!}</li>
        <li>{!! sprintf($lang['steps'], $bonus['step'], $bonus['max']) !!}</li>
        <li><ol>
                @foreach ($bonus['continuous'] as $day => $value)
                    <li>{!! sprintf($lang['continuous'], $day, $value) !!}</li>
                @endforeach
            </ol></li>
    </ul>

    <script type="text/javascript">
        let events = JSON.parse('{!! $eventsJson !!}')
        let validRange = JSON.parse('{!! $validRangeJson !!}')
        let confirmText = "{!! $confirmText !!}"
        document.addEventListener('DOMContentLoaded', function () {
            var calendarEl = document.getElementById('calendar');
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: '{{ $localeJs }}',
                events: events,
                validRange: validRange,
                eventClick: function (info) {
                    if (info.event.groupId == 'to_do') {
                        retroactive(info.event.startStr)
                    }
                }
            });
            calendar.render();
        });

        function retroactive(dateStr) {
            if (!window.confirm(confirmText + dateStr + ' ?')) {
                return
            }
            jQuery.post('ajax.php', {params: {date: dateStr}, action: 'attendanceRetroactive'}, function (response) {
                if (response.ret != 0) {
                    alert(response.msg)
                } else {
                    location.reload();
                }
            }, 'json')
        }
    </script>
@else
    <h2>{{ $lang['title'] }}</h2>
    <table width="100%" border="1" cellspacing="0" cellpadding="10"><tbody>
        <tr><td class="text">
                <div style="margin-top: 20px; text-align: center;">
                    <form method="post" action="{{ url('/attendance.php') }}" style="display: inline-block;">
                        @csrf
                        <table border="0" cellpadding="5">
                            @if ($attendanceCaptchaEnabled && \App\Models\Setting::get('security.iv') == 'yes')
                                {!! image_code_markup() !!}
                            @endif
                            <tr><td class="toolbox" colspan="2" align="center">
                                    <input type="submit" value="{{ htmlspecialchars($lang['attend_button'], ENT_QUOTES, 'UTF-8') }}" class="btn" />
                                </td></tr>
                        </table>
                    </form>
                </div>
        </td></tr>
    </tbody></table>
@endif