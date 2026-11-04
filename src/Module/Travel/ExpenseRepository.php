<?php

declare(strict_types=1);

namespace IMS\Module\Travel;

if (!defined('ABSPATH')) {
    exit;
}

/** wp_travel_expenses の読み書き。SQLは必ず prepare を通し、user_id で絞る。物理削除はしない。 */
final class ExpenseRepository
{
    /** @return array<string, mixed>|null 同一社員・同一日の通勤レコード（1件のみ許容。§7.1） */
    public static function find_commute(int $user_id, string $work_date): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::expenses_table() . " WHERE user_id = %d AND work_date = %s AND expense_type = 'commute' ORDER BY id ASC LIMIT 1",
                $user_id,
                $work_date
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * 期間内の通勤レコード（勤務日 → レコード）。
     *
     * @return array<string, array<string, mixed>>
     */
    public static function commutes_in_range(int $user_id, string $start, string $end): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::expenses_table() . " WHERE user_id = %d AND expense_type = 'commute' AND work_date BETWEEN %s AND %s ORDER BY work_date ASC, id ASC",
                $user_id,
                $start,
                $end
            ),
            ARRAY_A
        ) ?: [];
        $by = [];
        foreach ($rows as $r) {
            $by[(string) $r['work_date']] ??= $r; // 1日1件（先頭のみ）
        }
        return $by;
    }

    /**
     * 期間内で通勤費の生成対象になりうる日（出勤打刻のある日＋0円処理になる勤怠フラグの日）。
     *
     * @return string[] 'Y-m-d' の昇順
     */
    public static function candidate_dates(int $user_id, string $start, string $end): array
    {
        global $wpdb;
        $logs  = \IMS\Module\Timecard\Schema::logs_table();
        $daily = \IMS\Module\Attendance\Schema::daily_attendance_table();
        $dates = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT work_date FROM {$logs} WHERE user_id = %d AND punch_type = 'clock_in' AND voided_at IS NULL AND work_date BETWEEN %s AND %s
                 UNION
                 SELECT work_date FROM {$daily} WHERE user_id = %d AND attendance_flag IN ('paid_leave','legal_substitute','scheduled_substitute') AND work_date BETWEEN %s AND %s
                 ORDER BY work_date ASC",
                $user_id,
                $start,
                $end,
                $user_id,
                $start,
                $end
            )
        ) ?: [];
        return array_map('strval', $dates);
    }

    /** @return array<string, mixed>|null 本人のレコード（他人のIDは見つからない扱い） */
    public static function find_by_id(int $id, int $user_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . Schema::expenses_table() . ' WHERE id = %d AND user_id = %d', $id, $user_id),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * 期間内の事業別時間（勤務日 → 事業ID → 分）。事業のサジェスト用。
     *
     * @return array<string, array<int, int>>
     */
    public static function project_minutes_by_date(int $user_id, string $start, string $end): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT work_date, business_id, SUM(minutes) AS minutes FROM ' . \IMS\Module\Attendance\Schema::project_hours_table()
                . ' WHERE user_id = %d AND work_date BETWEEN %s AND %s GROUP BY work_date, business_id',
                $user_id,
                $start,
                $end
            ),
            ARRAY_A
        ) ?: [];
        $by = [];
        foreach ($rows as $r) {
            $by[(string) $r['work_date']][(int) $r['business_id']] = (int) $r['minutes'];
        }
        return $by;
    }

    /** @param array<string, mixed> $row */
    public static function insert(array $row): bool
    {
        global $wpdb;
        return $wpdb->insert(Schema::expenses_table(), $row) !== false;
    }

    /** @param array<string, mixed> $data */
    public static function update(int $id, int $user_id, array $data): bool
    {
        global $wpdb;
        return $wpdb->update(Schema::expenses_table(), $data, ['id' => $id, 'user_id' => $user_id]) !== false;
    }
}
