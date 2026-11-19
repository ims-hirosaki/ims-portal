<?php

declare(strict_types=1);

namespace IMS\Module\Travel;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 交通費の計算ルール設定（04 §2.2）。距離の小数点処理を wp_options に保存する。
 * 交通費・車両借り上げの双方に共通で適用する。画面は別スライスで追加する。
 */
final class CalcSettings
{
    public const OPTION = 'ims_travel_calc_settings';

    public static function rounding_mode(): string
    {
        $saved = get_option(self::OPTION, []);
        return ExpenseCalculator::normalize_mode(is_array($saved) ? ($saved['rounding_mode'] ?? null) : null);
    }

    public static function update_rounding_mode(string $mode): void
    {
        update_option(self::OPTION, ['rounding_mode' => ExpenseCalculator::normalize_mode($mode)], false);
    }
}
