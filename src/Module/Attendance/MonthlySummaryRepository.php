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
 *
 * DBの列名は target_year_month（Schema::contribute() 冒頭コメント参照。year_month は
 * MySQL/MariaDBの予約語のため実装側で変更した）。このクラスの外へは影響しないよう、
 * メソッドの引数名・PHP側のキー名は年月を表す変数として素直に $year_month のままにしている。
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
                'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND target_year_month = %s',
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
            'SELECT id FROM ' . $table . ' WHERE user_id = %d AND target_year_month = %s',
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

        $data['user_id']           = $user_id;
        $data['target_year_month'] = $year_month;
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

    /**
     * 最終承認：checked → confirmed。給与スナップショット（3g）も同じ更新で書き込む。
     * $base_salary が null（基本給の履歴が無い）の場合は snapshot_base_salary を NULL のままにする。
     */
    public static function final_approve(int $id, int $actor_id, ?int $base_salary, string $allowances_json): bool
    {
        global $wpdb;
        return $wpdb->update(self::table(), [
            'status'               => MonthlySummaryCalculator::CONFIRMED,
            'final_approved_by'    => $actor_id,
            'final_approved_at'    => current_time('mysql'),
            'snapshot_base_salary' => $base_salary,
            'snapshot_allowances'  => $allowances_json,
        ], ['id' => $id]) !== false;
    }

    /**
     * 確定の取り消し（3n-3）：取り消し記録の追記と、wp_monthly_summary の書き換えを
     * 1つのトランザクションで行う。書き換えは「まだ confirmed のままなら」に限る
     * （同時に2人が取り消しても二重に処理されないように）。失敗時はどちらも保存しない。
     *
     * @param array<string, mixed> $log    ConfirmationCancelCalculator::log_row() の結果
     * @param array<string, mixed> $update ConfirmationCancelCalculator::summary_update() の結果
     */
    public static function cancel_confirmation(int $id, array $log, array $update): bool
    {
        global $wpdb;

        $wpdb->query('START TRANSACTION');

        $inserted = $wpdb->insert(Schema::confirmation_cancellations_table(), $log);
        $updated  = $inserted !== false
            ? $wpdb->update(self::table(), $update, ['id' => $id, 'status' => MonthlySummaryCalculator::CONFIRMED])
            : false;

        if ($inserted === false || $updated !== 1) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        $wpdb->query('COMMIT');
        return true;
    }

    /**
     * 指定社員・年月の確定取り消し記録（新しい順）。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function cancellations(int $user_id, string $year_month): array
    {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::confirmation_cancellations_table()
                . ' WHERE user_id = %d AND target_year_month = %s ORDER BY cancelled_at DESC, id DESC',
                $user_id,
                $year_month
            ),
            ARRAY_A
        ) ?: [];
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
