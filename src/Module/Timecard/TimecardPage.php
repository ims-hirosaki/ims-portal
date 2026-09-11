<?php

declare(strict_types=1);

namespace IMS\Module\Timecard;

use IMS\Core\Layout;
use IMS\Support\UserRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 打刻コンソール `/portal/timecard/`（02_time_tracking.md §4.1 / ワイヤーフレーム 画面1）。
 *
 * 【2b】表示：現在ステータス／実勤務時間（経過）／リアルタイム時計／当月履歴・月送り。
 * 【2c】打刻ボタンを REST（ims/v1/timecard/punch）へ接続。押下でその場に記録され、
 *       ページ全体をリロードせずバッジ・経過時間・ボタン活性・当日行を描き替える。
 *
 * 退職者（employment_status = 退職）にはボタンを出さない（§6.1）。
 * ただしこれは表示上の配慮であり、実際の拒否は必ずサーバー側（PunchService）が行う。
 *
 * 修正ボタンは 2e で機能追加するため、まだ列自体を出さない
 * （空の操作列を見せて誤解させないため）。
 */
final class TimecardPage
{
    private const SLUG = 'timecard';

    public static function init(): void
    {
        // ページ登録（Router 経由・ProfilePage と同じ作法）
        add_action('ims_portal_register_page', static function (string $router_class): void {
            $router_class::register(self::SLUG, self::class);
        });

        // この画面でだけフロント用CSS/JSを積む（core の Assets はモジュール個別CSSを積まない）
        add_action('wp_enqueue_scripts', [self::class, 'enqueue'], 20);
    }

    public static function enqueue(): void
    {
        if (!self::is_current_page()) {
            return;
        }
        wp_enqueue_style(
            'ims-timecard',
            IMS_PORTAL_URL . 'assets/css/timecard.css',
            ['ims-portal-layout'],
            IMS_PORTAL_VERSION
        );
        wp_enqueue_script(
            'ims-timecard',
            IMS_PORTAL_URL . 'assets/js/timecard.js',
            ['ims-portal-js'],
            IMS_PORTAL_VERSION,
            true
        );

        // 2d：休憩補完付き退勤（ケースA/B）用のルートと労基法の目安（§3.2）。
        // 法令由来の固定値は PunchService の定数を正本とし、JS 側で数値を持たない。
        wp_localize_script('ims-timecard', 'imsTimecard', [
            'routes' => [
                'completeBreak'     => 'timecard/punch/complete-break',
                'clockOutWithBreak' => 'timecard/punch/clock-out-with-break',
            ],
            'laborBreak' => [
                'tier1Hours'   => PunchService::LABOR_BREAK_TIER1_HOURS,
                'tier2Hours'   => PunchService::LABOR_BREAK_TIER2_HOURS,
                'tier1Minutes' => PunchService::LABOR_BREAK_TIER1_MINUTES,
                'tier2Minutes' => PunchService::LABOR_BREAK_TIER2_MINUTES,
            ],
        ]);
    }

    /**
     * 現在のリクエストがこの打刻ページかを判定する。
     * Router のクエリ変数 'ims_portal_page' が slug と一致するかで見る。
     */
    private static function is_current_page(): bool
    {
        return get_query_var('ims_portal_page') === self::SLUG;
    }

