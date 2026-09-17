<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

use IMS\Module\Timecard\Repository as TimecardRepository;
use IMS\Support\UserRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * スタッフ向け月次勤務表グリッド（03_attendance_management.md §4.1）の表示に必要な
 * データを1か月分まとめて読み出す（読み取り専用の集約サービス）。
 *
 * 02（打刻ログ）・03の各テーブル（wp_daily_attendance・wp_project_hours）・
 * 事業マスタを1か所に集約し、AttendanceGridPage（3e）はこの結果を描画するだけにする。
 *
 * 3e時点のスコープ：表示のみ。セル編集（事業別時間割当てモーダル・勤怠フラグ変更）は
 * 次スライスで AttendanceFlagService / ProjectHourService を画面から呼び出す形で追加する。
 */
final class AttendanceGridService
{
    /**
     * @return array{
     *   businesses: array<int, array{id:int, name:string, color:string}>,
     *   days: array<string, array{
     *     date:string, day:int, dow:int,
     *     clock_in_minutes:?int, clock_out_minutes:?int,
     *     breaks: array<int, array{0:int, 1:int}>,
     *     attendance_flag:string, flag_label:string, hourly_leave_minutes:?int,
     *     actual_minutes:?int, overtime_legal_min:?int, overtime_illegal_min:?int, late_night_minutes:?int,
     *     raw_actual_minutes:?int,
     *     allocations: array<int, array{business_id:int, start_minutes:int, end_minutes:int}>,
     *     needs_allocation: bool
     *   }>,
     *   business_totals: array<int, int>
     * }
     */
    public static function month_data(int $user_id, int $year, int $month): array
    {
        $first         = sprintf('%04d-%02d-01', $year, $month);
        $days_in_month = (int) date('t', strtotime($first));

        $businesses = [];
        foreach (BusinessRepository::all(false) as $row) {
            $businesses[(int) $row['business_id']] = [
                'id'    => (int) $row['business_id'],
                'name'  => (string) $row['business_name'],
                'color' => (string) $row['color_code'],
            ];
        }

        $month_logs        = TimecardRepository::logs_for_month($user_id, $year, $month);
        $scheduled_minutes = (int) round(UserRepository::get_scheduled_work_hours($user_id) * 60);

        $days            = [];
        $business_totals = [];

        for ($d = 1; $d <= $days_in_month; $d++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $logs = $month_logs[$date] ?? [];

            $punch = WorkTimeCalculator::punch_times($logs, $date);
            $raw   = WorkTimeCalculator::calculate_day($logs, $date, $scheduled_minutes);

            $daily = DailyAttendanceRepository::find($user_id, $date);
            $flag  = $daily !== null ? (string) $daily['attendance_flag'] : AttendanceFlagCalculator::NONE;
            $hourly_leave_minutes = $daily !== null && $daily['hourly_leave_minutes'] !== null
                ? (int) $daily['hourly_leave_minutes']
                : null;

            $allocations = [];
            foreach (ProjectHourRepository::for_date($user_id, $date) as $row) {
                $business_id = (int) $row['business_id'];
                $start = ProjectHourCalculator::parse_minutes((string) $row['start_time']) ?? 0;
                $end   = ProjectHourCalculator::parse_minutes((string) $row['end_time']) ?? 0;

                $allocations[] = [
                    'business_id'   => $business_id,
                    'start_minutes' => $start,
                    'end_minutes'   => $end,
                ];
                $business_totals[$business_id] = ($business_totals[$business_id] ?? 0) + max(0, $end - $start);
            }

            $days[$date] = [
                'date' => $date,
                'day'  => $d,
                'dow'  => (int) date('w', strtotime($date)),

                'clock_in_minutes'  => $punch['clock_in_minutes'],
                'clock_out_minutes' => $punch['clock_out_minutes'],
                'breaks'            => $punch['breaks'],

                'attendance_flag'      => $flag,
                'flag_label'            => AttendanceFlagCalculator::label($flag),
                'hourly_leave_minutes'  => $hourly_leave_minutes,

                'actual_minutes'       => $daily['actual_minutes'] ?? null,
                'overtime_legal_min'   => $daily['overtime_legal_min'] ?? null,
                'overtime_illegal_min' => $daily['overtime_illegal_min'] ?? null,
                'late_night_minutes'   => $daily['late_night_minutes'] ?? null,

                'raw_actual_minutes' => $raw['actual_minutes'] ?? null,
                'allocations'        => $allocations,
                'needs_allocation'   => self::needs_allocation($flag, $raw, $allocations, $scheduled_minutes),
            ];
        }

        return [
            'businesses'      => array_values($businesses),
            'days'            => $days,
            'business_totals' => $business_totals,
        ];
    }

    /**
     * この日が「事業別時間の未割当て」としてハイライト（§3.3「⚠️要入力」）すべきかどうか。
     *
     * 比較対象は常に打刻由来の実労働時間（$raw。休憩を除いた実際に働いた時間）とする。
     * 有給は所定労働時間が割当ての目標になる（自動割当ては未実装。§3.3の有給日自動割当て参照）。
     *
     * @param array{actual_minutes:int, ...}|null $raw
     * @param array<int, array{start_minutes:int, end_minutes:int}> $allocations
     */
    private static function needs_allocation(string $flag, ?array $raw, array $allocations, int $scheduled_minutes): bool
    {
        if (AttendanceFlagCalculator::requires_no_allocation($flag)) {
            return false;
        }

        $allocated_total = 0;
        foreach ($allocations as $a) {
            $allocated_total += max(0, $a['end_minutes'] - $a['start_minutes']);
        }

        if ($flag === AttendanceFlagCalculator::PAID_LEAVE) {
            return $allocated_total < $scheduled_minutes;
        }

        // 打刻が未完了（進行中の日）・実働なしの日はハイライトしない
        if ($raw === null || $raw['actual_minutes'] === 0) {
            return false;
        }

        return $allocated_total !== $raw['actual_minutes'];
    }
}
