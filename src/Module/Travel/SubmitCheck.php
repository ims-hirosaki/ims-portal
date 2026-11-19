<?php

declare(strict_types=1);

namespace IMS\Module\Travel;

use IMS\Module\Attendance\SalaryCycleSettings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 月次提出時の交通費チェック（04 §3.1・§3.5）。03 の提出処理から `ims_monthly_summary_submit_check` フィルタ経由で呼ばれる。
 *
 * 通勤の交通費で事業が未設定の日が1日でもあれば、提出をブロックする。
 * 提出するとその月度の交通費は編集できなくなるため、チェックの前に通勤費を作り足し、
 * 事業が1つだけの日は自動で設定しておく（画面を一度も開いていなくても取りこぼさない）。
 */
final class SubmitCheck
{
    /** 未設定の日の判定（DB/WPに触れない純粋関数）。0円処理の日は金額が無いのでブロックしない。 */
    public static function unset_dates(array $commutes_by_date): array
    {
        $dates = [];
        foreach ($commutes_by_date as $date => $rec) {
            if ($rec['business_id'] === null && (int) $rec['is_zero_suppressed'] !== 1) {
                $dates[] = (string) $date;
            }
        }
        sort($dates);
        return $dates;
    }

    /**
     * @param mixed $result 他のチェックの結果（エラーが既にあればそれを優先する）
     * @return mixed|\WP_Error
     */
    public static function filter($result, int $user_id, string $year_month)
    {
        if (is_wp_error($result)) {
            return $result;
        }

        $period = SalaryCycleSettings::period_for_year_month($year_month);
        CommuteService::sync_period($user_id, $period['start'], $period['end']);
        CommuteEditService::auto_assign_business($user_id, $year_month, $period['start'], $period['end']);

        $dates = self::unset_dates(ExpenseRepository::commutes_in_range($user_id, $period['start'], $period['end']));
        if ($dates === []) {
            return $result;
        }
        return new \WP_Error(
            'unset_travel_business',
            '通勤の交通費で事業が未設定の日があります（' . count($dates) . '日）：' . implode('、', $dates) . '。「交通費」タブで事業を選んでから提出してください。',
            $dates
        );
    }
}