    public static function render(): void
    {
        $user_id = get_current_user_id();

        // ── 現在の勤務日の状態算出（サーバー時刻） ──
        // work_date は打刻API と同じ規則で解決する（深夜帯で画面とAPIがずれないように）。
        $work_date  = PunchService::console_work_date($user_id);
        $today_logs = Repository::logs_for_date($user_id, $work_date);
        $status     = StatusCalculator::status($today_logs);
        $active     = StatusCalculator::active_buttons($today_logs);
        $now_ts     = (int) current_time('timestamp');
        $worked_sec = StatusCalculator::worked_seconds($today_logs, $now_ts);

        // 退職者は打刻できない（§6.1）。サーバー側でも PunchService が拒否する。
        $can_punch = !UserRepository::is_retired($user_id);

        // clock_in 時刻（JS で経過を進めるための基準）
        $clock_in_at = self::first_punch_time($today_logs, StatusCalculator::CLOCK_IN);

        // 2d：ケースA/Bの補完UIが必要とする材料。
        // タイムゾーンのずれを避けるため H:i ではなく UNIX 秒（サーバー時刻基準）で渡す
        // （data-server-now と同じ流儀。JS 側の offset 計算とそのまま整合する）。
        $clock_in_epoch = self::last_punch_epoch($today_logs, StatusCalculator::CLOCK_IN);
        $break_in_epoch = self::last_punch_epoch($today_logs, StatusCalculator::BREAK_IN);
        $has_break      = StatusCalculator::has_break_in($today_logs);

        // ── 表示する月（?ym=YYYY-MM、なければ当月） ──
        [$year, $month] = self::resolve_month();
        $month_logs = Repository::logs_for_month($user_id, $year, $month);

        Layout::render_header(__('打刻', 'ims-portal'));
        ?>
        <div class="tc-wrap"
             data-status="<?php echo esc_attr($status); ?>"
             data-worked-sec="<?php echo esc_attr((string) $worked_sec); ?>"
             data-clock-in="<?php echo esc_attr($clock_in_at); ?>"
             data-server-now="<?php echo esc_attr((string) $now_ts); ?>"
             data-work-date="<?php echo esc_attr($work_date); ?>"
             data-can-punch="<?php echo $can_punch ? '1' : '0'; ?>"
             data-clock-in-epoch="<?php echo esc_attr((string) $clock_in_epoch); ?>"
             data-break-in-epoch="<?php echo esc_attr((string) $break_in_epoch); ?>"
             data-has-break="<?php echo $has_break ? '1' : '0'; ?>">

            <?php self::render_console($status, $active, $worked_sec, $can_punch); ?>
            <?php self::render_history($year, $month, $month_logs, $work_date); ?>
        </div>
        <?php
        Layout::render_footer();
    }

    // ── 打刻コンソール（上段） ──────────────────────────────

