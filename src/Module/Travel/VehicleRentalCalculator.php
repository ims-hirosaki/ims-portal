<?php

declare(strict_types=1);

namespace IMS\Module\Travel;

/**
 * 車両借り上げの入力チェックと保存する行の組み立て（04 §3.2・§7.1）。DB/WPに触れない純粋関数。
 *
 * distance_km は「片道距離」を保存し、往復は is_round_trip で表す（§3.2）。
 * 事業は必須（NULL での保存は禁止。§7.1）。
 */
final class VehicleRentalCalculator
{
    public const MAX_DISTANCE = 99999.99; // decimal(7,2) の上限

    /**
     * @param array<string, mixed> $input     date・route_from・route_to・distance_km・is_round_trip・business_id
     * @param array<int, bool>     $active_business_ids 選べる事業
     * @return array{error?: array{0:string,1:string}, row?: array<string, mixed>}
     */
    public static function build_row(int $user_id, array $input, array $active_business_ids, string $unit_price, string $mode): array
    {
        $date = (string) ($input['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            return ['error' => ['validation', '日付が正しくありません。']];
        }

        $from = trim((string) ($input['route_from'] ?? ''));
        $to   = trim((string) ($input['route_to'] ?? ''));
        if ($from === '' || $to === '') {
            return ['error' => ['validation', '出発地と目的地を入力してください。']];
        }
        if (mb_strlen($from) > 200 || mb_strlen($to) > 200) {
            return ['error' => ['validation', '出発地・目的地は200文字以内で入力してください。']];
        }

        $raw = $input['distance_km'] ?? '';
        $raw = is_string($raw) ? trim(mb_convert_kana($raw, 'n')) : $raw;
        if (!is_numeric($raw) || (float) $raw <= 0 || (float) $raw > self::MAX_DISTANCE) {
            return ['error' => ['validation', '距離は 0 より大きい数字で入力してください。']];
        }
        $km = round((float) $raw, 2);

        $business_id = (int) ($input['business_id'] ?? 0);
        if ($business_id <= 0 || !isset($active_business_ids[$business_id])) {
            return ['error' => ['validation', '事業を選んでください。']];
        }

        $round = !empty($input['is_round_trip']) ? 1 : 0;

        return ['row' => [
            'user_id'            => $user_id,
            'work_date'          => $date,
            'expense_type'       => 'vehicle_rental',
            'commute_type'       => null,
            'route_from'         => $from,
            'route_to'           => $to,
            'is_round_trip'      => $round,
            'business_id'        => $business_id,
            'distance_km'        => number_format($km, 2, '.', ''),
            'unit_price'         => $unit_price,
            'amount'             => ExpenseCalculator::vehicle_amount($km, $round === 1, (float) $unit_price, $mode),
            'is_auto_generated'  => 0,
            'is_zero_suppressed' => 0,
        ]];
    }
}
