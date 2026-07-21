<?php

declare(strict_types=1);

namespace IMS\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 01_user_management.md §3.3 で定義された usermeta 属性への薄いアクセサ。
 * 各モジュールはこのクラス経由で属性を読み書きし、生の get_user_meta 呼び出しを
 * あちこちに散らさない（キー名の変更・型の変換をここに閉じ込める）。
 *
 * Phase 0 時点では基本情報の読み取りのみ。01モジュール実装時に、書き込み・
 * バリデーション（自己承認禁止・権限チェック等）をここに追加していく。
 */
final class UserRepository
{
    public static function get_employee_code(int $user_id): string
    {
        return (string) get_user_meta($user_id, 'employee_code', true);
    }

    public static function get_auth_type(int $user_id): string
    {
        $value = get_user_meta($user_id, 'auth_type', true);
        return $value === 'google_sso' ? 'google_sso' : 'password';
    }

    public static function get_employment_status(int $user_id): string
    {
        $value = get_user_meta($user_id, 'employment_status', true);
        return $value !== '' ? (string) $value : '在籍';
    }

    public static function is_retired(int $user_id): bool
    {
        return self::get_employment_status($user_id) === '退職';
    }

    public static function get_first_approver_id(int $user_id): ?int
    {
        $value = get_user_meta($user_id, 'first_approver_id', true);
        return $value !== '' ? (int) $value : null;
    }

    public static function get_final_approver_id(int $user_id): ?int
    {
        $value = get_user_meta($user_id, 'final_approver_id', true);
        return $value !== '' ? (int) $value : null;
    }

    public static function get_scheduled_work_hours(int $user_id): float
    {
        $value = get_user_meta($user_id, 'scheduled_work_hours', true);
        return $value !== '' ? (float) $value : 8.0;
    }

    public static function get_affiliation_id(int $user_id): ?int
    {
        $value = get_user_meta($user_id, 'affiliation_id', true);
        return $value !== '' ? (int) $value : null;
    }

    public static function get_department_id(int $user_id): ?int
    {
        $value = get_user_meta($user_id, 'department_id', true);
        return $value !== '' ? (int) $value : null;
    }

    /** 交通費単価（円/km）→ 04_交通費モジュールが参照 */
    public static function get_travel_unit_price(int $user_id): float
    {
        $value = get_user_meta($user_id, 'travel_unit_price', true);
        return $value !== '' ? (float) $value : 0.0;
    }

    /** 通勤片道距離（km）→ 04_交通費モジュールが参照 */
    public static function get_commute_one_way_km(int $user_id): float
    {
        $value = get_user_meta($user_id, 'commute_one_way_km', true);
        return $value !== '' ? (float) $value : 0.0;
    }

    /**
     * 給与・手当等の機微情報は、呼び出し側で必ず ims_view_salary 権限を確認してから使うこと。
     * このメソッド自体は権限チェックを行わない（責務を呼び出し側に残すことで、
     * 「読めたが表示しなかった」漏洩パターンを防ぐ）。
     */
    public static function get_base_salary(int $user_id): int
    {
        $value = get_user_meta($user_id, 'base_salary', true);
        return $value !== '' ? (int) $value : 0;
    }
}
