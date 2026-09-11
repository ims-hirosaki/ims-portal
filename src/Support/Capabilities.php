<?php

declare(strict_types=1);

namespace IMS\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST permission_callback やテンプレート内での権限判定を簡潔にするヘルパー。
 * ロール名ではなくカスタム権限（08 §7）で判定することを徹底するための薄いラッパー。
 */
final class Capabilities
{
    public static function can_use_portal(): bool
    {
        return is_user_logged_in() && current_user_can('ims_use_portal');
    }

    public static function can_approve(): bool
    {
        return current_user_can('ims_approve');
    }

    public static function can_view_salary(): bool
    {
        return current_user_can('ims_view_salary');
    }

    public static function can_manage_masters(): bool
    {
        return current_user_can('ims_manage_masters');
    }

    public static function can_manage_users(): bool
    {
        return current_user_can('ims_manage_users');
    }

    /** 打刻修正機能を利用できるか（02_time_tracking.md §3.4）。approver は false。 */
    public static function can_correct_own_punch(): bool
    {
        return current_user_can('ims_correct_own_punch');
    }

    public static function can_run_closing(): bool
    {
        return current_user_can('ims_run_closing');
    }
}
