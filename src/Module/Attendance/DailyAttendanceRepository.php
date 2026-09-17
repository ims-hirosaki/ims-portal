<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 日次勤怠集計（wp_daily_attendance）の読み書き（03_attendance_management.md §5.3）。
 *
 * 3b時点では WorkTimeCalculator の算出結果（打刻実績のみ由来）を保存するだけ。
 * attendance_flag による書き込みは3c（勤怠フラグ管理）で追加する。
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
     * 打刻実績由来の算出結果を保存する（attendance_flag は 'none' のまま：3cで拡張）。
     *
     * @param array{actual_minutes:int, overtime_legal_min:int, overtime_illegal_min:int, late_night_minutes:int} $metrics
     */
    public static function save(int $user_id, string $work_date, int $scheduled_minutes, array $metrics): bool
    {
        global $wpdb;
        $table = self::table();

        $existing_id = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . $table . ' WHERE user_id = %d AND work_date = %s',
            $user_id,
            $work_date
        ));

        $data = [
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
