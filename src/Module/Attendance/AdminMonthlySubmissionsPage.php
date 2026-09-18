<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

use IMS\Module\User\AdminUserListPage;
use IMS\Module\User\EmployeeRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 「社員管理 > 月次提出状況」wp-admin画面（03_attendance_management.md §4.3 の簡略版。3f-4c）。
 *
 * 全社員の指定年月の提出ステータスを一覧表示し、チェック承認・最終承認・差し戻しを
 * その場で行える最小実装。要件定義書§4.2（事業別色分けの監査用グリッド・PDF/弥生CSV
 * ダウンロード）はスコープ外（04モジュール・スナップショット等の前提が未整備のため）。
 *
 * 承認・差し戻しの権限判定・自己承認禁止・ステータス遷移の妥当性チェックは
 * すべて MonthlySummaryService に委譲し、ここでは行わない（AdminBusinessesPage と同方針）。
 *
 * 権限：一覧の閲覧は ims_approve（approver以上）。承認・差し戻しボタンの表示は
 * 行ごとに MonthlySummaryService::can_check_approve() / can_final_approve() で判定し、
 * 担当外・権限外の社員には操作ボタンを出さない（サーバー側の判定が最終防衛）。
 */
final class AdminMonthlySubmissionsPage
{
    private const MENU_SLUG = 'ims-monthly-submissions';
    private const CAP       = 'ims_approve';
    private const NONCE     = 'ims_monthly_summary_action';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_post_ims_monthly_check_approve', [self::class, 'handle_check_approve']);
        add_action('admin_post_ims_monthly_check_reject', [self::class, 'handle_check_reject']);
        add_action('admin_post_ims_monthly_final_approve', [self::class, 'handle_final_approve']);
        add_action('admin_post_ims_monthly_final_reject', [self::class, 'handle_final_reject']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            AdminUserListPage::PARENT_SLUG,
            '月次提出状況',
            '月次提出状況',
            self::CAP,
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

    public static function enqueue(string $hook): void
    {
        if (!str_contains($hook, self::MENU_SLUG)) {
            return;
        }
        wp_enqueue_style('ims-portal-tokens', IMS_PORTAL_URL . 'assets/css/ims-tokens.css', [], IMS_PORTAL_VERSION);
        wp_enqueue_style('ims-admin', IMS_PORTAL_URL . 'assets/css/admin.css', ['ims-portal-tokens'], IMS_PORTAL_VERSION);
    }

    // ── フォーム処理（admin-post.php） ─────────────────────────

    public static function handle_check_approve(): void
    {
        [$actor_id, $target_id, $year_month] = self::guarded_input();
        $result = MonthlySummaryService::check_approve($actor_id, $target_id, $year_month);
        self::finish($result, $year_month, 'check_approved');
    }

    public static function handle_check_reject(): void
    {
        [$actor_id, $target_id, $year_month] = self::guarded_input();
        $comment = sanitize_textarea_field(wp_unslash($_POST['comment'] ?? ''));
        $result  = MonthlySummaryService::check_reject($actor_id, $target_id, $year_month, $comment);
        self::finish($result, $year_month, 'check_rejected');
    }

    public static function handle_final_approve(): void
    {
        [$actor_id, $target_id, $year_month] = self::guarded_input();
        $result = MonthlySummaryService::final_approve($actor_id, $target_id, $year_month);
        self::finish($result, $year_month, 'final_approved');
    }

    public static function handle_final_reject(): void
    {
        [$actor_id, $target_id, $year_month] = self::guarded_input();
        $comment = sanitize_textarea_field(wp_unslash($_POST['comment'] ?? ''));
        $result  = MonthlySummaryService::final_reject($actor_id, $target_id, $year_month, $comment);
        self::finish($result, $year_month, 'final_rejected');
    }

    /** @return array{0:int, 1:int, 2:string} */
    private static function guarded_input(): array
    {
        self::guard();
        check_admin_referer(self::NONCE);

        $target_id  = (int) ($_POST['user_id'] ?? 0);
        $year_month = sanitize_text_field(wp_unslash($_POST['year_month'] ?? ''));
        return [get_current_user_id(), $target_id, $year_month];
    }

    /** @param true|\WP_Error $result */
    private static function finish($result, string $year_month, string $success_msg): void
    {
        if (is_wp_error($result)) {
            self::redirect($year_month, 'error', $result->get_error_message());
            return;
        }
        self::redirect($year_month, 'success', $success_msg);
    }

    // ── 画面描画 ───────────────────────────────────────────

    public static function render(): void
    {
        self::guard();

        $year_month = self::resolve_month();
        $employees  = EmployeeRepository::list_employees();
        $actor_id   = get_current_user_id();
        ?>
        <div class="wrap ims-admin">
            <h1>月次提出状況</h1>
            <p class="ims-note">
                各社員の月次勤務表の提出・承認状況を確認できます。チェック承認は担当のチェック者、
                最終承認は人事管理担当者以上が行えます。自分自身の月度は承認できません。
            </p>
            <?php self::render_notice(); ?>
            <?php self::render_month_nav($year_month); ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>社員番号</th>
                        <th>氏名</th>
                        <th>ステータス</th>
                        <th>差し戻し理由</th>
                        <th class="col-ops">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($employees)) : ?>
                        <tr><td colspan="5" class="ims-empty">該当する社員がいません。</td></tr>
                    <?php endif; ?>
                    <?php foreach ($employees as $u) :
                        $target_id = $u->ID;
                        $code      = get_user_meta($target_id, 'employee_code', true);
                        $status    = MonthlySummaryService::status_summary($target_id, $year_month);
                        ?>
                        <tr>
                            <td><?php echo esc_html($code !== '' ? $code : '—'); ?></td>
                            <td><strong><?php echo esc_html($u->display_name); ?></strong></td>
                            <td>
                                <span class="ims-chip" style="<?php echo esc_attr(self::status_chip_style($status['status'])); ?>">
                                    <?php echo esc_html($status['label']); ?>
                                </span>
                            </td>
                            <td><?php echo $status['rejection_comment'] ? esc_html($status['rejection_comment']) : '—'; ?></td>
                            <td class="col-ops"><?php self::render_actions($actor_id, $target_id, $year_month, $status); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /** @param array{status:string, label:string, is_editable:bool, rejection_comment:?string} $status */
    private static function render_actions(int $actor_id, int $target_id, string $year_month, array $status): void
    {
        if ($actor_id === $target_id) {
            echo '<span class="ims-sub">—</span>';
            return;
        }

        if ($status['status'] === MonthlySummaryCalculator::SUBMITTED
            && MonthlySummaryService::can_check_approve($actor_id, $target_id)
        ) {
            self::render_action_forms($target_id, $year_month, 'ims_monthly_check_approve', 'ims_monthly_check_reject', 'チェック承認');
            return;
        }

        if ($status['status'] === MonthlySummaryCalculator::CHECKED
            && MonthlySummaryService::can_final_approve()
        ) {
            self::render_action_forms($target_id, $year_month, 'ims_monthly_final_approve', 'ims_monthly_final_reject', '最終承認');
            return;
        }

        echo '<span class="ims-sub">—</span>';
    }

    private static function render_action_forms(int $target_id, string $year_month, string $approve_action, string $reject_action, string $approve_label): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ims-inline-form"
              onsubmit="return confirm('<?php echo esc_js($approve_label . 'します。よろしいですか？'); ?>');">
            <?php wp_nonce_field(self::NONCE); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr($approve_action); ?>">
            <input type="hidden" name="user_id" value="<?php echo (int) $target_id; ?>">
            <input type="hidden" name="year_month" value="<?php echo esc_attr($year_month); ?>">
            <button type="submit" class="button button-primary button-small"><?php echo esc_html($approve_label); ?></button>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              onsubmit="return confirm('差し戻します。よろしいですか？');">
            <?php wp_nonce_field(self::NONCE); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr($reject_action); ?>">
            <input type="hidden" name="user_id" value="<?php echo (int) $target_id; ?>">
            <input type="hidden" name="year_month" value="<?php echo esc_attr($year_month); ?>">
            <textarea name="comment" rows="2" class="ims-reject-comment" placeholder="差し戻し理由（必須）" required></textarea>
            <button type="submit" class="button button-small ims-btn-del">差し戻す</button>
        </form>
        <?php
    }

