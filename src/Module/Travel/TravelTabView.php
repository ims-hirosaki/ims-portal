<?php

declare(strict_types=1);

namespace IMS\Module\Travel;

use IMS\Module\Attendance\AttendanceFlagCalculator;
use IMS\Module\Attendance\AttendanceGridService;
use IMS\Module\Attendance\BusinessRepository;
use IMS\Module\Attendance\MonthlySummaryService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * スタッフ画面「交通費」タブ（04 §4.1「交通費タブ」。4e）。勤怠画面（/portal/attendance/?tab=travel）の中に出す。
 *
 * 4e は表示だけ。事業の選択・編集・提出は後続スライス。
 * 画面を開いたとき、編集できる月度なら通勤費を作り足す（運用開始前の打刻などの補完。CommuteService::sync_period）。
 */
final class TravelTabView
{
    public const TAB = 'travel';

    public static function init(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue'], 25);
    }

    public static function enqueue(): void
    {
        if (get_query_var('ims_portal_page') !== 'attendance') {
            return;
        }
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : '';
        if ($tab !== self::TAB) {
            return;
        }
        wp_enqueue_style('ims-travel-tab', IMS_PORTAL_URL . 'assets/css/travel-tab.css', ['ims-attendance-statement'], IMS_PORTAL_VERSION);
    }

    public static function render(int $user_id, string $year_month, string $start, string $end): void
    {
        if (MonthlySummaryService::is_editable($user_id, $year_month)) {
            CommuteService::sync_period($user_id, $start, $end);
        }

        $businesses = [];
        foreach (BusinessRepository::all(true) as $b) {
            $businesses[(int) $b['business_id']] = ['id' => (int) $b['business_id'], 'name' => (string) $b['business_name'], 'color' => (string) $b['color_code']];
        }
        $flags = [];
        foreach (AttendanceGridService::month_data($user_id, $year_month)['days'] as $date => $day) {
            $flags[$date] = (string) $day['attendance_flag'];
        }

        $rows    = TravelTabCalculator::rows($start, $end, ExpenseRepository::commutes_in_range($user_id, $start, $end), $businesses, $flags, CalcSettings::rounding_mode());
        $summary = TravelTabCalculator::summary($rows, $businesses);
        ?>
        <div class="tv-wrap">
            <div class="tv-main">
                <?php if ($summary['unset_days'] > 0) : ?>
                    <p class="tv-warn">⚠ 事業が未設定の日が<?php echo (int) $summary['unset_days']; ?>日あります。提出までに事業を設定する必要があります（設定画面は準備中です）。</p>
                <?php endif; ?>
                <div class="tv-scroll">
                    <table class="tv-table">
                        <thead>
                            <tr><th>月日</th><th>内容</th><th class="tv-num">距離(km)</th><th class="tv-num">金額</th><th>事業</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row) : ?>
                            <?php self::render_row($row); ?>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr><th colspan="3">交通費の合計</th><td class="tv-num"><?php echo esc_html(self::yen($summary['total_amount'])); ?></td><td></td></tr>
                        </tfoot>
                    </table>
                </div>
                <p class="tv-note">通勤の交通費は、出勤の打刻をした日に自動で作られます。有給・振替休の日は0円になります。距離は通勤の往復距離（片道のときは片道）を、計算ルールに沿って丸めた値です。</p>
            </div>

            <aside class="tv-side">
                <h3 class="tv-side-title">事業別の交通費（参考）</h3>
                <?php if ($summary['by_business'] === []) : ?>
                    <p class="tv-note">この月の交通費はまだありません。</p>
                <?php else : ?>
                    <ul class="tv-side-list">
                        <?php foreach ($summary['by_business'] as $b) : ?>
                            <li>
                                <span class="tv-dot"<?php echo $b['color'] !== null ? ' style="background:' . esc_attr($b['color']) . ';"' : ''; ?>></span>
                                <span class="tv-side-name"><?php echo esc_html($b['name']); ?></span>
                                <span class="tv-side-days"><?php echo (int) $b['days']; ?>日</span>
                                <span class="tv-side-amt"><?php echo esc_html(self::yen($b['amount'])); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="tv-side-total">月の合計 <strong><?php echo esc_html(self::yen($summary['total_amount'])); ?></strong></p>
                <?php endif; ?>
            </aside>
        </div>
        <?php
    }

    /** @param array<string, mixed> $row TravelTabCalculator::rows() の1行 */
    private static function render_row(array $row): void
    {
        $classes = ['tv-row'];
        if ($row['is_weekend']) {
            $classes[] = 'is-weekend';
        }
        if ($row['is_zero']) {
            $classes[] = 'is-zero';
        }
        $bar = $row['business'] !== null ? ' style="box-shadow: inset 4px 0 0 ' . esc_attr($row['business']['color']) . ';"' : '';
        $md  = (int) substr($row['date'], 5, 2) . '/' . (int) substr($row['date'], 8, 2) . '（' . TravelTabCalculator::weekday_label($row['weekday']) . '）';
        ?>
        <tr class="<?php echo esc_attr(implode(' ', $classes)); ?>">
            <td class="tv-date"<?php echo $bar; ?>><?php echo esc_html($md); ?></td>
            <?php if ($row['record'] === null) : ?>
                <td class="tv-empty" colspan="4">―</td>
            <?php else : ?>
                <td>
                    <?php echo esc_html($row['content']); ?>
                    <?php if ($row['is_zero']) : ?><span class="tv-zero-label"><?php echo esc_html($row['zero_label']); ?></span><?php endif; ?>
                </td>
                <td class="tv-num"><?php echo (int) $row['distance']; ?></td>
                <td class="tv-num"><?php echo esc_html(self::yen((int) $row['amount'])); ?></td>
                <td>
                    <?php if ($row['business'] !== null) : ?>
                        <span class="tv-dot" style="background:<?php echo esc_attr($row['business']['color']); ?>;"></span><?php echo esc_html($row['business']['name']); ?>
                    <?php else : ?>
                        <span class="tv-unset">⚠ 未設定</span>
                    <?php endif; ?>
                </td>
            <?php endif; ?>
        </tr>
        <?php
    }

    private static function yen(int $amount): string
    {
        return number_format($amount) . '円';
    }
}
