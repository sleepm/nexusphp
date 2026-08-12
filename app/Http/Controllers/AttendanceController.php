<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\Setting;
use App\Repositories\AttendanceRepository;
use Carbon\Carbon;
use Carbon\CarbonInterval;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AttendanceController extends Controller
{
    private $repository;

    public function __construct(AttendanceRepository $repository)
    {
        $this->repository = $repository;
    }

    public function attend()
    {
        $uid = Auth::id();
        $attendance = $this->repository->attend($uid);
        return $this->success($attendance->toArray());
    }

    /**
     * Show the attendance page. Mirrors legacy public/attendance.php GET.
     */
    public function showPage(Request $request)
    {
        $user = Auth::user();
        $langAttendance = get_legacy_lang_file('attendance');

        $attendanceCaptchaEnabled = $this->attendanceCaptchaEnabled();
        $today = Carbon::today();
        $tomorrow = Carbon::tomorrow();
        $end = $today->clone()->endOfMonth();
        $start = $today->clone()->subMonth(2);

        $attendance = $this->repository->getAttendance($user->id);
        $hasAttendedToday = $attendance && $attendance->added && $attendance->added->isSameDay($today);

        if (!$attendanceCaptchaEnabled && !$hasAttendedToday) {
            $this->repository->attend($user->id);
            $attendance = $this->repository->getAttendance($user->id);
            $hasAttendedToday = $attendance && $attendance->added && $attendance->added->isSameDay($today);
        }

        if (!$attendance) {
            $attendance = new Attendance([
                'uid' => $user->id,
                'points' => 0,
                'days' => 0,
                'total_days' => 0,
            ]);
            $attendance->added = null;
            $hasAttendedToday = false;
        }

        $data = [
            'request' => $request,
            'lang' => $langAttendance,
            'user' => $user,
            'attendance' => $attendance,
            'hasAttendedToday' => $hasAttendedToday,
            'attendanceCaptchaEnabled' => $attendanceCaptchaEnabled,
            'bonus' => $this->bonusSettings(),
            'localeJs' => $this->localeJs(),
        ];
        if (!$hasAttendedToday) {
            return view('attendance', $data);
        }

        $todayDate = $today->format('Y-m-d');
        $baseQuery = AttendanceLog::query()->where('date', $todayDate);
        $todayCounts = $baseQuery->count();
        $myLog = (clone $baseQuery)->where('uid', $user->id)->first(['id']);
        $myRanking = 0;
        if ($myLog) {
            $myRanking = (clone $baseQuery)->where('id', '<=', $myLog->id)->count();
        }

        $logs = AttendanceLog::query()
            ->where('uid', $user->id)
            ->where('date', '>=', $start->format('Y-m-d'))
            ->get()
            ->keyBy('date');

        $events = [];
        foreach (CarbonPeriod::create($start, CarbonInterval::make('P1D'), $end) as $value) {
            if ($value->gte($tomorrow)) {
                continue;
            }
            $checkDate = $value->format('Y-m-d');
            $eventBase = ['start' => $checkDate, 'end' => $checkDate];
            if ($logs->has($checkDate)) {
                $logValue = $logs->get($checkDate);
                $events[] = array_merge($eventBase, ['display' => 'background']);
                if ($logValue->points > 0) {
                    $events[] = array_merge($eventBase, ['title' => $logValue->points]);
                }
                if ($logValue->is_retroactive) {
                    $events[] = array_merge($eventBase, ['title' => $langAttendance['retroactive_event_text'], 'display' => 'list-item']);
                }
            } elseif ($value->lte($today) && $value->diffInDays($today, true) <= Attendance::MAX_RETROACTIVE_DAYS) {
                $events[] = array_merge($eventBase, ['groupId' => 'to_do', 'display' => 'list-item']);
            }
        }

        $data['todayCounts'] = $todayCounts;
        $data['myRanking'] = $myRanking;
        $data['eventsJson'] = json_encode($events);
        $data['validRangeJson'] = json_encode(['start' => $start->format('Y-m-d'), 'end' => $end->clone()->addDays(1)->format('Y-m-d')]);
        $data['confirmText'] = $langAttendance['retroactive_confirm_tip'] ?? '';
        return view('attendance', $data);
    }

    /**
     * Handle the attendance POST, mirrors legacy public/attendance.php POST.
     */
    public function attendPage(Request $request)
    {
        $user = Auth::user();
        if ($this->attendanceCaptchaEnabled() && get_setting('security.iv') == 'yes') {
            try {
                verify_captcha(
                    ['imagehash' => $request->input('imagehash'), 'imagestring' => $request->input('imagestring')],
                    'attendance.php'
                );
            } catch (\App\Exceptions\NexusException $exception) {
                return back()->with('error', $exception->getMessage());
            }
        }
        $attendance = $this->repository->attend($user->id);
        if (!$attendance->is_updated) {
            $langAttendance = get_legacy_lang_file('attendance');
            return back()->with('error', ($langAttendance['already_attended'] ?? 'You have already attend, no refresh please.'));
        }
        return redirect('attendance.php');
    }

    private function attendanceCaptchaEnabled(): bool
    {
        $setting = Setting::get('captcha.attendance.enabled', config('captcha.attendance.enabled', true));
        if (is_string($setting)) {
            return in_array(strtolower($setting), ['1', 'true', 'yes'], true);
        }
        return (bool) $setting;
    }

    private function bonusSettings(): array
    {
        $bonus = Setting::get('bonus');
        return [
            'initial' => $bonus['attendance_initial'] ?? Attendance::INITIAL_BONUS,
            'step' => $bonus['attendance_step'] ?? Attendance::STEP_BONUS,
            'max' => $bonus['attendance_max'] ?? Attendance::MAX_BONUS,
            'continuous' => $bonus['attendance_continuous'] ?? Attendance::CONTINUOUS_BONUS,
        ];
    }

    private function localeJs(): string
    {
        $map = [
            'en' => 'en-us',
            'chs' => 'zh-cn',
            'cht' => 'zh-tw',
        ];
        return $map[get_langfolder_cookie()] ?? 'en-us';
    }


}
