<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 月次締め・提出・承認（wp_monthly_summary）の読み書き（03_attendance_management.md §5.5）。
 *
 * ステータス遷移の妥当性チェック（権限・自己承認禁止・元ステータスの検証）は行わない。
 * ここは「渡された値をそのまま保存する」ことに徹し、業務判断は
 * MonthlySummaryService（呼び出し側）に置く（Module\Timecard\Repository と同方針）。
 */
final class MonthlySummaryRepository
{
    private static function table(): string
    {
        return Schema::monthly_summary_table();
    }

    public static function find(int $user_id, string $year_month): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND year_month = %s',
                $user_id,
                $year_month
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * 提出（新規・再提出とも）。集計値を書き込み、status='submitted' にする。
     *
     * @param array{total_work_days:int, total_actual_minutes:int, total_overtime_legal:int, total_overtime_illegal:int, total_late_night_min:int, total_paid_leave_days:float} $totals
     */
    public static function submit(int $user_id, string $year_month, array $totals): bool
    {
        global $wpdb;
        $table = self::table();

        $existing_id = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . $table . ' WHERE user_id = %d AND year_month = %s',
            $user_id,
            $year_month
        ));

        $data = array_merge($totals, [
            'status'       => MonthlySummaryCalculator::SUBMITTED,
            'submitted_at' => current_time('mysql'),
        ]);

        if ($existing_id) {
            return $wpdb->update($table, $data, ['id' => (int) $existing_id]) !== false;
        }

        $data['user_id']    = $user_id;
        $data['year_month'] = $year_month;
        return $wpdb->insert($table, $data) !== false;
    }

    /** チェック者承認：submitted → checked。 */
    public static function check_approve(int $id, int $actor_id): bool
    {
        global $wpdb;
        return $wpdb->update(self::table(), [
            'status'            => MonthlySummaryCalculator::CHECKED,
            'first_approved_by' => $actor_id,
            'first_approved_at' => current_time('mysql'),
        ], ['id' => $id]) !== false;
    }

    /** チェック者差し戻し：submitted → rejected_by_checker。 */
    public static function check_reject(int $id, int $actor_id, string $comment): bool
    {
        global $wpdb;
        return $wpdb->update(self::table(), [
            'status'            => MonthlySummaryCalculator::REJECTED_BY_CHECKER,
            'last_rejected_by'  => $actor_id,
            'last_rejected_at'  => current_time('mysql'),
            'rejection_comment' => $comment,
        ], ['id' => $id]) !== false;
    }

    /** 最終承認：checked → confirmed。給与スナップショットは3gで別途書き込む。 */
    public static function final_approve(int $id, int $actor_id): bool
    {
        global $wpdb;
        return $wpdb->update(self::table(), [
            'status'            => MonthlySummaryCalculator::CONFIRMED,
            'final_approved_by' => $actor_id,
            'final_approved_at' => current_time('mysql'),
        ], ['id' => $id]) !== false;
    }

    /** 最終差し戻し：checked → rejected_by_admin。 */
    public static function final_reject(int $id, int $actor_id, string $comment): bool
    {
        global $wpdb;
        return $wpdb->update(self::table(), [
            'status'            => MonthlySummaryCalculator::REJECTED_BY_ADMIN,
            'last_rejected_by'  => $actor_id,
            'last_rejected_at'  => current_time('mysql'),
            'rejection_comment' => $comment,
        ], ['id' => $id]) !== false;
    }
}
