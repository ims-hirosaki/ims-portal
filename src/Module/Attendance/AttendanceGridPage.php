<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

use IMS\Core\Layout;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * スタッフ向け月次勤務表グリッド `/portal/attendance/`（03_attendance_management.md §4.1）。
 *
 * 3eで表示専用として実装し、3e-2で書き込み系（事業別時間割当てモーダル・
 * 勤怠フラグの変更）を追加した。AttendanceFlagService / ProjectHourService への
 * 実際の保存は Module\Attendance\RestController（REST API）が担い、このクラスは
 * 初期HTML描画とJSへ渡すデータの組み立てに徹する（業務判定は一切行わない）。
 *
 * 意図的に簡略化・見送った点（要件定義書との差分）：
 * ・タブ構成（勤怠／交通費／車両借り上げ／集計表）… 04（交通費・車両借上げ）・
 *   3g（月次スナップショット）が未実装のため、勤怠タブの中身のみを単独ページとして
 *   実装した。他タブは該当モジュール実装時に統合する。
 * ・「まとめて提出」ボタン … 3f（3段階締め・提出・承認フロー）で追加する。
 * ・右パネルの sticky 事業別集計 … 本実装ではページ上部の集計ブロックとして表示する
 *   （情報は同一だが、スクロール追従はしない簡略版）。
 * ・交通費アイコン行（🚗） … 04モジュール未実装のため省略した。
 * ・有給等のフラグ日のセル内テキスト表示 … 本実装では上部サマリー行のフラグ表示に
 *   統一し、グリッド内は列全体を淡色にするだけに留めた（rowspan によるセル結合は
 *   実装コストに対して3eの主目的（実績の可視化）への寄与が小さいため見送った）。
 * ・祝日の色分け … 祝日カレンダーが未実装のため、土日のみ色分けする。
 * ・グリッドの表示時間帯（§4.1「表示開始・終了時刻はプラグイン設定で変更可能」）…
 *   設定画面は未実装。8:00〜21:45（要件定義書の例と同じ）を定数として固定している。
 *   この範囲外の深夜勤務はグリッドには描画されないが、実労働時間・残業等の集計
 *   （日次サマリー行・月次総括）には正しく反映される。
 * ・保存後の画面更新（3e-2） … 要件定義書は「Fetch APIによる非同期更新」で該当セルのみ
 *   即時に描き替えるとしているが、グリッドの色分けロジック（PHP側）をJSで二重実装する
 *   コストを避けるため、保存自体はFetch APIで行いつつ、成功後は
 *   ページを再読み込みしてサーバー側の描画結果を反映する簡略実装とした。
 * ・事業別時間割当てモーダルの時刻入力は HTML の `<input type="time">` を使うため、
 *   日をまたぐ割当て（26:00 のような表記）は入力できない。バックエンド
 *   （ProjectHourCalculator）は対応済みだが、UIからの日またぎ入力は今後の課題。
 */
final class AttendanceGridPage
{
    private const SLUG = 'attendance';

    /** グリッドの表示範囲（分・work_date 0時起点）。8:00〜21:45（§4.1の例に合わせた既定値）。 */
    private const GRID_START_MINUTES = 8 * 60;
    private const GRID_END_MINUTES   = 21 * 60 + 45;
    private const SLOT_MINUTES       = 15;

    /** グリッド内で「フラグあり」として列全体を淡色にする対象（フラグなし・休日出勤以外）。 */
    private const FLAGGED_COLUMN_FLAGS = [
        AttendanceFlagCalculator::PAID_LEAVE,
        AttendanceFlagCalculator::HOURLY_LEAVE,
        AttendanceFlagCalculator::HALF_DAY_AM,
        AttendanceFlagCalculator::HALF_DAY_PM,
        AttendanceFlagCalculator::LEGAL_SUBSTITUTE,
        AttendanceFlagCalculator::SCHEDULED_SUBSTITUTE,
    ];