    private static function render_console(string $status, array $active, int $worked_sec, bool $can_punch): void
    {
        $variant = StatusCalculator::status_variant($status);
        $label   = StatusCalculator::status_label($status);
        ?>
        <section class="tc-console">
            <div class="tc-console-head">
                <span class="tc-card-title"><?php esc_html_e('本日の打刻', 'ims-portal'); ?></span>
                <span class="tc-server-note"><?php esc_html_e('サーバー時刻基準', 'ims-portal'); ?></span>
            </div>

            <div class="tc-status-row">
                <span class="tc-badge tc-badge--<?php echo esc_attr($variant); ?>" id="tc-status-badge">
                    <?php echo esc_html($label); ?>
                </span>
                <div class="tc-clock-block">
                    <span class="tc-clock" id="tc-clock">--:--:--</span>
                    <span class="tc-clock-note"><?php esc_html_e('※ 表示は参考値です。打刻時刻はサーバー時刻を使用します。', 'ims-portal'); ?></span>
                </div>
                <div class="tc-worked-block">
                    <span class="tc-worked" id="tc-worked"><?php echo esc_html(StatusCalculator::format_duration($worked_sec)); ?></span>
                    <span class="tc-worked-note"><?php esc_html_e('実勤務時間（経過）', 'ims-portal'); ?></span>
                </div>
            </div>

            <div class="tc-punch-grid">
                <?php
                self::punch_button(StatusCalculator::CLOCK_IN,  __('出勤', 'ims-portal'),     'primary', $active, $can_punch);
                self::punch_button(StatusCalculator::BREAK_IN,  __('休憩開始', 'ims-portal'), 'ghost',   $active, $can_punch);
                self::punch_button(StatusCalculator::BREAK_OUT, __('休憩終了', 'ims-portal'), 'ghost',   $active, $can_punch);
                self::punch_button(StatusCalculator::CLOCK_OUT, __('退勤', 'ims-portal'),     'danger',  $active, $can_punch);
                ?>
            </div>

            <?php if ($can_punch) : ?>
                <p class="tc-punch-hint" id="tc-feedback" role="status" aria-live="polite">
                    <?php esc_html_e('打刻ボタンは押下直後に非活性化され、通信完了まで再押下できません。', 'ims-portal'); ?>
                </p>
            <?php else : ?>
                <div class="tc-pending-banner">
                    <?php esc_html_e('退職済みのため打刻はできません。過去の打刻履歴のみ閲覧できます。', 'ims-portal'); ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * 打刻ボタン1つを描画する。
     *
     * 現在の状態で押せない種別は disabled にする（§3.1 の活性表）。
     * ただしこれは表示上の補助にすぎず、押せてしまった場合の防波堤は
     * サーバー側の StatusCalculator::validate_transition() が担う。
     */
    private static function punch_button(string $type, string $label, string $style, array $active, bool $can_punch): void
    {
        $is_active = $can_punch && in_array($type, $active, true);
        $classes   = ['tc-punch-btn', 'is-' . $style];
        if (!$is_active) {
            $classes[] = 'is-inactive';
        }
        ?>
        <button type="button"
                class="<?php echo esc_attr(implode(' ', $classes)); ?>"
                data-punch="<?php echo esc_attr($type); ?>"
                <?php disabled(!$is_active); ?>>
            <span class="tc-punch-label"><?php echo esc_html($label); ?></span>
        </button>
        <?php
    }

    // ── 打刻履歴テーブル（下段） ────────────────────────────

    private static function render_history(int $year, int $month, array $month_logs, string $work_date): void
    {
        $days_in_mon = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
        [$prev_ym, $next_ym] = self::adjacent_months($year, $month);

        // 未来月への移動は不可にする（打刻が存在し得ないため）
        $this_ym    = current_time('Y-m');
        $target_ym  = sprintf('%04d-%02d', $year, $month);
        $allow_next = $target_ym < $this_ym;
        ?>
        <section class="tc-history">
            <div class="tc-history-head">
                <span class="tc-card-title"><?php esc_html_e('打刻履歴', 'ims-portal'); ?></span>
                <div class="tc-month-nav">
                    <a class="tc-month-btn" href="<?php echo esc_url(self::month_url($prev_ym)); ?>" aria-label="<?php esc_attr_e('前の月', 'ims-portal'); ?>">‹</a>
                    <span class="tc-month-label"><?php echo esc_html(sprintf('%d年%d月', $year, $month)); ?></span>
                    <?php if ($allow_next) : ?>
                        <a class="tc-month-btn" href="<?php echo esc_url(self::month_url($next_ym)); ?>" aria-label="<?php esc_attr_e('次の月', 'ims-portal'); ?>">›</a>
                    <?php else : ?>
                        <span class="tc-month-btn is-disabled" aria-disabled="true">›</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tc-table-scroll">
                <table class="tc-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('日付', 'ims-portal'); ?></th>
                            <th><?php esc_html_e('出勤', 'ims-portal'); ?></th>
                            <th><?php esc_html_e('休憩開始', 'ims-portal'); ?></th>
                            <th><?php esc_html_e('休憩終了', 'ims-portal'); ?></th>
                            <th><?php esc_html_e('退勤', 'ims-portal'); ?></th>
                            <th><?php esc_html_e('実労働', 'ims-portal'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $has_any = false;
                        for ($d = 1; $d <= $days_in_mon; $d++) {
                            $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
                            $logs = $month_logs[$date] ?? [];
                            if ($logs !== []) {
                                $has_any = true;
                            }
                            // 「本日」の強調は暦日ではなく現在の勤務日に合わせる（深夜帯対応）
                            self::render_history_row($date, $logs, $date === $work_date);
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <?php if (!$has_any) : ?>
                <p class="tc-empty"><?php esc_html_e('この月の打刻記録はありません。', 'ims-portal'); ?></p>
            <?php endif; ?>
        </section>
        <?php
    }

    private static function render_history_row(string $date, array $logs, bool $is_today): void
    {
        $ts   = strtotime($date);
        $dow  = (int) date('w', $ts); // 0=日, 6=土
        $dow_ja = ['日', '月', '火', '水', '木', '金', '土'][$dow];

        $row_class = [];
        if ($is_today) {
            $row_class[] = 'is-today';
        }
        $date_class = [];
        if ($dow === 0) {
            $date_class[] = 'is-sun';
        }
        if ($dow === 6) {
            $date_class[] = 'is-sat';
        }

        // 種別ごとに時刻を集める
        $by_type = [
            StatusCalculator::CLOCK_IN  => [],
            StatusCalculator::BREAK_IN  => [],
            StatusCalculator::BREAK_OUT => [],
            StatusCalculator::CLOCK_OUT => [],
        ];
        foreach ($logs as $log) {
            $type = $log['punch_type'];
            if (isset($by_type[$type])) {
                $by_type[$type][] = $log;
            }
        }

        $worked = StatusCalculator::worked_seconds($logs, (int) current_time('timestamp'));

        // data-date / data-punch-cell は、打刻成功時に JS が該当セルへ時刻を差し込むための目印。
        ?>
        <tr class="<?php echo esc_attr(implode(' ', $row_class)); ?>" data-date="<?php echo esc_attr($date); ?>">
            <td class="tc-col-date <?php echo esc_attr(implode(' ', $date_class)); ?>">
                <?php echo esc_html(sprintf('%d/%d（%s）', (int) date('n', $ts), (int) date('j', $ts), $dow_ja)); ?>
            </td>
            <td data-punch-cell="<?php echo esc_attr(StatusCalculator::CLOCK_IN); ?>"><?php self::render_times($by_type[StatusCalculator::CLOCK_IN]); ?></td>
            <td data-punch-cell="<?php echo esc_attr(StatusCalculator::BREAK_IN); ?>"><?php self::render_times($by_type[StatusCalculator::BREAK_IN], true); ?></td>
            <td data-punch-cell="<?php echo esc_attr(StatusCalculator::BREAK_OUT); ?>"><?php self::render_times($by_type[StatusCalculator::BREAK_OUT], true); ?></td>
            <td data-punch-cell="<?php echo esc_attr(StatusCalculator::CLOCK_OUT); ?>"><?php self::render_times($by_type[StatusCalculator::CLOCK_OUT]); ?></td>
            <td class="tc-col-worked">
                <?php echo $logs === [] ? '—' : esc_html(StatusCalculator::format_duration($worked)); ?>
            </td>
        </tr>
        <?php
    }

    /**
     * 1セル内に時刻を描画する。複数件は縦並び。
     * $show_total が真で2件以上あれば、合計時間（休憩合計）を下部に出す。
     */
    private static function render_times(array $items, bool $show_total = false): void
    {
        if ($items === []) {
            echo '—';
            return;
        }

        foreach ($items as $item) {
            $time = date('H:i', strtotime($item['punched_at']));
            echo '<span class="tc-time">' . esc_html($time);
            if (!empty($item['is_auto_filled'])) {
                echo ' <span class="tc-auto-tag">' . esc_html__('自動補完', 'ims-portal') . '</span>';
            }
            echo '</span>';
        }

        // 休憩が2件以上：合計休憩時間（break_in と break_out の対で計算するのは
        // 行全体の worked に含まれるため、ここでは件数の視認補助として合計を出す）
        if ($show_total && count($items) >= 2) {
            echo '<span class="tc-times-count">' . esc_html(sprintf('%d回', count($items))) . '</span>';
        }
    }

    // ── ヘルパー ────────────────────────────────────────────

    /**
     * 表示対象の年月を決める。?ym=YYYY-MM を受け、不正なら当月。
     * 未来月が指定されても当月に丸める。
     *
     * @return array{0:int, 1:int} [year, month]
     */
    private static function resolve_month(): array
    {
        $now_year  = (int) current_time('Y');
        $now_month = (int) current_time('n');

        $ym = isset($_GET['ym']) ? sanitize_text_field(wp_unslash((string) $_GET['ym'])) : '';
        if (preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) {
            $y  = (int) $m[1];
            $mo = (int) $m[2];
            if ($mo >= 1 && $mo <= 12 && $y >= 2020 && $y <= $now_year + 1) {
                // 未来月は当月へ
                if ($y > $now_year || ($y === $now_year && $mo > $now_month)) {
                    return [$now_year, $now_month];
                }
                return [$y, $mo];
            }
        }
        return [$now_year, $now_month];
    }

    /**
     * @return array{0:string, 1:string} [prev 'YYYY-MM', next 'YYYY-MM']
     */
    private static function adjacent_months(int $year, int $month): array
    {
        $prev = mktime(0, 0, 0, $month - 1, 1, $year);
        $next = mktime(0, 0, 0, $month + 1, 1, $year);
        return [date('Y-m', $prev), date('Y-m', $next)];
    }

    private static function month_url(string $ym): string
    {
        return add_query_arg('ym', $ym, home_url('/portal/' . self::SLUG . '/'));
    }

    /**
     * 指定した種別の最初の打刻時刻を 'H:i:s' で返す。なければ空文字。
     */
    private static function first_punch_time(array $logs, string $type): string
    {
        foreach ($logs as $log) {
            if (($log['punch_type'] ?? '') === $type) {
                $ts = strtotime((string) $log['punched_at']);
                return $ts !== false ? date('H:i:s', $ts) : '';
            }
        }
        return '';
    }

    /**
     * 指定した種別の最後の打刻時刻を UNIX 秒で返す（2d のケースA/B補完UI用）。
     * なければ 0（JS 側は 0 を「該当なし」として扱う）。
     */
    private static function last_punch_epoch(array $logs, string $type): int
    {
        $last = 0;
        foreach ($logs as $log) {
            if (($log['punch_type'] ?? '') === $type) {
                $ts = strtotime((string) $log['punched_at']);
                if ($ts !== false) {
                    $last = $ts;
                }
            }
        }
        return $last;
    }
}
