<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

use IMS\Module\User\EmployeeRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 印刷用勤務表（03_attendance_management.md §4.2「PDF 印刷用出力：1人1ページ形式でタイムカード＋
 * 事業別明細を印刷レイアウトで出力」。3k）。
 *
 * PDFファイルをサーバーで生成せず、1人1ページ（A4横）の印刷用ページを表示し、ブラウザの
 * 「印刷 → PDFとして保存」でPDFにする方式とした（ユーザー確認済み）。サーバーにPDFライブラリが
 * 無く（Composer不可）、日本語フォントの同梱も重いため。色分けグリッドはスタッフ画面と同じ
 * AttendanceGridPage::render_grid() をそのまま使うので、画面と印刷物の見た目が一致する。
 *
 * 呼び出し方（admin-post.php?action=ims_attendance_print）：
 * ・user_id を指定 … その社員1人分。MonthlySummaryService::can_view_month() で閲覧できる社員のみ。
 *   ステータスは問わない（確定前の確認用にも使える。ページ上にステータスを明記する）。
 * ・user_id を省略 … その月が「確定済み」の社員全員分（§4.2「承認済みデータ」）。
 *   給与データの一括出力にあたるため、最終承認できる権限（人事管理担当者以上）に限る（弥生CSVと同じ）。
 */
final class AttendancePrintPage
{
    public const ACTION = 'ims_attendance_print';
    private const NONCE = 'ims_attendance_print';
    private const CAP   = 'ims_approve';

    public static function init(): void
    {
        add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
    }

    /** 印刷用ページのURL。$user_id = 0 は確定済み全員分。 */
    public static function url(string $year_month, int $user_id = 0): string
    {
        $args = ['action' => self::ACTION, 'ym' => $year_month];
        if ($user_id > 0) {
            $args['user_id'] = $user_id;
        }
        return wp_nonce_url(add_query_arg($args, admin_url('admin-post.php')), self::NONCE);
    }

    public static function handle(): void
    {
        if (!current_user_can(self::CAP)) {
            self::deny();
        }
        check_admin_referer(self::NONCE);

        $actor_id   = get_current_user_id();
        $year_month = sanitize_text_field(wp_unslash($_GET['ym'] ?? ''));
        if (!MonthlySummaryCalculator::is_valid_year_month($year_month)) {
            wp_die(esc_html__('対象年月の形式が正しくありません。', 'ims-portal'), '', ['response' => 400]);
        }

        $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        if ($user_id > 0) {
            $user = get_userdata($user_id);
            if (!$user || !MonthlySummaryService::can_view_month($actor_id, $user_id)) {
                self::deny();
            }
            $targets = [$user];
        } else {
            if (!MonthlySummaryService::can_final_approve()) {
                self::deny();
            }
            $targets = self::confirmed_employees($year_month);
        }

        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        self::render_document($year_month, $targets, $user_id === 0);
        exit;
    }

    /** @return array<int, \WP_User> 指定年月が確定済みの社員（退職者を含む。社員番号順）。 */
    private static function confirmed_employees(string $year_month): array
    {
        $targets = [];
        foreach (EmployeeRepository::list_employees(true) as $u) {
            $summary = MonthlySummaryRepository::find($u->ID, $year_month);
            if ($summary !== null && (string) $summary['status'] === MonthlySummaryCalculator::CONFIRMED) {
                $targets[(string) get_user_meta($u->ID, 'employee_code', true) . "\0" . $u->ID] = $u;
            }
        }
        ksort($targets, SORT_STRING);
        return array_values($targets);
    }

    /** @param array<int, \WP_User> $targets */
    private static function render_document(string $year_month, array $targets, bool $is_batch): void
    {
        [$year, $month] = array_map('intval', explode('-', $year_month));
        $title = sprintf('月次勤務表 %d年%d月分', $year, $month);
        $css   = static fn(string $file): string => IMS_PORTAL_URL . 'assets/css/' . $file . '?ver=' . rawurlencode(IMS_PORTAL_VERSION);
        ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html($title); ?></title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Shippori+Mincho:wght@600;700&family=Zen+Kaku+Gothic+New:wght@400;500;700&display=swap">
<link rel="stylesheet" href="<?php echo esc_url($css('ims-tokens.css')); ?>">
<link rel="stylesheet" href="<?php echo esc_url($css('attendance-grid.css')); ?>">
<link rel="stylesheet" href="<?php echo esc_url($css('attendance-print.css')); ?>">
</head>
<body class="ap-print">
<div class="ap-toolbar">
    <button type="button" class="ap-print-btn" onclick="window.print()">印刷する／PDFとして保存</button>
    <p class="ap-toolbar-note">
        印刷の画面で「送信先」を「PDFに保存」にすると、PDFファイルとして保存できます。
        用紙は「A4」「横向き」、「背景のグラフィック」をオンにしてください（事業の色が印刷されます）。
    </p>
</div>
<?php if ($targets === []) : ?>
    <p class="ap-empty">
        <?php echo esc_html($is_batch ? 'この月に確定済みの社員はいません。' : '表示できる勤務表がありません。'); ?>
    </p>
<?php endif; ?>
<?php foreach ($targets as $user) : ?>
    <?php self::render_sheet($user, $year_month); ?>
<?php endforeach; ?>
</body>
</html>
        <?php
    }

