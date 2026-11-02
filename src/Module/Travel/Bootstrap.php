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
 * 4c：ガソリン単価の変更履歴と一括再計算（GasolinePriceCalculator・GasolinePriceService・AdminGasolinePricePage）。
 * 4e：スタッフ画面の「交通費」タブ（TravelTabCalculator・TravelTabView。表示のみ）。
 * 4d：通勤交通費の自動生成（CommutePlanner・CommuteService・ExpenseRepository）。
 *
 * ims-portal.php の boot() から Bootstrap::init() を呼ぶ。
 */
final class Bootstrap
{
    public static function init(): void
    {
        add_filter('ims_register_schema', [Schema::class, 'contribute']);

        // 通勤交通費の自動生成（4d。§3.1）。打刻・勤怠フラグの変更は管理画面の外でも起きるので is_admin() の外で登録する。
        add_action('ims_timecard_punched', [CommuteService::class, 'on_punched'], 10, 3);          // 出勤打刻
        add_action('ims_timecard_punch_added', [CommuteService::class, 'on_day_changed'], 10, 2);  // 打刻の追加（出勤の追加を含む）
        add_action('ims_attendance_flag_changed', [CommuteService::class, 'on_day_changed'], 10, 2); // 勤怠フラグの変更

        TravelTabView::init(); // スタッフ画面の「交通費」タブ（4e）。ポータル画面なので is_admin() の外

        if (is_admin()) {
            AdminCalcRulesPage::init(); // ポータル設定 > 交通費の計算ルール（4b）
            AdminGasolinePricePage::init(); // ポータル設定 > ガソリン単価の変更（4c）
        }
    }
}
