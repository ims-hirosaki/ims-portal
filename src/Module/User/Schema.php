<?php

declare(strict_types=1);

namespace IMS\Module\User;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 01_user_management.md §4 の独自テーブルDDL。
 *
 * `ims_register_schema` フィルタで Installer に寄与する（core 無改修）。
 * dbDelta 互換のため：PRIMARY KEY の後は半角スペース2つ、INDEX ではなく KEY を使う。
 *
 * 注：テーブル基底名は要件定義書のDDLと完全一致させ、traceability を保つ
 *     （`ims_` 接頭辞の有無が要件定義書内で不統一だが、意図的にそのまま踏襲する）。
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

        // ① 所属マスタ
        $ddls[] = "CREATE TABLE {$p}ims_affiliations (
  id int(11) NOT NULL AUTO_INCREMENT,
  affiliation_code varchar(20) NOT NULL,
  name varchar(100) NOT NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY affiliation_code (affiliation_code)
) {$charset};";

        // ② 部署マスタ
        $ddls[] = "CREATE TABLE {$p}ims_departments (
  id int(11) NOT NULL AUTO_INCREMENT,
  department_code varchar(20) NOT NULL,
  name varchar(100) NOT NULL,
  affiliation_id int(11) DEFAULT NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY department_code (department_code),
  KEY affiliation_id (affiliation_id)
) {$charset};";

        // ③ 役職マスタ
        $ddls[] = "CREATE TABLE {$p}ims_positions (
  id int(11) NOT NULL AUTO_INCREMENT,
  name varchar(100) NOT NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id)
) {$charset};";

        // ④ 職種マスタ
        $ddls[] = "CREATE TABLE {$p}ims_job_types (
  id int(11) NOT NULL AUTO_INCREMENT,
  name varchar(100) NOT NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id)
) {$charset};";

        // ⑤ 雇用形態マスタ
        $ddls[] = "CREATE TABLE {$p}ims_employment_types (
  id int(11) NOT NULL AUTO_INCREMENT,
  name varchar(100) NOT NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id)
) {$charset};";

        // ⑥ 手当設定マスタ（列名が name ではなく allowance_name である点に注意）
        $ddls[] = "CREATE TABLE {$p}allowance_masters (
  id int(11) NOT NULL AUTO_INCREMENT,
  allowance_code varchar(20) NOT NULL,
  allowance_name varchar(100) NOT NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  sort_order int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY allowance_code (allowance_code)
) {$charset};";

        // ⑦ 外部ツールランチャー
        $ddls[] = "CREATE TABLE {$p}ims_launcher_apps (
  id int(11) NOT NULL AUTO_INCREMENT,
  app_name varchar(50) NOT NULL,
  url varchar(2083) NOT NULL,
  icon_url varchar(2083) NOT NULL,
  protocol_scheme varchar(100) DEFAULT NULL,
  sort_order smallint(5) unsigned NOT NULL DEFAULT 0,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_sort_active (is_active, sort_order)
) {$charset};";

        // ⑧ 個人手当金額・変更履歴
        $ddls[] = "CREATE TABLE {$p}user_allowances (
  id int(11) NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  allowance_master_id int(11) NOT NULL,
  amount int(11) NOT NULL DEFAULT 0,
  effective_date date NOT NULL,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_user_allowance (user_id, allowance_master_id, effective_date)
) {$charset};";

        // ⑨ 基本給変更履歴
        $ddls[] = "CREATE TABLE {$p}salary_history (
  id int(11) NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  base_salary int(11) NOT NULL,
  effective_date date NOT NULL,
  note text,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_user_salary (user_id, effective_date)
) {$charset};";

        return $ddls;
    }
}
