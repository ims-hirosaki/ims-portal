<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 事業別時間実績（wp_project_hours）の読み書き（03_attendance_management.md §5.4）。
 *
 * 1日分の割当ては常に「丸ごと差し替え」で扱う（§3.3 のモーダルは行追加形式で、
 * 保存のたびにその日の行全体を再構成するUIのため）。既存行を削除してから
 * 新しい行を挿入する1トランザクションにし、部分保存を残さない
 * （Module\Timecard\Repository::insert_punches_atomic() と同方針）。
 */
final class ProjectHourRepository
{
    private static function table(): string
    {
        return Schema::project_hours_table();
    }

    /** @return array<int, array<string, mixed>> */
    public static function for_date(int $user_id, string $work_date): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND work_date = %s ORDER BY start_time ASC, id ASC',
                $user_id,
                $work_date
            ),
            ARRAY_A
        );
        return is_array($rows) ? $rows : [];
    }

    /**
     * 1日分の事業別時間割当てを丸ごと差し替える。
     *
     * @param array<int, array{business_id:int, start_minutes:int, end_minutes:int, is_auto_assigned?:bool}> $rows
     *        ProjectHourCalculator::validate() を通過済みの行を渡すこと（ここでは再検証しない）。
     */
    public static function replace_day(int $user_id, string $work_date, array $rows): bool
    {
        global $wpdb;
        $table = self::table();

        $wpdb->query('START TRANSACTION');

        if ($wpdb->delete($table, ['user_id' => $user_id, 'work_date' => $work_date]) === false) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        foreach ($rows as $row) {
            $start = (int) $row['start_minutes'];
            $end   = (int) $row['end_minutes'];

            $ok = $wpdb->insert($table, [
                'user_id'          => $user_id,
                'work_date'        => $work_date,
                'business_id'      => (int) $row['business_id'],
                'start_time'       => ProjectHourCalculator::format_minutes($start),
                'end_time'         => ProjectHourCalculator::format_minutes($end),
                'minutes'          => max(0, $end - $start),
                'is_auto_assigned' => !empty($row['is_auto_assigned']) ? 1 : 0,
            ]);
            if (!$ok) {
                $wpdb->query('ROLLBACK');
                return false;
            }
        }

        $wpdb->query('COMMIT');
        return true;
    }
}