    /** 画面表示用のステータス別チップ色（非技術者向け。§4.3「ステータス別に色分けしたバッジ」）。 */
    private static function status_chip_style(string $status): string
    {
        return match ($status) {
            MonthlySummaryCalculator::SUBMITTED,
            MonthlySummaryCalculator::CHECKED             => 'background:var(--gold-tint);color:var(--gold);',
            MonthlySummaryCalculator::CONFIRMED            => 'background:var(--moss-tint);color:var(--moss);',
            MonthlySummaryCalculator::REJECTED_BY_CHECKER,
            MonthlySummaryCalculator::REJECTED_BY_ADMIN    => 'background:var(--vermillion-tint);color:var(--vermillion);',
            default                                         => 'background:var(--line-soft);color:var(--sub);',
        };
    }

    private static function render_month_nav(string $year_month): void
    {
        [$year, $month] = array_map('intval', explode('-', $year_month));
        $prev = self::adjacent_month($year_month, -1);
        $next = self::adjacent_month($year_month, 1);
        ?>
        <p class="ims-list-filter">
            <a class="button" href="<?php echo esc_url(self::month_url($prev)); ?>">‹ 前の月</a>
            <strong><?php echo esc_html(sprintf('%d年%d月分', $year, $month)); ?></strong>
            <a class="button" href="<?php echo esc_url(self::month_url($next)); ?>">次の月 ›</a>
        </p>
        <?php
    }

