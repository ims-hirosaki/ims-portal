<?php

declare(strict_types=1);

namespace IMS\Module\User;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 社員（WordPressユーザー）の業務属性の読み書きと検証（01_user_management.md §3.3〜3.4）。
 *
 * ・基本属性は wp_usermeta に保存する（Support\UserRepository は横断参照用の薄い読取
 *   アクセサ。こちらは 01 モジュール内の書込・検証・履歴管理を担う）。
 * ・基本給は変更のたびに wp_salary_history に追記し、usermeta の base_salary を現在値として更新する。
 * ・手当金額は wp_user_allowances に適用開始日付きで追記する。現在値は
 *   「effective_date <= 本日 の最新1件」で解決する。
 */
final class EmployeeRepository
{
    /** usermeta に保存する基本属性のキー一覧 */
    public const ORG_KEYS = ['affiliation_id', 'department_id', 'position_id', 'job_type_id', 'employment_type_id'];

    public const EMPLOYMENT_STATUSES = ['在籍', '休職', '育児休業中', '出向', '退職'];

    // ── 一覧 ───────────────────────────────────────────────

    /**
     * 社員一覧（社員管理画面用）。退職者はデフォルト除外。
     * @return array<int, \WP_User>
     */
    public static function list_employees(bool $include_retired = false): array
    {
        $users = get_users([
            'orderby' => 'ID',
            'order'   => 'ASC',
            'number'  => -1,
        ]);

        if ($include_retired) {
            return $users;
        }

        return array_values(array_filter($users, static function (\WP_User $u): bool {
            return get_user_meta($u->ID, 'employment_status', true) !== '退職';
        }));
    }

    // ── 承認者候補 ─────────────────────────────────────────

    /**
     * 確認者（第1承認者）候補：approver 以上（approver / hr_admin / administrator）。
     * @return array<int, \WP_User>
     */
    public static function first_approver_candidates(): array
    {
        return get_users([
            'role__in' => ['approver', 'hr_admin', 'administrator'],
            'orderby'  => 'display_name',
        ]) ?: [];
    }

    /**
     * 最終承認者（第2承認者）候補：hr_admin 以上（hr_admin / administrator）。
     * @return array<int, \WP_User>
     */
    public static function final_approver_candidates(): array
    {
        return get_users(['role__in' => ['hr_admin', 'administrator'], 'orderby' => 'display_name']) ?: [];
    }

    // ── 属性の保存（検証つき） ───────────────────────────────

    /**
     * 基本情報・所属情報を保存する。
     * @return true|\WP_Error
     */
    public static function save_basic_and_org(int $user_id, array $input)
    {
        // 社員番号（一意）
        $employee_code = trim((string) ($input['employee_code'] ?? ''));
        if ($employee_code !== '' && self::employee_code_exists($employee_code, $user_id)) {
            return new \WP_Error('validation', 'その社員番号は既に使用されています。');
        }
        update_user_meta($user_id, 'employee_code', $employee_code);

        // 認証方式
        $auth_type = ($input['auth_type'] ?? 'password') === 'google_sso' ? 'google_sso' : 'password';
        update_user_meta($user_id, 'auth_type', $auth_type);

        // 所属系ID
        foreach (self::ORG_KEYS as $key) {
            $val = (int) ($input[$key] ?? 0);
            update_user_meta($user_id, $key, $val > 0 ? $val : '');
        }

        // 在籍状況
        $status = (string) ($input['employment_status'] ?? '在籍');
        if (!in_array($status, self::EMPLOYMENT_STATUSES, true)) {
            $status = '在籍';
        }
        update_user_meta($user_id, 'employment_status', $status);

        // 所定労働時間
        $hours = (float) ($input['scheduled_work_hours'] ?? 8.0);
        update_user_meta($user_id, 'scheduled_work_hours', number_format($hours, 2, '.', ''));

        return true;
    }

    /**
     * 承認者設定を保存する。自己承認禁止・権限レベル検証つき。
     * @return true|\WP_Error
     */
    public static function save_approvers(int $user_id, array $input)
    {
        $first = (int) ($input['first_approver_id'] ?? 0);
        $final = (int) ($input['final_approver_id'] ?? 0);

        // 自己承認の禁止
        if ($first === $user_id && $first !== 0) {
            return new \WP_Error('validation', '確認者に本人を設定することはできません。');
        }
        if ($final === $user_id && $final !== 0) {
            return new \WP_Error('validation', '最終承認者に本人を設定することはできません。');
        }

        // 権限レベルの検証
        if ($first > 0) {
            $u = get_userdata($first);
            if (!$u || !$u->has_cap('ims_approve')) {
                return new \WP_Error('validation', '確認者は承認者（上長）以上の権限を持つ社員を指定してください。');
            }
        }
        if ($final > 0) {
            $u = get_userdata($final);
            if (!$u || !$u->has_cap('ims_manage_users')) {
                return new \WP_Error('validation', '最終承認者は人事管理担当者以上の権限を持つ社員を指定してください。');
            }
        }

        update_user_meta($user_id, 'first_approver_id', $first > 0 ? $first : '');
        update_user_meta($user_id, 'final_approver_id', $final > 0 ? $final : '');
        return true;
    }

