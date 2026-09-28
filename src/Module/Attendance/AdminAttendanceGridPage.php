<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

use IMS\Module\User\AdminUserListPage;
use IMS\Module\User\EmployeeRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 「社員管理 > 勤務表の確認」wp-admin画面（03_attendance_management.md §4.2「事業別色分けグリッド表示」。3j-1）。
 *
 * 選んだ社員・年月の月次勤務表を、スタッフ向け画面（AttendanceGridPage）と同じ描画処理で
 * 読み取り専用表示する。見た目をスタッフ画面と完全に統一するため（§4.2）、グリッドと凡例の
 * HTMLは AttendanceGridPage::render_grid() / render_legend() をそのまま使い、ここで二重実装しない。
 * 凡例（事業名と色）はスクロールしても画面上部に残るようにする（§4.2「凡例を画面上部に常時表示」）。
 *
 * 権限：画面は ims_approve（approver以上）。表示できる社員は MonthlySummaryService::can_view_month()
 * で絞る（approverは担当する社員のみ、hr_admin以上は全員。§3.4）。
 *
 * 見送った点：承認・差し戻しはこの画面では行わず、「月次提出状況」画面で行う（既存の操作を二重に置かない）。
 */
final class AdminAttendanceGridPage
{
    public const MENU_SLUG = 'ims-attendance-grid';
    private const CAP      = 'ims_approve';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            AdminUserListPage::PARENT_SLUG,
            '勤務表の確認',
            '勤務表の確認',
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
        wp_enqueue_style('ims-attendance-grid', IMS_PORTAL_URL . 'assets/css/attendance-grid.css', ['ims-portal-tokens'], IMS_PORTAL_VERSION);
        wp_enqueue_style('ims-admin-attendance-grid', IMS_PORTAL_URL . 'assets/css/admin-attendance-grid.css', ['ims-attendance-grid'], IMS_PORTAL_VERSION);
    }

    /** 月次提出状況画面などからこの画面を開くURL。 */
    public static function url(int $user_id, string $year_month): string
    {
        return add_query_arg([
            'page'    => self::MENU_SLUG,
            'user_id' => $user_id,
            'ym'      => $year_month,
        ], admin_url('admin.php'));
    }

    public static function render(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('この操作を行う権限がありません。', 'ims-portal'), '', ['response' => 403]);
        }

        $actor_id   = get_current_user_id();
        $year_month = self::resolve_month();
        $employees  = array_values(array_filter(
            EmployeeRepository::list_employees(true),
            static fn(\WP_User $u): bool => MonthlySummaryService::can_view_month($actor_id, $u->ID)
        ));

        $target_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        $target    = null;
        foreach ($employees as $u) {
            if ($u->ID === $target_id) {
                $target = $u;
            }
        }
        ?>
        <div class="wrap ims-admin ims-ag-admin">
            <h1>勤務表の確認</h1>
            <p class="ims-note">
                社員が入力した月次勤務表を、本人の画面と同じ色分けで確認できます。
                色は事業ごとに分かれています（事業の色は「事業マスタ」で変更できます）。
                この画面では内容を変更できません。承認・差し戻しは「月次提出状況」から行ってください。
            </p>
            <?php self::render_selector($employees, $target_id, $year_month); ?>

            <?php if ($target_id !== 0 && $target === null) : ?>
                <div class="notice notice-error"><p>この社員の勤務表を表示する権限がありません。</p></div>
            <?php elseif ($target !== null) : ?>
                <?php self::render_target($target, $year_month); ?>
            <?php else : ?>
                <p class="ims-sub">表示する社員を選んでください。</p>
            <?php endif; ?>
        </div>
        <?php
    }

    /** @param array<int, \WP_User> $employees */
    private static function render_selector(array $employees, int $target_id, string $year_month): void
    {
        [$year, $month] = array_map('intval', explode('-', $year_month));
        ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="ims-list-filter ims-ag-admin-filter">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>">
            <label>
                社員
                <select name="user_id">
                    <option value="0">選んでください</option>
                    <?php foreach ($employees as $u) :
                        $code = (string) get_user_meta($u->ID, 'employee_code', true);
                        ?>
                        <option value="<?php echo (int) $u->ID; ?>" <?php selected($u->ID, $target_id); ?>>
                            <?php echo esc_html(($code !== '' ? $code . '　' : '') . $u->display_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                年月
                <input type="month" name="ym" value="<?php echo esc_attr($year_month); ?>">
            </label>
            <button type="submit" class="button button-primary">表示する</button>
            <?php if ($target_id !== 0) : ?>
                <a class="button" href="<?php echo esc_url(self::url($target_id, self::adjacent_month($year_month, -1))); ?>">‹ 前の月</a>
                <strong><?php echo esc_html(sprintf('%d年%d月分', $year, $month)); ?></strong>
                <a class="button" href="<?php echo esc_url(self::url($target_id, self::adjacent_month($year_month, 1))); ?>">次の月 ›</a>
            <?php endif; ?>
        </form>
        <?php
    }

    private static function render_target(\WP_User $target, string $year_month): void
    {
        $data   = AttendanceGridService::month_data($target->ID, $year_month);
        $status = MonthlySummaryService::status_summary($target->ID, $year_month);
        ?>
        <div class="ims-ag-admin-head">
            <strong class="ims-ag-admin-name"><?php echo esc_html($target->display_name); ?></strong>
            <span class="ims-ag-admin-period">
                <?php echo esc_html(sprintf(
                    '対象期間：%s 〜 %s',
                    date('n月j日', strtotime($data['period']['start'])),
                    date('n月j日', strtotime($data['period']['end']))
                )); ?>
            </span>
            <span class="ims-ag-admin-status ag-status-<?php echo esc_attr($status['status']); ?>">
                <span class="ag-status-label"><?php echo esc_html($status['label']); ?></span>
            </span>
            <a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'ims-monthly-submissions', 'ym' => $year_month], admin_url('admin.php'))); ?>">
                月次提出状況へ
            </a>
        </div>
        <?php if ($status['rejection_comment']) : ?>
            <p class="ims-ag-admin-comment">差し戻し理由：<?php echo esc_html($status['rejection_comment']); ?></p>
        <?php endif; ?>

        <div class="ag-wrap">
            <div class="ims-ag-admin-legend">
                <?php AttendanceGridPage::render_legend($data['businesses'], $data['business_totals']); ?>
            </div>
            <?php AttendanceGridPage::render_grid($data, false); ?>
        </div>
        <?php
    }

    private static function adjacent_month(string $year_month, int $offset): string
    {
        [$year, $month] = array_map('intval', explode('-', $year_month));
        return date('Y-m', mktime(0, 0, 0, $month + $offset, 1, $year));
    }

    /** 表示対象の年月（'Y-m'）。?ym=YYYY-MM を受け、不正なら締め日設定に基づく今日時点の対象年月。 */
    private static function resolve_month(): string
    {
        $ym = isset($_GET['ym']) ? sanitize_text_field(wp_unslash((string) $_GET['ym'])) : '';
        if (MonthlySummaryCalculator::is_valid_year_month($ym)) {
            [, $month] = array_map('intval', explode('-', $ym));
            if ($month >= 1 && $month <= 12) {
                return $ym;
            }
        }
        return SalaryCycleSettings::year_month_for_date(current_time('Y-m-d'));
    }
}
