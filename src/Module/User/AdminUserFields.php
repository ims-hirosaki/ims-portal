<?php

declare(strict_types=1);

namespace IMS\Module\User;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WordPress 標準のユーザー編集画面に、業務用カードセクションを差し込む
 * （01_user_management.md §5.1.1）。コアのメール・氏名・パスワード管理はそのまま活用し、
 * 業務項目（社員番号・認証方式・所属・承認者・給与手当）だけを追加する。
 *
 * カード構成：
 *   ① 基本情報・ログイン設定（社員番号・認証方式）
 *   ② 所属情報（所属/部署/役職/職種/雇用形態/在籍状況/所定労働時間）
 *   ③ 申請の承認者設定（確認者・最終承認者）
 *   ④ 給与・手当設定（ims_view_salary 権限保有者のみ表示・保存）
 *
 * 検証は user_profile_update_errors で行い（エラー時は保存を中断）、
 * 保存は edit_user_profile_update / personal_options_update で行う。
 * これらは WordPress コアが update-user nonce を検証した後にのみ発火する。
 */
final class AdminUserFields
{
    private const CAP_EDIT      = 'ims_manage_users';
    private const CAP_SENSITIVE = 'ims_view_salary';

    public static function init(): void
    {
        add_action('show_user_profile', [self::class, 'render']);
        add_action('edit_user_profile', [self::class, 'render']);

        add_action('user_profile_update_errors', [self::class, 'validate'], 10, 3);
        add_action('personal_options_update', [self::class, 'save']);
        add_action('edit_user_profile_update', [self::class, 'save']);

        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function enqueue(string $hook): void
    {
        if (!in_array($hook, ['user-edit.php', 'profile.php', 'user-new.php'], true)) {
            return;
        }
        wp_enqueue_style('ims-portal-tokens', IMS_PORTAL_URL . 'assets/css/ims-tokens.css', [], IMS_PORTAL_VERSION);
        wp_enqueue_style('ims-admin', IMS_PORTAL_URL . 'assets/css/admin.css', ['ims-portal-tokens'], IMS_PORTAL_VERSION);
        wp_enqueue_script('ims-admin', IMS_PORTAL_URL . 'assets/js/admin.js', [], IMS_PORTAL_VERSION, true);
    }

    // ── 描画 ───────────────────────────────────────────────

    public static function render(\WP_User $user): void
    {
        if (!current_user_can(self::CAP_EDIT)) {
            return;
        }
        $uid = $user->ID;

        // ① 基本情報・ログイン設定
        $employee_code = get_user_meta($uid, 'employee_code', true);
        $auth_type     = get_user_meta($uid, 'auth_type', true) === 'google_sso' ? 'google_sso' : 'password';
        ?>
        <div class="ims-admin ims-user-cards">
            <div class="ims-user-card" data-auth="<?php echo esc_attr($auth_type); ?>">
                <h2>基本情報・ログイン設定</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="ims_employee_code">社員番号 <span class="ims-req">*</span></label></th>
                        <td><input type="text" id="ims_employee_code" name="ims_employee_code" class="regular-text"
                                   value="<?php echo esc_attr($employee_code); ?>">
                            <p class="description">一意の社員番号。他モジュールの突合キーになります。</p></td>
                    </tr>
                    <tr>
                        <th>ログイン方法</th>
                        <td>
                            <label><input type="radio" name="ims_auth_type" value="google_sso" class="ims-auth-radio"
                                <?php checked($auth_type, 'google_sso'); ?>> Google アカウント（@ims-hirosaki.com）</label><br>
                            <label><input type="radio" name="ims_auth_type" value="password" class="ims-auth-radio"
                                <?php checked($auth_type, 'password'); ?>> ID・パスワード</label>
                            <p class="description ims-auth-hint">
                                Google アカウントを選んだ社員は WordPress パスワードを使用しません（下のパスワード欄は非表示になります）。
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <?php self::render_org_card($uid); ?>
            <?php self::render_approver_card($user); ?>
            <?php if (current_user_can(self::CAP_SENSITIVE)) : ?>
                <?php self::render_sensitive_card($uid); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_org_card(int $uid): void
    {
        $status = get_user_meta($uid, 'employment_status', true) ?: '在籍';
        $hours  = get_user_meta($uid, 'scheduled_work_hours', true);
        $hours  = $hours !== '' ? $hours : '8.00';
        ?>
        <div class="ims-user-card">
            <h2>所属情報</h2>
            <table class="form-table" role="presentation">
                <tr><th><label>所属</label></th><td><?php self::master_select('ims_affiliation_id', 'affiliation', (int) get_user_meta($uid, 'affiliation_id', true)); ?></td></tr>
                <tr><th><label>部署</label></th><td><?php self::master_select('ims_department_id', 'department', (int) get_user_meta($uid, 'department_id', true)); ?></td></tr>
                <tr><th><label>役職</label></th><td><?php self::master_select('ims_position_id', 'position', (int) get_user_meta($uid, 'position_id', true)); ?></td></tr>
                <tr><th><label>職種</label></th><td><?php self::master_select('ims_job_type_id', 'job_type', (int) get_user_meta($uid, 'job_type_id', true)); ?></td></tr>
                <tr><th><label>雇用形態</label></th><td><?php self::master_select('ims_employment_type_id', 'employment_type', (int) get_user_meta($uid, 'employment_type_id', true)); ?></td></tr>
                <tr>
                    <th><label for="ims_employment_status">在籍状況</label></th>
                    <td>
                        <select id="ims_employment_status" name="ims_employment_status">
                            <?php foreach (EmployeeRepository::EMPLOYMENT_STATUSES as $s) : ?>
                                <option value="<?php echo esc_attr($s); ?>" <?php selected($status, $s); ?>><?php echo esc_html($s); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">「退職」への変更後の無効化処理は退職者管理（実装予定）で行います。</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="ims_scheduled_work_hours">所定労働時間 / 日</label></th>
                    <td><input type="number" step="0.25" min="0" max="24" id="ims_scheduled_work_hours"
                               name="ims_scheduled_work_hours" value="<?php echo esc_attr($hours); ?>" class="small-text"> 時間</td>
                </tr>
            </table>
        </div>
        <?php
    }

    private static function render_approver_card(\WP_User $user): void
    {
        $uid   = $user->ID;
        $first = (int) get_user_meta($uid, 'first_approver_id', true);
        $final = (int) get_user_meta($uid, 'final_approver_id', true);
        ?>
        <div class="ims-user-card">
            <h2>申請の承認者設定</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="ims_first_approver_id">確認者（第1承認者）</label></th>
                    <td><?php self::user_select('ims_first_approver_id', EmployeeRepository::first_approver_candidates(), $first, $uid); ?>
                        <p class="description">承認者（上長）以上の権限を持つ社員から選択します。本人は選べません。</p></td>
                </tr>
                <tr>
                    <th><label for="ims_final_approver_id">最終承認者（第2承認者）</label></th>
                    <td><?php self::user_select('ims_final_approver_id', EmployeeRepository::final_approver_candidates(), $final, $uid); ?>
                        <p class="description">人事管理担当者以上の権限を持つ社員から選択します。</p></td>
                </tr>
            </table>
        </div>
        <?php
    }

    private static function render_sensitive_card(int $uid): void
    {
        $current_salary = EmployeeRepository::current_base_salary($uid);
        $travel = get_user_meta($uid, 'travel_unit_price', true);
        $commute = get_user_meta($uid, 'commute_one_way_km', true);
        $history = EmployeeRepository::salary_history($uid, 5);
        $today  = current_time('Y-m-d');
        ?>
        <div class="ims-user-card ims-card-sensitive">
            <h2>給与・手当設定 <span class="ims-lock">🔒 人事・管理者のみ</span></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="ims_base_salary">基本給（現在値）</label></th>
                    <td>
                        <input type="number" id="ims_base_salary" name="ims_base_salary" class="regular-text"
                               value="<?php echo esc_attr((string) $current_salary); ?>"> 円
                        <p class="description">金額を変更して保存すると、下の適用開始日で履歴に記録されます。</p>
                        <p>
                            適用開始日 <input type="date" name="ims_base_salary_effective_date" value="<?php echo esc_attr($today); ?>">
                            　備考 <input type="text" name="ims_base_salary_note" class="regular-text" placeholder="昇給・改定理由など（任意）">
                        </p>
                        <?php if ($history) : ?>
                            <details class="ims-history">
                                <summary>変更履歴（直近<?php echo count($history); ?>件）</summary>
                                <ul>
                                    <?php foreach ($history as $h) : ?>
                                        <li><?php echo esc_html($h['effective_date']); ?>：
                                            <?php echo esc_html(number_format((int) $h['base_salary'])); ?> 円
                                            <?php if (!empty($h['note'])) : ?>（<?php echo esc_html($h['note']); ?>）<?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="ims_travel_unit_price">交通費単価</label></th>
                    <td><input type="number" step="0.01" id="ims_travel_unit_price" name="ims_travel_unit_price"
                               value="<?php echo esc_attr($travel); ?>" class="small-text"> 円/km</td>
                </tr>
                <tr>
                    <th><label for="ims_commute_one_way_km">通勤片道距離</label></th>
                    <td><input type="number" step="0.01" id="ims_commute_one_way_km" name="ims_commute_one_way_km"
                               value="<?php echo esc_attr($commute); ?>" class="small-text"> km
                        <p class="description">直行直帰対応のため片道距離で管理します。</p></td>
                </tr>
                <?php self::render_allowances_row($uid, $today); ?>
            </table>
        </div>
        <?php
    }

    private static function render_allowances_row(int $uid, string $today): void
    {
        $allowances = MasterRepository::all('allowance', false); // 有効な手当種類のみ
        if (empty($allowances)) {
            return;
        }
        ?>
        <tr>
            <th><label>手当設定</label></th>
            <td>
                <table class="ims-allowance-table widefat striped">
                    <thead><tr><th>手当</th><th>金額（円）</th><th>適用開始日</th></tr></thead>
                    <tbody>
                        <?php foreach ($allowances as $a) :
                            $mid = (int) $a['id'];
                            $cur = EmployeeRepository::current_allowance_amount($uid, $mid);
                            ?>
                            <tr>
                                <td><?php echo esc_html($a['allowance_name']); ?></td>
                                <td><input type="number" name="ims_allowance_amount[<?php echo $mid; ?>]"
                                           value="<?php echo $cur === null ? '' : esc_attr((string) $cur); ?>" class="small-text"></td>
                                <td><input type="date" name="ims_allowance_date[<?php echo $mid; ?>]" value="<?php echo esc_attr($today); ?>"></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description">金額を変更した手当のみ、適用開始日付きで履歴に追記されます。</p>
            </td>
        </tr>
        <?php
    }

    // ── 検証 ───────────────────────────────────────────────

    /**
     * @param \WP_Error $errors
     */
    public static function validate($errors, bool $update, $user): void
    {
        if (!current_user_can(self::CAP_EDIT) || !isset($user->ID)) {
            return;
        }
        $uid = (int) $user->ID;

        // 社員番号の一意性
        $code = sanitize_text_field(wp_unslash($_POST['ims_employee_code'] ?? ''));
        if ($code !== '' && EmployeeRepository::employee_code_exists($code, $uid)) {
            $errors->add('ims_employee_code', '<strong>エラー</strong>：その社員番号は既に使用されています。');
        }

        // Google SSO のドメイン制限
        $auth = ($_POST['ims_auth_type'] ?? 'password') === 'google_sso' ? 'google_sso' : 'password';
        $email = sanitize_email(wp_unslash($_POST['email'] ?? $user->user_email ?? ''));
        if ($auth === 'google_sso' && $email !== '' && !str_ends_with(strtolower($email), '@ims-hirosaki.com')) {
            $errors->add('ims_auth_type', '<strong>エラー</strong>：Google アカウント認証には @ims-hirosaki.com のメールアドレスが必要です。');
        }

        // 承認者の自己参照・権限レベル
        $first = (int) ($_POST['ims_first_approver_id'] ?? 0);
        $final = (int) ($_POST['ims_final_approver_id'] ?? 0);
        if ($first === $uid && $first !== 0) {
            $errors->add('ims_first_approver_id', '<strong>エラー</strong>：確認者に本人は設定できません。');
        }
        if ($final === $uid && $final !== 0) {
            $errors->add('ims_final_approver_id', '<strong>エラー</strong>：最終承認者に本人は設定できません。');
        }
        if ($first > 0) {
            $u = get_userdata($first);
            if (!$u || !$u->has_cap('ims_approve')) {
                $errors->add('ims_first_approver_id', '<strong>エラー</strong>：確認者は承認者以上の権限が必要です。');
            }
        }
        if ($final > 0) {
            $u = get_userdata($final);
            if (!$u || !$u->has_cap('ims_manage_users')) {
                $errors->add('ims_final_approver_id', '<strong>エラー</strong>：最終承認者は人事管理担当者以上の権限が必要です。');
            }
        }
    }

    // ── 保存 ───────────────────────────────────────────────

    public static function save(int $user_id): void
    {
        if (!current_user_can(self::CAP_EDIT)) {
            return;
        }

        EmployeeRepository::save_basic_and_org($user_id, [
            'employee_code'        => sanitize_text_field(wp_unslash($_POST['ims_employee_code'] ?? '')),
            'auth_type'            => $_POST['ims_auth_type'] ?? 'password',
            'affiliation_id'       => $_POST['ims_affiliation_id'] ?? 0,
            'department_id'        => $_POST['ims_department_id'] ?? 0,
            'position_id'          => $_POST['ims_position_id'] ?? 0,
            'job_type_id'          => $_POST['ims_job_type_id'] ?? 0,
            'employment_type_id'   => $_POST['ims_employment_type_id'] ?? 0,
            'employment_status'    => sanitize_text_field(wp_unslash($_POST['ims_employment_status'] ?? '在籍')),
            'scheduled_work_hours' => $_POST['ims_scheduled_work_hours'] ?? 8.0,
        ]);

        EmployeeRepository::save_approvers($user_id, [
            'first_approver_id' => $_POST['ims_first_approver_id'] ?? 0,
            'final_approver_id' => $_POST['ims_final_approver_id'] ?? 0,
        ]);

        // 給与・手当は ims_view_salary 保有者のみ保存（権限のないユーザーの画面には
        // そもそもフィールドが出ないが、二重に防御する）
        if (current_user_can(self::CAP_SENSITIVE)) {
            EmployeeRepository::save_sensitive($user_id, [
                'base_salary'                 => $_POST['ims_base_salary'] ?? '',
                'base_salary_effective_date'  => sanitize_text_field(wp_unslash($_POST['ims_base_salary_effective_date'] ?? '')),
                'base_salary_note'            => sanitize_text_field(wp_unslash($_POST['ims_base_salary_note'] ?? '')),
                'travel_unit_price'           => $_POST['ims_travel_unit_price'] ?? 0,
                'commute_one_way_km'          => $_POST['ims_commute_one_way_km'] ?? 0,
                'allowance_amount'            => $_POST['ims_allowance_amount'] ?? [],
                'allowance_date'              => $_POST['ims_allowance_date'] ?? [],
            ], get_current_user_id());
        }
    }

    // ── 部品 ───────────────────────────────────────────────

    private static function master_select(string $name, string $type, int $selected): void
    {
        $rows = MasterRepository::all($type, false); // 有効項目のみ
        // 選択中の項目が停止済みでも、現状値は選択肢に残す
        $selected_row = $selected > 0 ? MasterRepository::find($type, $selected) : null;
        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        if ($selected_row && !in_array($selected, $ids, true)) {
            $rows[] = $selected_row;
        }
        $name_col = MasterRepository::config($type)['name_col'];
        echo '<select name="' . esc_attr($name) . '"><option value="0">（未指定）</option>';
        foreach ($rows as $r) {
            printf(
                '<option value="%d" %s>%s</option>',
                (int) $r['id'],
                selected($selected, (int) $r['id'], false),
                esc_html($r[$name_col] ?? '')
            );
        }
        echo '</select>';
    }

    /**
     * @param array<int, \WP_User> $candidates
     */
    private static function user_select(string $name, array $candidates, int $selected, int $exclude_id): void
    {
        echo '<select name="' . esc_attr($name) . '"><option value="0">（未設定）</option>';
        foreach ($candidates as $u) {
            if ($u->ID === $exclude_id) {
                continue; // 本人は候補から除外
            }
            $code = get_user_meta($u->ID, 'employee_code', true);
            $label = $u->display_name . ($code ? " ({$code})" : '');
            printf(
                '<option value="%d" %s>%s</option>',
                $u->ID,
                selected($selected, $u->ID, false),
                esc_html($label)
            );
        }
        echo '</select>';
    }
}