    public static function init(): void
    {
        add_action('ims_portal_register_page', static function (string $router_class): void {
            $router_class::register(self::SLUG, self::class);
        });

        add_action('wp_enqueue_scripts', [self::class, 'enqueue'], 20);
    }

    public static function enqueue(): void
    {
        if (!self::is_current_page()) {
            return;
        }
        wp_enqueue_style(
            'ims-attendance-grid',
            IMS_PORTAL_URL . 'assets/css/attendance-grid.css',
            ['ims-portal-layout'],
            IMS_PORTAL_VERSION
        );
        wp_enqueue_script(
            'ims-attendance-grid',
            IMS_PORTAL_URL . 'assets/js/attendance-grid.js',
            ['ims-portal-js'],
            IMS_PORTAL_VERSION,
            true
        );

        // モーダル・フラグ変更（3e-2）がJS側で必要とするデータをまとめて渡す。
        // imsPortal（restUrl・nonce）は core の Assets が既に localize 済み。
        $user_id = get_current_user_id();
        [$year, $month] = self::resolve_month();
        $data = AttendanceGridService::month_data($user_id, $year, $month);

        $days_for_js = [];
        foreach ($data['days'] as $date => $day) {
            $allocations = array_map(static function (array $a): array {
                return [
                    'businessId' => $a['business_id'],
                    'start'      => self::minutes_to_input_value($a['start_minutes']),
                    'end'        => self::minutes_to_input_value($a['end_minutes']),
                ];
            }, $day['allocations']);

            $days_for_js[$date] = [
                'flag'           => $day['attendance_flag'],
                'clockIn'        => self::minutes_to_label($day['clock_in_minutes']),
                'clockOut'       => self::minutes_to_label($day['clock_out_minutes']),
                'roundedActualLabel' => $day['rounded_actual_minutes'] !== null ? self::format_hours((int) $day['rounded_actual_minutes']) : null,
                'allocations'    => $allocations,
            ];
        }

        $flags = [];
        foreach (AttendanceFlagCalculator::FLAGS as $flag) {
            $flags[$flag] = AttendanceFlagCalculator::label($flag);
        }

        wp_localize_script('ims-attendance-grid', 'imsAttendanceGrid', [
            'routes' => [
                'flag'          => 'attendance/day/{date}/flag',
                'projectHours'  => 'attendance/day/{date}/project-hours',
            ],
            'businesses' => $data['businesses'],
            'flags'      => $flags,
            'days'       => $days_for_js,
        ]);
    }

    private static function is_current_page(): bool
    {
        return get_query_var('ims_portal_page') === self::SLUG;
    }

    public static function render(): void
    {
        $user_id = get_current_user_id();
        [$year, $month] = self::resolve_month();

        $data = AttendanceGridService::month_data($user_id, $year, $month);

        Layout::render_header(__('勤怠', 'ims-portal'));
        ?>
        <div class="ag-wrap">
            <?php self::render_head($year, $month); ?>
            <?php self::render_legend($data['businesses'], $data['business_totals']); ?>
            <?php self::render_grid($year, $month, $data); ?>
            <?php self::render_modal(); ?>
        </div>
        <?php
        Layout::render_footer();
    }

    // ── ヘッダー・月送り ───────────────────────────────────

    private static function render_head(int $year, int $month): void
    {
        [$prev_ym, $next_ym] = self::adjacent_months($year, $month);
        ?>
        <div class="ag-head">
            <span class="ag-card-title"><?php esc_html_e('月次勤務表', 'ims-portal'); ?></span>
            <div class="ag-month-nav">
                <a class="ag-month-btn" href="<?php echo esc_url(self::month_url($prev_ym)); ?>" aria-label="<?php esc_attr_e('前の月', 'ims-portal'); ?>">‹</a>
                <span class="ag-month-label"><?php echo esc_html(sprintf('%d年%d月', $year, $month)); ?></span>
                <a class="ag-month-btn" href="<?php echo esc_url(self::month_url($next_ym)); ?>" aria-label="<?php esc_attr_e('次の月', 'ims-portal'); ?>">›</a>
            </div>
        </div>
        <p class="ag-note">
            <?php esc_html_e('セルをダブルクリックすると、その日の事業別時間を入力できます。勤怠フラグは下の「勤怠フラグ」行から変更できます。月次提出は今後の更新で追加されます。', 'ims-portal'); ?>
        </p>
        <?php
    }

