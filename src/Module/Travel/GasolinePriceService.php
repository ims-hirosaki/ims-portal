<?php

declare(strict_types=1);

namespace IMS\Module\Travel;

use IMS\Module\Attendance\PayPeriodCalculator;
use IMS\Module\Attendance\SalaryCycleSettings;
use IMS\Support\Capabilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ガソリン単価の変更と再計算（04 §2.1）。
 *
 * 実行（apply）は1つのトランザクションで、明細の更新と変更履歴の追記を行う。
 * 確定済みの月度は計画の段階で除くので、確定月のデータは一切変わらない（§7.1）。
 * どれか1件でも失敗したら全体を取り消す（途中までの更新は残さない）。
 */
final class GasolinePriceService
{
    /** 現在の組織共通単価（最新の変更履歴）。まだ設定されていなければ null。 */
    public static function current_price(): ?string
    {
        global $wpdb;
        $v = $wpdb->get_var('SELECT unit_price FROM ' . Schema::price_history_table() . ' ORDER BY id DESC LIMIT 1');
        return $v !== null ? (string) $v : null;
    }

    /**
     * 変更履歴（新しい順）。各行に、1つ前の履歴の単価を「変更前単価」として添える。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function history(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . Schema::price_history_table() . ' ORDER BY id DESC', ARRAY_A) ?: [];
        foreach ($rows as $i => $row) {
            $rows[$i]['prev_unit_price'] = $rows[$i + 1]['unit_price'] ?? null;
        }
        return $rows;
    }

    /**
     * 実行前の確認用。何件・どの月度が変わるかを返す（DBは変更しない）。
     *
     * @return array<string, mixed>|\WP_Error
     */
    public static function preview(string $raw_price, string $raw_apply_from): array|\WP_Error
    {
        $input = self::validate($raw_price, $raw_apply_from);
        if ($input instanceof \WP_Error) {
            return $input;
        }
        return self::build_plan($input['price'], $input['apply_from'], false) + $input;
    }

    /**
     * 単価を変更して再計算する。
     *
     * @return array<string, mixed>|\WP_Error 成功時は preview と同じ形（updates は含まない件数情報）
     */
    public static function apply(int $actor_id, string $raw_price, string $raw_apply_from): array|\WP_Error
    {
        global $wpdb;

        $input = self::validate($raw_price, $raw_apply_from);
        if ($input instanceof \WP_Error) {
            return $input;
        }

        $wpdb->query('START TRANSACTION');

        // 行をロックして読み直す（確認画面の後に状態が変わっていても、実行時点の状態で計画する）
        $plan = self::build_plan($input['price'], $input['apply_from'], true);
        $ok   = true;
        foreach ($plan['updates'] as $u) {
            if ($wpdb->update(Schema::expenses_table(), ['unit_price' => $u['unit_price'], 'amount' => $u['amount']], ['id' => $u['id']]) === false) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            $ok = $wpdb->insert(Schema::price_history_table(), [
                'unit_price'     => $input['price'],
                'apply_from'     => $input['apply_from'],
                'affected_count' => count($plan['updates']),
                'changed_by'     => $actor_id,
                'changed_at'     => current_time('mysql'),
            ]) !== false;
        }

        if (!$ok) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('save_failed', '保存に失敗しました。何も変更していません。');
        }

        $wpdb->query('COMMIT');
        return $plan + $input;
    }

    /** @return array{price:string, apply_from:string}|\WP_Error */
    private static function validate(string $raw_price, string $raw_apply_from): array|\WP_Error
    {
        if (!Capabilities::can_manage_masters()) {
            return new \WP_Error('forbidden', 'この操作を行う権限がありません。');
        }
        $price = GasolinePriceCalculator::normalize_price($raw_price);
        if ($price === null) {
            return new \WP_Error('invalid_price', '単価は 0 より大きい数字（小数第2位まで）で入力してください。');
        }
        $apply_from = GasolinePriceCalculator::normalize_apply_from($raw_apply_from);
        if ($apply_from === null) {
            return new \WP_Error('invalid_apply_from', '適用開始月は「2026-10」の形式で入力してください。');
        }
        return ['price' => $price, 'apply_from' => $apply_from];
    }

    /** @return array{updates: array<int, array<string, mixed>>, months: string[], users: int, skipped_confirmed: int} */
    private static function build_plan(string $price, string $apply_from, bool $lock): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, user_id, work_date, expense_type, commute_type, distance_km, is_round_trip, is_zero_suppressed FROM '
                . Schema::expenses_table() . ' WHERE work_date >= %s' . ($lock ? ' FOR UPDATE' : ''),
                GasolinePriceCalculator::scan_start_date($apply_from)
            ),
            ARRAY_A
        ) ?: [];

        $confirmed = [];
        $found     = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT user_id, target_year_month FROM ' . \IMS\Module\Attendance\Schema::monthly_summary_table()
                . " WHERE status = 'confirmed' AND target_year_month >= %s",
                $apply_from
            ),
            ARRAY_A
        ) ?: [];
        foreach ($found as $c) {
            $confirmed[$c['user_id'] . '|' . $c['target_year_month']] = true;
        }

        $closing = SalaryCycleSettings::closing_day();
        return GasolinePriceCalculator::plan(
            $rows,
            $confirmed,
            $apply_from,
            $price,
            CalcSettings::rounding_mode(),
            static fn(string $date): string => PayPeriodCalculator::year_month_for_date($closing, $date)
        );
    }
}
