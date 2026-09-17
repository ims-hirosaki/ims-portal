<?php

declare(strict_types=1);

namespace IMS\Module\Timecard;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 月次締め後のロック判定（02_time_tracking.md §3.4「締め後のロック」）。
 *
 * 正本の判定対象は 03_勤怠管理モジュールの `wp_monthly_summary`（confirmed ステータス）
 * だが、03 は未実装のため、CLAUDE.md の方針どおり「テーブル不在時は無効」として
 * このメソッドに閉じ込める。03 実装時に、ここへ実データ参照を差し込む。
 *
 * 締め後ロックは修正許可レベル（staff_correction_level）や役割に関わらず、
 * すべての操作者に対して優先して効く（§3.4）。
 */
final class MonthlyClosing
{
    /**
     * 指定した work_date の月度が締め済み（修正不可）かどうか。
     * `wp_monthly_summary` テーブルが存在しない間は常に false（ロックなし）。
     */
    public static function is_locked(string $work_date): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'monthly_summary';

        // dbDelta 未実行環境やテーブル名の不一致でも例外を起こさないよう、
        // 存在確認のみ行い、無ければロックなしとして扱う。
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return false;
        }

        // 03_勤怠管理モジュール実装時、ここで対象月度の confirmed ステータスを
        // 実際に参照する処理へ差し替える。
        return false;
    }
}
