<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

use IMS\Module\User\MasterRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 集計表タブ（月次総括表。03_attendance_management.md §4.1「集計表タブ」。3l）の描画。
 * スタッフ画面（AttendanceGridPage）と管理者向け「勤務表の確認」（AdminAttendanceGridPage）で共通に使う。
 * 値の組み立ては MonthlyStatementCalculator（純粋関数）に任せ、ここは表示だけを行う。
 *
 * 要件定義書の項目のうち、次は前提となるデータ・モジュールが無いため表示しない（画面上に理由を明記）：
 * ・交通費・車両借り上げの事業別内訳、それらを含む総支給額 … 04モジュール未実装
 * ・欠勤日数 … 稼働日カレンダー未実装（弥生CSVと同じく集計しない。ユーザー確認済み）
 * ・遅刻早退 … 所定の始業・終業時刻のデータが無い
 * ・在宅日数 … 在宅を表す勤怠フラグが無い
 */
final class MonthlyStatementView
{
    public static function render(int $user_id, string $year_month): void
    {
        $data    = AttendanceGridService::month_data($user_id, $year_month);
        $summary = MonthlySummaryRepository::find($user_id, $year_month);
        $user    = get_userdata($user_id);
        $code    = (string) get_user_meta($user_id, 'employee_code', true);

        $totals = MonthlyStatementCalculator::totals($summary, $data['days']);
        $hourly = MonthlyStatementCalculator::hourly_leave($data['days']);
        $salary = MonthlyStatementCalculator::salary($summary, MasterRepository::all('allowance', true));
        ?>
        <div class="ag-statement">
            <section class="ag-st-block">
                <h3 class="ag-st-title">基本情報</h3>
                <table class="ag-st-table">
                    <tr><th>社員番号</th><td><?php echo esc_html($code !== '' ? $code : '—'); ?></td></tr>
                    <tr><th>氏名</th><td><?php echo esc_html($user ? $user->display_name : '—'); ?></td></tr>
                </table>
            </section>

            <section class="ag-st-block">
                <h3 class="ag-st-title">給与・手当</h3>
                <?php if (!$salary['recorded']) : ?>
                    <p class="ag-st-note">最終承認されると、その時点の基本給・手当が記録され、ここに表示されます。</p>
                <?php else : ?>
                    <table class="ag-st-table">
                        <tr><th>基本給</th><td class="ag-st-num"><?php echo esc_html($salary['base_salary'] !== null ? MonthlyStatementCalculator::yen($salary['base_salary']) : '—'); ?></td></tr>
                        <?php foreach ($salary['allowances'] as $a) : ?>
                            <tr><th><?php echo esc_html($a['name']); ?></th><td class="ag-st-num"><?php echo esc_html(MonthlyStatementCalculator::yen($a['amount'])); ?></td></tr>
                        <?php endforeach; ?>
                        <tr class="ag-st-total"><th>基本給＋手当</th><td class="ag-st-num"><?php echo esc_html(MonthlyStatementCalculator::yen((int) $salary['subtotal'])); ?></td></tr>
                    </table>
                    <p class="ag-st-note">金額は最終承認の時点で記録したものです。変更は社員管理の給与・手当設定で行います。</p>
                <?php endif; ?>
            </section>

            <section class="ag-st-block">
                <h3 class="ag-st-title">勤務のまとめ<?php echo $totals['is_final'] ? '' : '<span class="ag-st-badge">未提出のため参考値</span>'; ?></h3>
                <table class="ag-st-table">
                    <tr><th>出勤日数</th><td class="ag-st-num"><?php echo esc_html($totals['total_work_days'] . '日'); ?></td></tr>
                    <tr><th>総労働時間</th><td class="ag-st-num"><?php echo esc_html(AttendancePrintCalculator::format_hours($totals['total_actual_minutes'])); ?></td></tr>
                    <tr><th>法定内残業</th><td class="ag-st-num"><?php echo esc_html(AttendancePrintCalculator::format_hours($totals['total_overtime_legal'])); ?></td></tr>
                    <tr><th>法定外残業</th><td class="ag-st-num"><?php echo esc_html(AttendancePrintCalculator::format_hours($totals['total_overtime_illegal'])); ?></td></tr>
                    <tr><th>深夜労働</th><td class="ag-st-num"><?php echo esc_html(AttendancePrintCalculator::format_hours($totals['total_late_night_min'])); ?></td></tr>
                </table>
            </section>

            <section class="ag-st-block">
                <h3 class="ag-st-title">休暇・勤怠のまとめ</h3>
                <table class="ag-st-table">
                    <tr><th>有給</th><td class="ag-st-num"><?php echo esc_html(MonthlyStatementCalculator::days_label($totals['total_paid_leave_days'])); ?></td></tr>
                    <tr><th>時間休</th><td class="ag-st-num"><?php echo esc_html($hourly['days'] . '日（' . AttendancePrintCalculator::format_hours($hourly['minutes']) . '）'); ?></td></tr>
                    <tr><th>欠勤</th><td class="ag-st-num ag-st-muted">集計していません</td></tr>
                    <tr><th>遅刻・早退</th><td class="ag-st-num ag-st-muted">集計していません</td></tr>
                    <tr><th>在宅</th><td class="ag-st-num ag-st-muted">集計していません</td></tr>
                </table>
                <p class="ag-st-note">欠勤・遅刻早退・在宅は、会社の稼働日や始業時刻などの設定がまだ無いため集計していません。</p>
            </section>

            <section class="ag-st-block">
                <h3 class="ag-st-title">交通費・車両借り上げ</h3>
                <p class="ag-st-note">交通費・車両借り上げの機能は準備中です。総支給額（基本給＋手当＋交通費＋車両借り上げ）も、準備ができしだい表示します。</p>
            </section>
        </div>
        <?php
    }
}
