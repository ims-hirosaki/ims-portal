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
 * wp_daily_attendance / wp_project_hours / wp_monthly_summary のDDLは、
 * それぞれ書き込み処理を実装するスライス（3b / 3d / 3f）で追加する。
 * このスライス（3a）では追加しない。
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

        return $ddls;
    }

    /** 事業マスタテーブルの物理名。 */
    public static function businesses_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'businesses';
    }
}
