<?php

declare(strict_types=1);

namespace IMS\Module\Travel;

use IMS\Module\Attendance\BusinessRepository;
use IMS\Module\Attendance\MonthlySummaryService;
use IMS\Module\Attendance\SalaryCycleSettings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * スタッフによる通勤レコードの編集（事業の選択・往復/片道の変更）と、事業の自動設定。
 *
 * ・編集できるのは本人の通勤レコードだけ。提出済み以降の月度は編集できない（§3.5。確定後は完全ロック）。
 * ・事業の自動設定は、その日の事業別時間が1事業だけで、まだ事業が未設定のときに限る（§3.1）。
 */
final class CommuteEditService
{
    /**
     * @return array<string, mixed>|\WP_Error 成功時は更新後のレコード
     */
    public static function update(int $user_id, int $expense_id, ?int $business_id, ?string $commute_type)
    {
        $record = ExpenseRepository::find_by_id($expense_id, $user_id);
        if ($record === null || $record['expense_type'] !== 'commute') {
            return new \WP_Error('not_found', '対象の交通費が見つかりません。');
        }

        $year_month = SalaryCycleSettings::year_month_for_date((string) $record['work_date']);
        if (!MonthlySummaryService::is_editable($user_id, $year_month)) {
            return new \WP_Error('month_locked', 'この月度はすでに提出されているため、編集できません。');
        }

        $result = CommuteEditCalculator::build_update($record, $business_id, $commute_type, self::active_business_ids(), CalcSettings::rounding_mode());
        if (isset($result['error'])) {
            return new \WP_Error($result['error'][0], $result['error'][1]);
        }

        if (!ExpenseRepository::update($expense_id, $user_id, $result['update'])) {
            return new \WP_Error('db_error', '保存に失敗しました。');
        }
        return ExpenseRepository::find_by_id($expense_id, $user_id) ?? $record;
    }

    /**
     * 期間内で事業が未設定の通勤レコードのうち、事業別時間が1事業だけの日に、その事業を設定する。
     * 提出済み以降の月度には何もしない。ベストエフォート（失敗しても画面表示は続ける）。
     */
    public static function auto_assign_business(int $user_id, string $year_month, string $start, string $end): void
    {
        if (!MonthlySummaryService::is_editable($user_id, $year_month)) {
            return;
        }
        try {
            $active = self::active_business_ids();
            $by_day = ExpenseRepository::project_minutes_by_date($user_id, $start, $end);
            foreach (ExpenseRepository::commutes_in_range($user_id, $start, $end) as $date => $rec) {
                if ($rec['business_id'] !== null) {
                    continue;
                }
                $s = BusinessSuggester::suggest($by_day[$date] ?? []);
                if ($s['basis'] === BusinessSuggester::SINGLE && isset($active[$s['business_id']])) {
                    ExpenseRepository::update((int) $rec['id'], $user_id, ['business_id' => $s['business_id']]);
                }
            }
        } catch (\Throwable $e) {
            // ベストエフォート
        }
    }

    /** @return array<int, bool> 選べる（有効な）事業のID => true */
    public static function active_business_ids(): array
    {
        $ids = [];
        foreach (BusinessRepository::all(false) as $b) {
            $ids[(int) $b['business_id']] = true;
        }
        return $ids;
    }
}