    /**
     * 給与・手当（センシティブ）を保存する。呼び出し側で ims_view_salary を必ず確認すること。
     * @return true|\WP_Error
     */
    public static function save_sensitive(int $user_id, array $input, int $operator_id)
    {
        // 交通費単価・通勤片道距離
        update_user_meta($user_id, 'travel_unit_price', number_format((float) ($input['travel_unit_price'] ?? 0), 2, '.', ''));
        update_user_meta($user_id, 'commute_one_way_km', number_format((float) ($input['commute_one_way_km'] ?? 0), 2, '.', ''));

        // 基本給：入力があり、かつ現在値と異なる場合のみ履歴追記
        $new_salary = isset($input['base_salary']) && $input['base_salary'] !== ''
            ? (int) $input['base_salary'] : null;
        if ($new_salary !== null) {
            $current = self::current_base_salary($user_id);
            $eff = self::sanitize_date($input['base_salary_effective_date'] ?? '') ?: current_time('Y-m-d');
            if ($new_salary !== $current) {
                self::append_salary_history($user_id, $new_salary, $eff, (string) ($input['base_salary_note'] ?? ''), $operator_id);
            }
        }

        // 手当金額：allowance_amount[master_id] = 金額 / allowance_date[master_id] = 適用日
        $amounts = (array) ($input['allowance_amount'] ?? []);
        $dates   = (array) ($input['allowance_date'] ?? []);
        foreach ($amounts as $master_id => $amount_raw) {
            $master_id = (int) $master_id;
            if ($amount_raw === '' ) {
                continue;
            }
            $amount  = (int) $amount_raw;
            $current = self::current_allowance_amount($user_id, $master_id);
            if ($current !== null && $current === $amount) {
                continue; // 変更なし
            }
            $eff = self::sanitize_date($dates[$master_id] ?? '') ?: current_time('Y-m-d');
            self::append_allowance($user_id, $master_id, $amount, $eff, $operator_id);
        }

        return true;
    }

    // ── 給与・手当の読取／追記 ───────────────────────────────

    public static function current_base_salary(int $user_id): int
    {
        return (int) get_user_meta($user_id, 'base_salary', true);
    }

    public static function append_salary_history(int $user_id, int $amount, string $effective_date, string $note, int $operator_id): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'salary_history', [
            'user_id'        => $user_id,
            'base_salary'    => $amount,
            'effective_date' => $effective_date,
            'note'           => $note !== '' ? $note : null,
            'created_by'     => $operator_id,
        ]);
        update_user_meta($user_id, 'base_salary', $amount);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function salary_history(int $user_id, int $limit = 10): array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'salary_history';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE user_id = %d ORDER BY effective_date DESC, id DESC LIMIT %d",
            $user_id,
            $limit
        ), ARRAY_A) ?: [];
    }

    public static function current_allowance_amount(int $user_id, int $master_id): ?int
    {
        global $wpdb;
        $t = $wpdb->prefix . 'user_allowances';
        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT amount FROM {$t}
             WHERE user_id = %d AND allowance_master_id = %d AND effective_date <= %s
             ORDER BY effective_date DESC, id DESC LIMIT 1",
            $user_id,
            $master_id,
            current_time('Y-m-d')
        ));
        return $val === null ? null : (int) $val;
    }

    public static function append_allowance(int $user_id, int $master_id, int $amount, string $effective_date, int $operator_id): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'user_allowances', [
            'user_id'             => $user_id,
            'allowance_master_id' => $master_id,
            'amount'              => $amount,
            'effective_date'      => $effective_date,
            'created_by'          => $operator_id,
        ]);
    }

    // ── ヘルパー ───────────────────────────────────────────

    public static function employee_code_exists(string $code, int $exclude_user_id = 0): bool
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'employee_code' AND meta_value = %s",
            $code
        ));
        foreach ($ids as $id) {
            if ((int) $id !== $exclude_user_id) {
                return true;
            }
        }
        return false;
    }

    /** 所属・部署名の解決（一覧表示用） */
    public static function org_label(int $user_id): string
    {
        $aff_id  = (int) get_user_meta($user_id, 'affiliation_id', true);
        $dept_id = (int) get_user_meta($user_id, 'department_id', true);
        $parts = [];
        if ($aff_id > 0) {
            $row = MasterRepository::find('affiliation', $aff_id);
            if ($row) {
                $parts[] = $row['name'];
            }
        }
        if ($dept_id > 0) {
            $row = MasterRepository::find('department', $dept_id);
            if ($row) {
                $parts[] = $row['name'];
            }
        }
        return $parts ? implode(' / ', $parts) : '—';
    }

    private static function sanitize_date(string $date): string
    {
        $date = trim($date);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
    }
}
