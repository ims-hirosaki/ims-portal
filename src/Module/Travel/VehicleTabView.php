<?php

declare(strict_types=1);

namespace IMS\Module\Travel;

use IMS\Module\Attendance\BusinessRepository;
use IMS\Module\Attendance\MonthlySummaryService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * スタッフ画面「車両借り上げ」タブ（04 §3.2・§4.1。4h）。勤怠画面（/portal/attendance/?tab=vehicle）の中に出す。
 *
 * 事業ごとに区切って明細を並べ、事業別の小計を出す。事業のチップで絞り込める（?biz=事業ID）。
 * 追加・削除は assets/js/travel-tab.js から REST（Module\Travel\RestController）へ送る。
 */
final class VehicleTabView
{
    public const TAB = 'vehicle';

    public static function render(int $user_id, string $year_month, string $start, string $end): void
    {
        $editable = MonthlySummaryService::is_editable($user_id, $year_month);

        $businesses = [];
        foreach (BusinessRepository::all(true) as $b) {
            $businesses[(int) $b['business_id']] = ['id' => (int) $b['business_id'], 'name' => (string) $b['business_name'], 'color' => (string) $b['color_code']];
        }
        $active = CommuteEditService::active_business_ids();

        $records = ExpenseRepository::vehicles_in_range($user_id, $start, $end);
        $mode    = CalcSettings::rounding_mode();
        $all     = VehicleTabCalculator::groups($records, $businesses, $mode);
        $filter  = isset($_GET['biz']) ? (int) $_GET['biz'] : 0;
        $view    = $filter > 0 ? VehicleTabCalculator::groups($records, $businesses, $mode, $filter) : $all;
        ?>
        <div class="tv-wrap">
            <div class="tv-main">
                <?php if ($editable) : ?>
                    <?php self::render_form($businesses, $active, $start, $end); ?>
                <?php endif; ?>

                <nav class="tv-chips" aria-label="事業で絞り込む">
                    <a class="tv-chip<?php echo $filter === 0 ? ' is-current' : ''; ?>" href="<?php echo esc_url(self::url($year_month, 0)); ?>">すべての事業</a>
                    <?php foreach ($all['groups'] as $g) : ?>
                        <a class="tv-chip<?php echo $filter === (int) $g['business']['id'] ? ' is-current' : ''; ?>" href="<?php echo esc_url(self::url($year_month, (int) $g['business']['id'])); ?>">
                            <span class="tv-dot" style="background:<?php echo esc_attr($g['business']['color']); ?>;"></span><?php echo esc_html($g['business']['name']); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <?php if ($view['groups'] === []) : ?>
                    <p class="tv-note">この月の車両借り上げはまだありません。</p>
                <?php endif; ?>

                <?php foreach ($view['groups'] as $g) : ?>
                    <section class="tv-group">
                        <h3 class="tv-group-title"><span class="tv-dot" style="background:<?php echo esc_attr($g['business']['color']); ?>;"></span><?php echo esc_html($g['business']['name']); ?></h3>
                        <div class="tv-scroll">
                            <table class="tv-table tv-vehicle-table">
                                <thead><tr><th>月日</th><th>出発地</th><th>目的地</th><th class="tv-num">距離(km)</th><th>往復</th><th class="tv-num">金額</th><?php echo $editable ? '<th></th>' : ''; ?></tr></thead>
                                <tbody>
                                <?php foreach ($g['rows'] as $r) : ?>
                                    <tr class="tv-row">
                                        <td class="tv-date"><?php echo esc_html((int) substr($r['date'], 5, 2) . '/' . (int) substr($r['date'], 8, 2)); ?></td>
                                        <td><?php echo esc_html($r['from']); ?></td>
                                        <td><?php echo esc_html($r['to']); ?></td>
                                        <td class="tv-num"><?php echo (int) $r['distance']; ?></td>
                                        <td><?php echo $r['is_round'] ? '往復' : '片道'; ?></td>
                                        <td class="tv-num"><?php echo esc_html(number_format($r['amount']) . '円'); ?></td>
                                        <?php if ($editable) : ?><td><button type="button" class="tv-delete" data-id="<?php echo (int) $r['id']; ?>">削除</button></td><?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                                <tfoot><tr><th colspan="5"><?php echo esc_html($g['business']['name']); ?> の小計（<?php echo (int) $g['count']; ?>件）</th><td class="tv-num"><?php echo esc_html(number_format($g['subtotal']) . '円'); ?></td><?php echo $editable ? '<td></td>' : ''; ?></tr></tfoot>
                            </table>
                        </div>
                    </section>
                <?php endforeach; ?>

                <p class="tv-note">業務中のマイカー移動（訪問・直行直帰）を、区間ごとに入力します。距離は片道で入力し、往復にチェックを入れると2倍で計算します。通勤の交通費とは別に集計します。</p>
            </div>

            <aside class="tv-side">
                <h3 class="tv-side-title">事業別の車両借り上げ</h3>
                <?php if ($all['groups'] === []) : ?>
                    <p class="tv-note">この月の借り上げはまだありません。</p>
                <?php else : ?>
                    <ul class="tv-side-list">
                        <?php foreach ($all['groups'] as $g) : ?>
                            <li>
                                <span class="tv-dot" style="background:<?php echo esc_attr($g['business']['color']); ?>;"></span>
                                <span class="tv-side-name"><?php echo esc_html($g['business']['name']); ?></span>
                                <span class="tv-side-days"><?php echo (int) $g['count']; ?>件</span>
                                <span class="tv-side-amt"><?php echo esc_html(number_format($g['subtotal']) . '円'); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="tv-side-total">月の合計 <strong><?php echo esc_html(number_format($all['total_amount']) . '円'); ?></strong></p>
                <?php endif; ?>
            </aside>
        </div>
        <?php
    }

    /**
     * 追加フォーム（編集できる月度だけ）。
     *
     * @param array<int, array{id:int, name:string, color:string}> $businesses
     * @param array<int, bool> $active
     */
    private static function render_form(array $businesses, array $active, string $start, string $end): void
    {
        ?>
        <form class="tv-form" id="tv-vehicle-form" autocomplete="off">
            <h3 class="tv-group-title">区間を追加</h3>
            <div class="tv-form-grid">
                <label>日付<input type="date" name="date" min="<?php echo esc_attr($start); ?>" max="<?php echo esc_attr($end); ?>" required></label>
                <label>出発地<input type="text" name="route_from" maxlength="200" placeholder="例：自宅" required></label>
                <label>目的地<input type="text" name="route_to" maxlength="200" placeholder="例：訪問先名" required></label>
                <label>距離（km・片道）<input type="number" name="distance_km" min="0.01" step="0.01" inputmode="decimal" required></label>
                <label class="tv-check"><input type="checkbox" name="is_round_trip" value="1"> 往復</label>
                <label>事業
                    <select name="business_id" required>
                        <option value="">選んでください</option>
                        <?php foreach ($businesses as $id => $b) : ?>
                            <?php if (isset($active[$id])) : ?><option value="<?php echo (int) $id; ?>"><?php echo esc_html($b['name']); ?></option><?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <button type="submit" class="tv-submit">追加する</button>
        </form>
        <?php
    }

    private static function url(string $year_month, int $business_id): string
    {
        $args = ['ym' => $year_month, 'tab' => self::TAB];
        if ($business_id > 0) {
            $args['biz'] = $business_id;
        }
        return add_query_arg($args, home_url('/portal/attendance/'));
    }
}
