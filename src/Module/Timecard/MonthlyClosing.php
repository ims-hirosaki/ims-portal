<?php

declare(strict_types=1);

namespace IMS\Module\Timecard;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 月次締め後のロック判定（02_time_tracking.md §3.4「締め後のロック」）。
 *
 * 判定対象は 03_勤怠管理モジュールの `wp_monthly_summary`（confirmed ステータス）。
 * 03側で対象の勤務日がどの年月（給与計算サイクルの締め日設定に基づく対象期間）に
 * 属するかを判定してから、その年月のステータスを見る（3f-3で実データに接続）。
 *
 * `wp_monthly_summary` テーブルが存在しない環境（dbDelta未実行等）でも例外を
 * 起こさないよう、存在確認のみ行い、無ければロックなしとして扱う
 * （CLAUDE.mdの「テーブル不在時は無効」方針。3f実装前はこの分岐で常にfalseだった）。
 *
 * 締め後ロックは修正許可レベル（staff_correction_level）や役割に関わらず、
 * すべての操作者に対して優先して効く（§3.4）。
 */
final class MonthlyClosing
{
    /**
     * 指定ユーザー・work_date の月度が締め済み（修正不可）かどうか。
     */
    public static function is_locked(int $user_id, string $work_date): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'monthly_summary';

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return false;
        }

        $year_month = \IMS\Module\Attendance\SalaryCycleSettings::year_month_for_date($work_date);
        $summary    = \IMS\Module\Attendance\MonthlySummaryRepository::find($user_id, $year_month);

        return $summary !== null
            && (string) $summary['status'] === \IMS\Module\Attendance\MonthlySummaryCalculator::CONFIRMED;
    }
}
