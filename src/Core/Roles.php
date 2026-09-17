<?php

declare(strict_types=1);

namespace IMS\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * カスタムロール・カスタム権限（capability）の定義。
 *
 * 設計方針（08 §7）：
 * ・ロール名（'approver' 等）を直接参照して分岐するのではなく、必ずカスタム権限
 *   （`ims_view_salary` 等）を current_user_can() で判定する。ロール再編に強く、
 *   機微データのゲートを堅牢化できる。
 * ・administrator は WordPress 標準ロールを流用し、全カスタム権限を自動付与する。
 */
final class Roles
{
    public const ROLE_GENERAL_STAFF = 'general_staff';
    public const ROLE_APPROVER      = 'approver';
    public const ROLE_HR_ADMIN      = 'hr_admin';
    // administrator は WordPress 標準ロールをそのまま使用する

    /**
     * カスタム権限一覧（01_user_management.md §3.2、08 §7 準拠）。
     * 新しい権限が必要になった場合はここに追記する。
     */
    private const CAPABILITIES = [
        // 全ロール共通の基本操作（自分のデータの打刻・入力・申請）
        'ims_use_portal' => [
            self::ROLE_GENERAL_STAFF,
            self::ROLE_APPROVER,
            self::ROLE_HR_ADMIN,
            'administrator',
        ],
        // 承認・差し戻し（自分の配下・担当分）
        'ims_approve' => [
            self::ROLE_APPROVER,
            self::ROLE_HR_ADMIN,
            'administrator',
        ],
        // 打刻修正機能の利用可否（02_time_tracking.md §3.4）。
        // approver だけは修正許可レベルの設定に関わらず常にボタン非表示という
        // 表に合わせるため、general_staff と approver を区別する capability。
        // hr_admin/administrator は ims_manage_users 側の「常に直接修正可」判定で
        // 別途上書きされるため、ここでの有無は general_staff/approver の分岐にのみ効く。
        'ims_correct_own_punch' => [
            self::ROLE_GENERAL_STAFF,
            self::ROLE_HR_ADMIN,
            'administrator',
        ],
        // 給与・手当情報の閲覧・編集（機微データ）
        'ims_view_salary' => [
            self::ROLE_HR_ADMIN,
            'administrator',
        ],
        // 各種マスタ・ランチャー管理
        'ims_manage_masters' => [
            self::ROLE_HR_ADMIN,
            'administrator',
        ],
        // 全社員データの閲覧・編集・CSV入出力
        'ims_manage_users' => [
            self::ROLE_HR_ADMIN,
            'administrator',
        ],
        // 月次締め処理の実行
        'ims_run_closing' => [
            self::ROLE_HR_ADMIN,
            'administrator',
        ],
        // システム全体設定（プラグイン設定・権限設定等）
        'ims_manage_system' => [
            'administrator',
        ],
    ];

    public static function init(): void
    {
        // WordPress 標準の管理画面「ユーザー」一覧でロール表示が崩れないよう、
        // 有効化時だけでなく、万一ロールが欠落していた場合にも起動時に補完する。
        add_action('admin_init', [self::class, 'ensure_roles_exist']);
    }

    public static function ensure_roles_exist(): void
    {
        if (!get_role(self::ROLE_GENERAL_STAFF)) {
            self::register_roles();
        }
    }

    /**
     * ロールと capability を登録する。何度呼んでも安全（べき等）。
     * 有効化フック（Installer::activate）と admin_init の両方から呼ばれる。
     */
    public static function register_roles(): void
    {
        add_role(self::ROLE_GENERAL_STAFF, '一般社員', ['read' => true]);
        add_role(self::ROLE_APPROVER, '承認者（上長）', ['read' => true]);
        add_role(self::ROLE_HR_ADMIN, '人事管理担当者', ['read' => true]);
        // administrator は既存のWP標準ロールを使用（作り直さない）

        foreach (self::CAPABILITIES as $cap => $roles) {
            foreach ($roles as $role_name) {
                $role = get_role($role_name);
                if ($role !== null && !$role->has_cap($cap)) {
                    $role->add_cap($cap);
                }
            }
        }

        // hr_admin は wp-admin にアクセス可能にする必要があるため、最低限の管理画面閲覧権限を付与
        $hr_admin = get_role(self::ROLE_HR_ADMIN);
        if ($hr_admin !== null) {
            $hr_admin->add_cap('read');
        }
    }

    /**
     * 指定ユーザーが wp-admin へのアクセスを許可されたロールかどうか。
     * general_staff / approver は禁止（AuthGuard が参照する）。
     */
    public static function can_access_wp_admin(\WP_User $user): bool
    {
        return $user->has_cap(self::ROLE_HR_ADMIN) || $user->has_cap('administrator');
    }
}
