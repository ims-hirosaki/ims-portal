<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 月次データスナップショット用に、01_ユーザー管理モジュールの給与テーブルを読む
 * （03_attendance_management.md §3.5・§6.1）。読み取り専用。書き込みは一切しない。
 *
 * 「どの行を採用するか」の判断は SalarySnapshotCalculator（純粋関数）に任せ、
 * ここは対象月末日以前の行をそのまま返すだけにする。
 */
final class SalarySnapshotRepository
{
    /**
     * @return array<int, array{id:string, base_salary:string, effective_date:string}>
     */
    public static function salary_rows(int $user_id, string $as_of_date): array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'salary_history';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, base_salary, effective_date FROM {$t} WHERE user_id = %d AND effective_date <= %s",
            $user_id,
            $as_of_date
        ), ARRAY_A) ?: [];
    }

    /**
     * @return array<int, array{id:string, allowance_master_id:string, amount:string, effective_date:string}>
     */
    public static function allowance_rows(int $user_id, string $as_of_date): array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'user_allowances';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, allowance_master_id, amount, effective_date FROM {$t} WHERE user_id = %d AND effective_date <= %s",
            $user_id,
            $as_of_date
        ), ARRAY_A) ?: [];
    }
}