    private static function render_sheet(\WP_User $user, string $year_month): void
    {
        [$year, $month] = array_map('intval', explode('-', $year_month));
        $data    = AttendanceGridService::month_data($user->ID, $year_month);
        $summary = MonthlySummaryRepository::find($user->ID, $year_month);
        $status  = $summary !== null ? (string) $summary['status'] : MonthlySummaryCalculator::DRAFT;
        $code    = (string) get_user_meta($user->ID, 'employee_code', true);

        // 月次集計：提出済みなら提出時に確定した値、未提出・差し戻し中は今の入力内容から計算した参考値。
        $has_totals = $summary !== null && $summary['total_actual_minutes'] !== null
            && !in_array($status, MonthlySummaryCalculator::SUBMITTABLE_FROM, true);
        $totals = $has_totals ? $summary : MonthlySummaryCalculator::aggregate($data['days']);

        $breakdown = AttendancePrintCalculator::business_breakdown($data['days'], $data['businesses']);
        ?>
<section class="ap-sheet">
    <header class="ap-head">
        <h1 class="ap-title"><?php echo esc_html(sprintf('月次勤務表　%d年%d月分', $year, $month)); ?></h1>
        <dl class="ap-meta">
            <div><dt>社員番号</dt><dd><?php echo esc_html($code !== '' ? $code : '—'); ?></dd></div>
            <div><dt>氏名</dt><dd><?php echo esc_html($user->display_name); ?></dd></div>
            <div><dt>対象期間</dt><dd><?php echo esc_html(date('Y年n月j日', strtotime($data['period']['start'])) . ' 〜 ' . date('n月j日', strtotime($data['period']['end']))); ?></dd></div>
            <div><dt>状態</dt><dd><?php echo esc_html(MonthlySummaryCalculator::label($status)); ?></dd></div>
        </dl>
    </header>

    <div class="ag-wrap ap-grid">
        <?php AttendanceGridPage::render_grid($data, false); ?>
    </div>

    <div class="ap-bottom">
        <table class="ap-table">
            <caption>月次集計<?php echo $has_totals ? '' : '（未提出のため参考値）'; ?></caption>
            <tr><th>出勤日数</th><td><?php echo (int) $totals['total_work_days']; ?>日</td></tr>
            <tr><th>有給取得</th><td><?php echo esc_html(rtrim(rtrim(number_format((float) $totals['total_paid_leave_days'], 1, '.', ''), '0'), '.')); ?>日</td></tr>
            <tr><th>総労働時間</th><td><?php echo esc_html(AttendancePrintCalculator::format_hours((int) $totals['total_actual_minutes'])); ?></td></tr>
            <tr><th>法定内残業</th><td><?php echo esc_html(AttendancePrintCalculator::format_hours((int) $totals['total_overtime_legal'])); ?></td></tr>
            <tr><th>法定外残業</th><td><?php echo esc_html(AttendancePrintCalculator::format_hours((int) $totals['total_overtime_illegal'])); ?></td></tr>
            <tr><th>深夜労働</th><td><?php echo esc_html(AttendancePrintCalculator::format_hours((int) $totals['total_late_night_min'])); ?></td></tr>
        </table>

        <table class="ap-table ap-table-business">
            <caption>事業別明細</caption>
            <thead><tr><th>事業</th><th>時間</th><th>日数</th></tr></thead>
            <tbody>
                <?php if ($breakdown === []) : ?>
                    <tr><td colspan="3" class="ap-muted">事業別時間の入力はありません。</td></tr>
                <?php endif; ?>
                <?php foreach ($breakdown as $b) : ?>
                    <tr>
                        <td><span class="ag-swatch" style="background:<?php echo esc_attr($b['color']); ?>;"></span><?php echo esc_html($b['name']); ?></td>
                        <td><?php echo esc_html(AttendancePrintCalculator::format_hours($b['minutes'])); ?></td>
                        <td><?php echo (int) $b['days']; ?>日</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <table class="ap-table ap-table-approval">
            <caption>承認記録</caption>
            <tr><th>提出</th><td><?php echo esc_html(self::stamp(null, $summary['submitted_at'] ?? null)); ?></td></tr>
            <tr><th>チェック承認</th><td><?php echo esc_html(self::stamp($summary['first_approved_by'] ?? null, $summary['first_approved_at'] ?? null)); ?></td></tr>
            <tr><th>最終承認</th><td><?php echo esc_html(self::stamp($summary['final_approved_by'] ?? null, $summary['final_approved_at'] ?? null)); ?></td></tr>
        </table>
    </div>
    <p class="ap-foot">出力日時：<?php echo esc_html(current_time('Y年n月j日 H:i')); ?></p>
</section>
        <?php
    }

    /** 承認記録の1行（「山田 太郎　2026年9月30日 17:05」）。未実施は「—」。 */
    private static function stamp($user_id, $datetime): string
    {
        if ($datetime === null || $datetime === '') {
            return '—';
        }
        $when = date('Y年n月j日 H:i', strtotime((string) $datetime));
        if ($user_id === null || (int) $user_id === 0) {
            return $when;
        }
        $user = get_userdata((int) $user_id);
        return ($user ? $user->display_name . '　' : '') . $when;
    }

    private static function deny(): void
    {
        wp_die(esc_html__('この操作を行う権限がありません。', 'ims-portal'), '', ['response' => 403]);
    }
}