    /**
     * @param array<int, array{id:int, name:string, color:string}> $businesses
     * @param array<int, int> $business_totals
     */
    private static function render_legend(array $businesses, array $business_totals): void
    {
        ?>
        <div class="ag-legend">
            <span class="ag-legend-title"><?php esc_html_e('事業別集計（今月）', 'ims-portal'); ?></span>
            <?php if ($businesses === []) : ?>
                <span class="ag-legend-empty"><?php esc_html_e('有効な事業がありません。', 'ims-portal'); ?></span>
            <?php endif; ?>
            <ul class="ag-legend-list">
                <?php foreach ($businesses as $b) : ?>
                    <?php $minutes = $business_totals[$b['id']] ?? 0; ?>
                    <li class="ag-legend-item">
                        <span class="ag-swatch" style="background:<?php echo esc_attr($b['color']); ?>;"></span>
                        <span class="ag-legend-name"><?php echo esc_html($b['name']); ?></span>
                        <span class="ag-legend-hours"><?php echo esc_html(self::format_hours($minutes)); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    // ── グリッド本体 ──────────────────────────────────────

    /**
     * @param array{businesses: array<int, array{id:int, name:string, color:string}>, days: array<string, array<string, mixed>>, business_totals: array<int,int>} $data
     */
    private static function render_grid(int $year, int $month, array $data): void
    {
        $days = $data['days'];
        $business_by_id = [];
        foreach ($data['businesses'] as $b) {
            $business_by_id[$b['id']] = $b;
        }
        $slots = range(self::GRID_START_MINUTES, self::GRID_END_MINUTES, self::SLOT_MINUTES);
        ?>
        <div class="ag-table-scroll">
            <table class="ag-table">
                <thead>
                    <tr class="ag-row-daynum">
                        <th class="ag-col-time"><?php esc_html_e('時刻', 'ims-portal'); ?></th>
                        <?php foreach ($days as $day) : ?>
                            <th class="<?php echo esc_attr(self::day_header_class($day)); ?>">
                                <?php echo (int) $day['day']; ?>
                                <?php if ($day['attendance_flag'] !== AttendanceFlagCalculator::NONE) : ?>
                                    <span class="ag-flag-badge"><?php echo esc_html($day['flag_label']); ?></span>
                                <?php endif; ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                    <tr class="ag-row-summary">
                        <th class="ag-col-time"><?php esc_html_e('勤怠フラグ', 'ims-portal'); ?></th>
                        <?php foreach ($days as $day) : ?>
                            <td class="<?php echo esc_attr(self::day_cell_class($day)); ?>" data-date="<?php echo esc_attr($day['date']); ?>">
                                <select class="ag-flag-select" data-date="<?php echo esc_attr($day['date']); ?>" data-current="<?php echo esc_attr($day['attendance_flag']); ?>">
                                    <?php foreach (AttendanceFlagCalculator::FLAGS as $flag_value) : ?>
                                        <option value="<?php echo esc_attr($flag_value); ?>" <?php selected($day['attendance_flag'], $flag_value); ?>>
                                            <?php echo esc_html(AttendanceFlagCalculator::label($flag_value)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr class="ag-row-summary">
                        <th class="ag-col-time"><?php esc_html_e('出勤', 'ims-portal'); ?></th>
                        <?php foreach ($days as $day) : ?>
                            <td class="<?php echo esc_attr(self::day_cell_class($day)); ?>" data-date="<?php echo esc_attr($day['date']); ?>">
                                <?php echo esc_html(self::minutes_to_label($day['clock_in_minutes'])); ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr class="ag-row-summary">
                        <th class="ag-col-time"><?php esc_html_e('退勤', 'ims-portal'); ?></th>
                        <?php foreach ($days as $day) : ?>
                            <td class="<?php echo esc_attr(self::day_cell_class($day)); ?>" data-date="<?php echo esc_attr($day['date']); ?>">
                                <?php echo esc_html(self::minutes_to_label($day['clock_out_minutes'])); ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr class="ag-row-summary">
                        <th class="ag-col-time"><?php esc_html_e('実労働', 'ims-portal'); ?></th>
                        <?php foreach ($days as $day) : ?>
                            <td class="<?php echo esc_attr(self::day_cell_class($day)); ?><?php echo $day['needs_allocation'] ? ' ag-needs-allocation' : ''; ?>"
                                data-date="<?php echo esc_attr($day['date']); ?>">
                                <?php echo $day['actual_minutes'] !== null ? esc_html(self::format_hours((int) $day['actual_minutes'])) : '—'; ?>
                                <?php if ($day['needs_allocation']) : ?>
                                    <span class="ag-needs-badge">⚠️ <?php esc_html_e('要入力', 'ims-portal'); ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($slots as $t) : ?>
                        <tr class="<?php echo esc_attr(self::slot_row_class($t)); ?>">
                            <th class="ag-col-time">
                                <?php echo $t % 60 === 0 ? esc_html(self::minutes_to_label($t)) : ''; ?>
                            </th>
                            <?php foreach ($days as $day) : ?>
                                <?php self::render_slot_cell($day, $t, $business_by_id); ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed> $day
     * @param array<int, array{id:int, name:string, color:string}> $business_by_id
     */
    private static function render_slot_cell(array $day, int $t, array $business_by_id): void
    {
        $classes = ['ag-cell'];
        if (in_array($day['attendance_flag'], self::FLAGGED_COLUMN_FLAGS, true)) {
            $classes[] = 'ag-col-flagged';
        }

        $clock_in  = $day['clock_in_minutes'];
        $clock_out = $day['clock_out_minutes'];
        $in_shift  = $clock_in !== null && $clock_out !== null && $t >= $clock_in && $t < $clock_out;

        $style = '';
        $label = '';

        if (!$in_shift) {
            $classes[] = 'ag-cell-outside';
        } elseif (self::in_any_range($t, $day['breaks'])) {
            $classes[] = 'ag-cell-break';
        } else {
            $alloc = self::allocation_at($t, $day['allocations']);
            if ($alloc !== null) {
                $classes[] = 'ag-cell-business';
                $business = $business_by_id[$alloc['business_id']] ?? null;
                if ($business !== null) {
                    $style = 'background:' . esc_attr($business['color']) . ';';
                    if ($t === $alloc['start_minutes']) {
                        $label = $business['name'];
                    }
                }
            } elseif ($day['needs_allocation']) {
                $classes[] = 'ag-cell-needs';
            } else {
                $classes[] = 'ag-cell-worked';
            }
        }
        ?>
        <td class="<?php echo esc_attr(implode(' ', $classes)); ?>"
            data-date="<?php echo esc_attr($day['date']); ?>"
            <?php echo $style !== '' ? 'style="' . $style . '"' : ''; ?>>
            <?php if ($label !== '') : ?>
                <span class="ag-cell-label"><?php echo esc_html($label); ?></span>
            <?php endif; ?>
        </td>
        <?php
    }

    /**
     * 事業別時間割当ての入力モーダル（§3.3）。非表示のまま描画し、JS（3e-2）が
     * ダブルクリック時に対象日のデータ（imsAttendanceGrid.businesses 等）で
     * 中身を組み立てて表示する。事業の選択肢もJS側で生成するため、ここでは骨格のみ。
     */
    private static function render_modal(): void
    {
        ?>
        <div class="ag-modal" id="ag-modal" hidden>
            <div class="ag-modal-backdrop" data-ag-close="1"></div>
            <div class="ag-modal-panel" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('事業別時間の入力', 'ims-portal'); ?>">
                <h2 class="ag-modal-title">
                    <?php esc_html_e('事業別時間の入力', 'ims-portal'); ?>
                    <span id="ag-modal-date"></span>
                </h2>
                <p class="ag-modal-sub" id="ag-modal-target"></p>

                <table class="ag-modal-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('事業', 'ims-portal'); ?></th>
                            <th><?php esc_html_e('開始', 'ims-portal'); ?></th>
                            <th><?php esc_html_e('終了', 'ims-portal'); ?></th>
                            <th><?php esc_html_e('時間', 'ims-portal'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="ag-modal-rows"></tbody>
                </table>
                <button type="button" class="button" id="ag-modal-add-row">＋ <?php esc_html_e('行を追加', 'ims-portal'); ?></button>

                <p class="ag-modal-error" id="ag-modal-error" hidden></p>

                <div class="ag-modal-actions">
                    <button type="button" class="button button-primary" id="ag-modal-save"><?php esc_html_e('保存する', 'ims-portal'); ?></button>
                    <button type="button" class="button" id="ag-modal-cancel" data-ag-close="1"><?php esc_html_e('キャンセル', 'ims-portal'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    // ── ヘルパー ────────────────────────────────────────────

    private static function day_header_class(array $day): string
    {
        $classes = ['ag-daynum'];
        if ((int) $day['dow'] === 0) {
            $classes[] = 'is-sun';
        }
        if ((int) $day['dow'] === 6) {
            $classes[] = 'is-sat';
        }
        return implode(' ', $classes);
    }

    private static function day_cell_class(array $day): string
    {
        $classes = ['ag-summary-cell'];
        if ((int) $day['dow'] === 0) {
            $classes[] = 'is-sun';
        }
        if ((int) $day['dow'] === 6) {
            $classes[] = 'is-sat';
        }
        return implode(' ', $classes);
    }

    private static function slot_row_class(int $t): string
    {
        if ($t % 60 === 0) {
            return 'ag-row-hour';
        }
        if ($t % 30 === 0) {
            return 'ag-row-half';
        }
        return 'ag-row-quarter';
    }

    /** @param array<int, array{0:int, 1:int}> $ranges */
    private static function in_any_range(int $t, array $ranges): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($t >= $start && $t < $end) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, array{business_id:int, start_minutes:int, end_minutes:int}> $allocations
     * @return array{business_id:int, start_minutes:int, end_minutes:int}|null
     */
    private static function allocation_at(int $t, array $allocations): ?array
    {
        foreach ($allocations as $alloc) {
            if ($t >= $alloc['start_minutes'] && $t < $alloc['end_minutes']) {
                return $alloc;
            }
        }
        return null;
    }

    private static function minutes_to_label(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return sprintf('%d:%02d', $h, $m);
    }

    private static function format_hours(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return sprintf('%d時間%02d分', $h, $m);
    }

    /**
     * `<input type="time">` に渡せる 'HH:MM' 形式にする。
     * HTML の time 入力は24時を超える値を扱えないため、1440分以上は 1440 で割った余りに
     * 丸める（日をまたぐ割当ての表示上の簡略化。クラス冒頭コメント参照）。
     */
    private static function minutes_to_input_value(int $minutes): string
    {
        $minutes = $minutes % 1440;
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * 表示対象の年月を決める。?ym=YYYY-MM を受け、不正なら当月。
     * @return array{0:int, 1:int}
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
                return [$y, $mo];
            }
        }
        return [$now_year, $now_month];
    }

    /** @return array{0:string, 1:string} */
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
}
