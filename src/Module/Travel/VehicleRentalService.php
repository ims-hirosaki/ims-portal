<?php

declare(strict_types=1);

namespace IMS\Module\Travel;

use IMS\Module\Attendance\MonthlySummaryService;
use IMS\Module\Attendance\SalaryCycleSettings;
use IMS\Support\UserRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 車両借り上げの追加・削除（04 §3.2）。
 *
 * ・本人の分だけ。提出済み以降の月度は追加も削除もできない（§3.5。確定後は完全ロック）。
 * ・単価は入力時に決めてレコードに保存する（社員個別の単価があればそれを、無ければ組織共通単価）。
 * ・削除できるのは、まだ提出していない月度の自分の借り上げ行だけ（確定済みは削除できない。§7.3）。
 */
final class VehicleRentalService
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error 成功時は保存したレコード
     */
    public static function create(int $user_id, array $input)
    {
        $price  = CommutePlanner::resolve_unit_price(UserRepository::get_travel_unit_price($user_id), GasolinePriceService::current_price());
        $result = VehicleRentalCalculator::build_row($user_id, $input, CommuteEditService::active_business_ids(), $price, CalcSettings::rounding_mode());
        if (isset($result['error'])) {
            return new \WP_Error($result['error'][0], $result['error'][1]);
        }
        $row = $result['row'];

        if (!self::is_editable($user_id, (string) $row['work_date'])) {
            return new \WP_Error('month_locked', 'この月度はすでに提出されているため、編集できません。');
        }
        if (!ExpenseRepository::insert($row)) {
            return new \WP_Error('db_error', '保存に失敗しました。');
        }
        return $row;
    }

    /** @return true|\WP_Error */
    public static function delete(int $user_id, int $expense_id)
    {
        $record = ExpenseRepository::find_by_id($expense_id, $user_id);
        if ($record === null || $record['expense_type'] !== 'vehicle_rental') {
            return new \WP_Error('not_found', '対象の借り上げが見つかりません。');
        }
        if (!self::is_editable($user_id, (string) $record['work_date'])) {
            return new \WP_Error('month_locked', 'この月度はすでに提出されているため、削除できません。');
        }
        return ExpenseRepository::delete_vehicle($expense_id, $user_id) ? true : new \WP_Error('db_error', '削除に失敗しました。');
    }

    private static function is_editable(int $user_id, string $work_date): bool
    {
        return MonthlySummaryService::is_editable($user_id, SalaryCycleSettings::year_month_for_date($work_date));
    }
}
