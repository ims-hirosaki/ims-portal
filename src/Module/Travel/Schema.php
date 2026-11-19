<?php

declare(strict_types=1);

namespace IMS\Module\Travel;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 04_travel_expenses.md §5 の独自テーブルDDL。`ims_register_schema` フィルタで Installer に寄与する。
 * dbDelta 互換の作法は他モジュールの Schema と同じ（PRIMARY KEY の後は半角スペース2つ・KEY・型は小文字）。
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

        // 交通費・借上げ明細（§5.1）。確定済みの月は変更・削除しない（§7.2・§7.3）。
        $ddls[] = "CREATE TABLE {$p}travel_expenses (
  id int(11) NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  work_date date NOT NULL,
  expense_type enum('commute','vehicle_rental') NOT NULL,
  commute_type enum('round_trip','one_way') DEFAULT 'round_trip',
  route_from varchar(200) DEFAULT NULL,
  route_to varchar(200) DEFAULT NULL,
  is_round_trip tinyint(1) NOT NULL DEFAULT 0,
  business_id int(11) DEFAULT NULL,
  distance_km decimal(7,2) NOT NULL DEFAULT 0,
  unit_price decimal(6,2) NOT NULL,
  amount int(11) NOT NULL DEFAULT 0,
  is_auto_generated tinyint(1) NOT NULL DEFAULT 0,
  is_zero_suppressed tinyint(1) NOT NULL DEFAULT 0,
  note text DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_user_date (user_id,work_date),
  KEY idx_business_date (business_id,work_date),
  KEY idx_expense_type (expense_type,work_date)
) {$charset};";

        // ガソリン単価の変更履歴（§5.2）。1行ずつ追記し、更新・削除はしない（監査証跡）。
        $ddls[] = "CREATE TABLE {$p}gasoline_price_history (
  id int(11) NOT NULL AUTO_INCREMENT,
  unit_price decimal(6,2) NOT NULL,
  apply_from char(7) NOT NULL,
  affected_count int(11) NOT NULL DEFAULT 0,
  changed_by bigint(20) unsigned NOT NULL,
  changed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id)
) {$charset};";

        return $ddls;
    }

    public static function expenses_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'travel_expenses';
    }

    public static function price_history_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'gasoline_price_history';
    }
}
