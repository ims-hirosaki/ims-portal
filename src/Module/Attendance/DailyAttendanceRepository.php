<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 日次勤怠集計（wp_daily_attendance）の読み書き（03_attendance_management.md §5.3）。
 *
 * attendance_flag・hourly_leave_minutes を含めた最終値（AttendanceFlagCalculator が
 * 算出したもの）を保存する（3c）。「排他制御」は attendance_flag が単一のENUM列である
 * ことで自動的に満たされるため、ここでは単純に上書き保存するだけでよい。
 *
 * user_id + work_date の UNIQUE 制約があるため、既存行があれば UPDATE、なければ INSERT する
 * （$wpdb->replace は created_at が入れ直しになるため使わない）。
 */
final class DailyAttendanceRepository
{
    private static function table(): string
    {
        return Schema::daily_attendance_table();
    }

    /** @return array<string, mixed>|null */
    public static function find(int $user_id, string $work_date): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND work_date = %s',
                $user_id,
                $work_date
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * 勤怠フラグ適用後の最終値を保存する（AttendanceFlagCalculator::apply() の結果をそのまま渡す）。
     *
     * @param array{actual_minutes:int, overtime_legal_min:int, overtime_illegal_min:int, late_night_minutes:int} $metrics
     */
    public static function save(
        int $user_id,
        string $work_date,
        int $scheduled_minutes,
        string $attendance_flag,
        ?int $hourly_leave_minutes,
        array $metrics
    ): bool {
        global $wpdb;
        $table = self::table();

        $existing_id = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . $table . ' WHERE user_id = %d AND work_date = %s',
            $user_id,
            $work_date
        ));

        $data = [
            'attendance_flag'      => $attendance_flag,
            'hourly_leave_minutes' => $hourly_leave_minutes,
            'scheduled_minutes'    => $scheduled_minutes,
            'actual_minutes'       => $metrics['actual_minutes'],
            'overtime_legal_min'   => $metrics['overtime_legal_min'],
            'overtime_illegal_min' => $metrics['overtime_illegal_min'],
            'late_night_minutes'   => $metrics['late_night_minutes'],
        ];

        if ($existing_id) {
            return $wpdb->update($table, $data, ['id' => (int) $existing_id]) !== false;
        }

        $data['user_id']   = $user_id;
        $data['work_date'] = $work_date;
        return $wpdb->insert($table, $data) !== false;
    }
}
