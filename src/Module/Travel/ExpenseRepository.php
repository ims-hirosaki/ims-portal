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
