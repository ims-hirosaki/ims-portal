<?php

declare(strict_types=1);

namespace IMS\Module\Timecard;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 02_time_tracking.md §5 の独自テーブルDDL（2表）。
 *
 * `ims_register_schema` フィルタで Installer に寄与する（core 無改修 / 08 §5.2）。
 *
 * dbDelta 互換のための作法（Module\User\Schema と同一）：
 * ・PRIMARY KEY の後は半角スペース2つ
 * ・INDEX ではなく KEY を使う
 * ・型・キーワードは小文字で書く（dbDelta の差分比較が文字列一致のため）
 * ・1カラム1行
 *
 * テーブル基底名は要件定義書のDDLと完全一致させ、traceability を保つ
 * （`ims_` 接頭辞が付かないのは要件定義書に合わせた意図的なもの）。
 */
final class Schema
{
    /**
     * @param string[] $ddls
     * @return string[]
     */
    public static function contribute(array $ddls): array
    {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        // ① 打刻ログ（§5.1）
        //    ・work_date は「帰属日付」。退勤打刻のみ date_boundary_hour を考慮して前日になり得る。
        //    ・clock_in / clock_out の1日1件制約はアプリケーション層で担保する（§5.1 制約・ルール）。
        //      break_in / break_out は複数件を許容するため、DB の UNIQUE 制約は張らない。
        //    ・物理削除はしない（§7.5）。
        $ddls[] = "CREATE TABLE {$p}attendance_logs (
  log_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  work_date date NOT NULL,
  punch_type enum('clock_in','break_in','break_out','clock_out') NOT NULL,
  punched_at datetime NOT NULL,
  is_auto_filled tinyint(1) NOT NULL DEFAULT 0,
  ip_address varchar(45) DEFAULT NULL,
  gps_latitude decimal(10,7) DEFAULT NULL,
  gps_longitude decimal(10,7) DEFAULT NULL,
  note text DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (log_id),
  KEY idx_user_date (user_id,work_date),
  KEY idx_punched_at (punched_at)
) {$charset};";

        // ② 打刻修正履歴（§5.2）
        //    監査証跡。削除不可・修正のたびに1行追記する。
        $ddls[] = "CREATE TABLE {$p}attendance_corrections (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  log_id bigint(20) unsigned NOT NULL,
  original_datetime datetime NOT NULL,
  corrected_datetime datetime NOT NULL,
  reason text NOT NULL,
  corrected_by bigint(20) unsigned NOT NULL,
  corrected_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_log_id (log_id)
) {$charset};";

        return $ddls;
    }

    /** 打刻ログテーブルの物理名。 */
    public static function logs_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'attendance_logs';
    }

    /** 修正履歴テーブルの物理名。 */
    public static function corrections_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'attendance_corrections';
    }
}
