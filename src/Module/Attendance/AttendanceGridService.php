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
 * 3e-2で書き込み系（事業別時間割当てモーダル・勤怠フラグ変更）を追加した際、
 * 保存直後にその日1日分だけを再集計してグリッドへ反映できるよう、1日分の集計を
 * day_data() として公開している（RestController が保存後のレスポンスに使う）。
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

            $days[$date] = self::build_day($user_id, $date, $logs, $scheduled_minutes, $d);

            foreach ($days[$date]['allocations'] as $a) {
                $business_totals[$a['business_id']] = ($business_totals[$a['business_id']] ?? 0)
                    + max(0, $a['end_minutes'] - $a['start_minutes']);
            }
        }

        return [
            'businesses'      => array_values($businesses),
            'days'            => $days,
            'business_totals' => $business_totals,
        ];
    }

    /**
     * 指定ユーザー・勤務日1日分の集計を返す（保存直後の部分更新用。3e-2）。
     *
     * @return array<string, mixed> month_data() の 'days' の1要素と同じ形
     */
    public static function day_data(int $user_id, string $work_date): array
    {
        $scheduled_minutes = (int) round(UserRepository::get_scheduled_work_hours($user_id) * 60);
        $logs = TimecardRepository::logs_for_date($user_id, $work_date);
        $day  = (int) date('j', strtotime($work_date));

        return self::build_day($user_id, $work_date, $logs, $scheduled_minutes, $day);
    }

    /**
     * @param array<int, array{punch_type:string, punched_at:string}> $logs
     * @return array<string, mixed>
     */
    private static function build_day(int $user_id, string $date, array $logs, int $scheduled_minutes, int $day): array
    {
        $punch = WorkTimeCalculator::punch_times($logs, $date);
        $raw   = WorkTimeCalculator::calculate_day($logs, $date, $scheduled_minutes);

        $daily = DailyAttendanceRepository::find($user_id, $date);
        $flag  = $daily !== null ? (string) $daily['attendance_flag'] : AttendanceFlagCalculator::NONE;
        $hourly_leave_minutes = $daily !== null && $daily['hourly_leave_minutes'] !== null
            ? (int) $daily['hourly_leave_minutes']
            : null;

        $allocations = [];
        foreach (ProjectHourRepository::for_date($user_id, $date) as $row) {
            $allocations[] = [
                'business_id'   => (int) $row['business_id'],
                'start_minutes' => ProjectHourCalculator::parse_minutes((string) $row['start_time']) ?? 0,
                'end_minutes'   => ProjectHourCalculator::parse_minutes((string) $row['end_time']) ?? 0,
            ];
        }

        return [
            'date' => $date,
            'day'  => $day,
            'dow'  => (int) date('w', strtotime($date)),

            'clock_in_minutes'  => $punch['clock_in_minutes'],
            'clock_out_minutes' => $punch['clock_out_minutes'],
            'breaks'            => $punch['breaks'],

            'attendance_flag'     => $flag,
            'flag_label'          => AttendanceFlagCalculator::label($flag),
            'hourly_leave_minutes' => $hourly_leave_minutes,

            'actual_minutes'       => $daily['actual_minutes'] ?? null,
            'overtime_legal_min'   => $daily['overtime_legal_min'] ?? null,
            'overtime_illegal_min' => $daily['overtime_illegal_min'] ?? null,
            'late_night_minutes'   => $daily['late_night_minutes'] ?? null,

            'raw_actual_minutes' => $raw['actual_minutes'] ?? null,
            'allocations'        => $allocations,
            'needs_allocation'   => self::needs_allocation($flag, $raw, $allocations, $scheduled_minutes),
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
