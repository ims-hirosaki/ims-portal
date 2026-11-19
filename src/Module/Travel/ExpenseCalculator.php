<?php

declare(strict_types=1);

namespace IMS\Module\Travel;

/**
 * 交通費・車両借り上げの金額計算（04 §2.2・§3.1・§3.2・§5.1）。DB/WPに触れない純粋関数。
 *
 * 計算順序（§2.2）：① 距離を丸める → ② 金額 = 丸めた距離 × 単価（円未満は切り捨て）。
 * 小数の誤差（0.29×100 が 28.999… になる等）を避けるため、距離は 1/100km、単価は 1/100円 の整数にして計算する。
 *
 * 通勤の distance_km は「往復距離」を保存する（§5.1 のDDLコメント・計算式による。
 * 'one_way' のときは distance_km / 2 を使う）。車両借り上げの distance_km は片道距離。
 */
final class ExpenseCalculator
{
    public const ROUND_FLOOR = 'floor';
    public const ROUND_ROUND = 'round';
    public const ROUND_CEIL  = 'ceil';

    /** @return string[] */
    public static function rounding_modes(): array
    {
        return [self::ROUND_FLOOR, self::ROUND_ROUND, self::ROUND_CEIL];
    }

    /** 不正な値は既定（切り捨て）にする。 */
    public static function normalize_mode(mixed $mode): string
    {
        return is_string($mode) && in_array($mode, self::rounding_modes(), true) ? $mode : self::ROUND_FLOOR;
    }

    /**
     * 通勤の適用距離（丸め後・km）。
     *
     * @param float  $round_trip_km 往復距離（レコードの distance_km）
     * @param string $commute_type  'round_trip' | 'one_way'
     */
    public static function commute_distance(float $round_trip_km, string $commute_type, string $mode): int
    {
        $h = self::hundredths($round_trip_km);
        // 1/200km 単位：往復＝h×2、片道＝h（往復距離の半分）
        $units = $commute_type === 'one_way' ? $h : $h * 2;
        return self::round_to_km($units, 200, $mode);
    }

    /** 通勤の支給金額（円）。 */
    public static function commute_amount(float $round_trip_km, string $commute_type, float $unit_price, string $mode): int
    {
        return self::amount(self::commute_distance($round_trip_km, $commute_type, $mode), $unit_price);
    }

    /**
     * 車両借り上げの適用距離（丸め後・km）。
     *
     * @param float $one_way_km 片道距離（レコードの distance_km）
     */
    public static function vehicle_distance(float $one_way_km, bool $is_round_trip, string $mode): int
    {
        $units = self::hundredths($one_way_km) * ($is_round_trip ? 2 : 1);
        return self::round_to_km($units, 100, $mode);
    }

    /** 車両借り上げの金額（円）。 */
    public static function vehicle_amount(float $one_way_km, bool $is_round_trip, float $unit_price, string $mode): int
    {
        return self::amount(self::vehicle_distance($one_way_km, $is_round_trip, $mode), $unit_price);
    }

    /** 丸めた距離（整数km）× 単価 の円未満切り捨て。 */
    private static function amount(int $km, float $unit_price): int
    {
        if ($km <= 0 || $unit_price <= 0) {
            return 0;
        }
        return intdiv($km * self::hundredths($unit_price), 100);
    }

    private static function hundredths(float $value): int
    {
        return max(0, (int) round($value * 100));
    }

    /** $units（1km = $per_km 単位）を整数kmに丸める。四捨五入は 0.5 を切り上げる。 */
    private static function round_to_km(int $units, int $per_km, string $mode): int
    {
        return match (self::normalize_mode($mode)) {
            self::ROUND_CEIL  => intdiv($units + $per_km - 1, $per_km),
            self::ROUND_ROUND => intdiv($units + intdiv($per_km, 2), $per_km),
            default           => intdiv($units, $per_km),
        };
    }
}