    private static function adjacent_month(string $year_month, int $offset): string
    {
        [$year, $month] = array_map('intval', explode('-', $year_month));
        $ts = mktime(0, 0, 0, $month + $offset, 1, $year);
        return date('Y-m', $ts);
    }

    private static function month_url(string $ym): string
    {
        return add_query_arg(['page' => self::MENU_SLUG, 'ym' => $ym], admin_url('admin.php'));
    }

    /** 表示対象の年月（'Y-m'）。?ym=YYYY-MM を受け、不正なら締め日設定に基づく今日時点の対象年月。 */
    private static function resolve_month(): string
    {
        $ym = isset($_GET['ym']) ? sanitize_text_field(wp_unslash((string) $_GET['ym'])) : '';
        if (MonthlySummaryCalculator::is_valid_year_month($ym)) {
            return $ym;
        }
        return SalaryCycleSettings::year_month_for_date(current_time('Y-m-d'));
    }

    private static function render_notice(): void
    {
        $status = sanitize_key($_GET['ims_notice'] ?? '');
        if ($status === '') {
            return;
        }
        $msg = sanitize_text_field(wp_unslash($_GET['ims_msg'] ?? ''));

        $labels = [
            'check_approved' => 'チェック承認しました。',
            'check_rejected' => '差し戻しました。',
            'final_approved' => '最終承認しました。',
            'final_rejected' => '差し戻しました。',
        ];
        $text  = $labels[$msg] ?? $msg;
        $class = $status === 'success' ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($text) . '</p></div>';
    }

    private static function guard(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('この操作を行う権限がありません。', 'ims-portal'), '', ['response' => 403]);
        }
    }

    private static function redirect(string $year_month, string $status, string $msg): void
    {
        wp_safe_redirect(add_query_arg([
            'page'       => self::MENU_SLUG,
            'ym'         => $year_month,
            'ims_notice' => $status,
            'ims_msg'    => rawurlencode($msg),
        ], admin_url('admin.php')));
        exit;
    }
}
