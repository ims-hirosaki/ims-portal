<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 03_attendance_management.md §5 の独自テーブルDDL（3a時点：事業マスタのみ）。
 *
 * `ims_register_schema` フィルタで Installer に寄与する（08 §5.2 準拠）。
 *
 * 手当マスタ（wp_allowance_masters）は要件定義書では本モジュール所有とされているが、
 * 実装は先行して 01_user_management モジュールが `allowance_masters` テーブルと
 * 管理画面（社員管理＞各種マスタ＞手当設定タブ）を完成させている。CLAUDE.md の
 * 「既存実装は依頼された箇所だけ変更する」方針に従い、ここでは重複定義しない
 * （引き継ぎ書_phase3a.md 確認時にユーザーに確認済み）。
 *
 * wp_monthly_summary のDDLは、書き込み処理を実装するスライス（3f）で追加する。
 * wp_daily_attendance は 3b、wp_project_hours は 3d で追加した（下記）。
 *
 * dbDelta 互換の作法（Module\Timecard\Schema 等と同一）：
 * ・PRIMARY KEY の後は半角スペース2つ
 * ・INDEX ではなく KEY を使う
 * ・型・キーワードは小文字、1カラム1行
 *
 * テーブル基底名は要件定義書のDDLと完全一致させる（`ims_` 接頭辞は付けない）。
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

        // 事業マスタ（§2.3・§5.2）。03・04・05すべてが参照する共通マスター。
        $ddls[] = "CREATE TABLE {$p}businesses (
  business_id int(11) NOT NULL AUTO_INCREMENT,
  business_code varchar(20) NOT NULL,
  business_name varchar(100) NOT NULL,
  color_code varchar(7) NOT NULL,
  client_name varchar(100) DEFAULT NULL,
  is_default_for_paid_leave tinyint(1) NOT NULL DEFAULT 0,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  sort_order int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (business_id),
  UNIQUE KEY business_code (business_code)
) {$charset};";

        // 日次勤怠集計（§3.1・§5.3）。3bでは attendance_flag='none'（既定値）固定で書き込む。
        // hourly_leave_minutes・flagによる労働時間補正は 3c（勤怠フラグ管理）で接続する。
        // rounded_clock_in_minutes/rounded_clock_out_minutes は要件定義書に無い追加仕様
        // （ユーザー確認済み）。打刻ログの生の実時刻とは別に、勤怠管理上の丸め後時刻
        // （work_date 0時からの経過分）を保持する。WorkTimeCalculator 冒頭コメント参照。
        $ddls[] = "CREATE TABLE {$p}daily_attendance (
  id int(11) NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  work_date date NOT NULL,
  attendance_flag enum('none','paid_leave','hourly_leave','half_day_am','half_day_pm','holiday_work','legal_substitute','scheduled_substitute') NOT NULL DEFAULT 'none',
  hourly_leave_minutes int(11) DEFAULT NULL,
  scheduled_minutes int(11) NOT NULL,
  rounded_clock_in_minutes int(11) DEFAULT NULL,
  rounded_clock_out_minutes int(11) DEFAULT NULL,
  actual_minutes int(11) DEFAULT NULL,
  overtime_legal_min int(11) DEFAULT NULL,
  overtime_illegal_min int(11) DEFAULT NULL,
  late_night_minutes int(11) DEFAULT NULL,
  note text DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY unique_user_date (user_id,work_date)
) {$charset};";

        // 事業別時間実績（§3.3・§5.4）。「時間帯（開始〜終了）」単位で記録する。
        // start_time/end_time は MySQL の time型（最大838:59:59）をそのまま利用し、
        // 日をまたぐ勤務（例：22:00出勤〜翌2:00退勤）は '26:00:00' のように24時を超えた
        // 表記で保存する（ProjectHourCalculator が変換を担う）。
        $ddls[] = "CREATE TABLE {$p}project_hours (
  id int(11) NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  work_date date NOT NULL,
  business_id int(11) NOT NULL,
  start_time time NOT NULL,
  end_time time NOT NULL,
  minutes int(11) NOT NULL,
  is_auto_assigned tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_user_date (user_id,work_date),
  KEY idx_business_date (business_id,work_date)
) {$charset};";

        return $ddls;
    }

    /** 事業マスタテーブルの物理名。 */
    public static function businesses_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'businesses';
    }

    /** 日次勤怠集計テーブルの物理名。 */
    public static function daily_attendance_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'daily_attendance';
    }

    /** 事業別時間実績テーブルの物理名。 */
    public static function project_hours_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'project_hours';
    }
}
