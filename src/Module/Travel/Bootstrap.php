<?php

declare(strict_types=1);

namespace IMS\Module\Travel;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 04_travel_expenses（交通費・車両借上げ）モジュールのエントリポイント。
 *
 * 4a：テーブル（wp_travel_expenses・wp_gasoline_price_history）、金額計算（ExpenseCalculator）、
 * 距離の丸め設定の保存（CalcSettings）。4b：その設定画面（AdminCalcRulesPage）。
 *
 * ims-portal.php の boot() から Bootstrap::init() を呼ぶ。
 */
final class Bootstrap
{
    public static function init(): void
    {
        add_filter('ims_register_schema', [Schema::class, 'contribute']);

        if (is_admin()) {
            AdminCalcRulesPage::init(); // ポータル設定 > 交通費の計算ルール（4b）
        }
    }
}
