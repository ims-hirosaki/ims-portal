<?php

declare(strict_types=1);

namespace IMS\Module\Travel;

/**
 * ガソリン単価の変更時の入力チェックと再計算の計画（04 §2.1）。DB/WPに触れない純粋関数。
 *
 * 再計算の対象：適用開始月（月度）以降の交通費・借上げ明細。
 * ただし確定済み（confirmed）の月度に属する明細は一切変えない（§2.1・§7.1）。
 * 「月度」は 03 の年月ラベル（締め日設定に従う）で判定する。呼び出し側が $label_for で渡す。
 */
final class GasolinePriceCalculator
{
    /** 単価の入力値を '27.50' 形式にする。0以下・桁あふれ・数値でない場合は null。 */
    public static function normalize_price(string $raw): ?string
    {
        $raw = trim(mb_convert_kana($raw, 'n'));
        if (!preg_match('/^\d{1,4}(\.\d{1,2})?$/', $raw)) {
            return null;
        }
        $value = (float) $raw;
        return $value > 0 ? number_format($value, 2, '.', '') : null;
    }

    /** 適用開始月 'YYYY-MM' の形式チェック。正しければそのまま、違えば null。 */
    public static function normalize_apply_from(string $raw): ?string
    {
        $raw = trim($raw);
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $raw) ? $raw : null;
    }

    /**
     * 明細を読み込む範囲の開始日。月度が前月にずれる締め日設定でも取りこぼさないよう、前月1日から読む
     * （範囲外の月度は plan() が除く）。
     */
    public static function scan_start_date(string $apply_from): string
    {
        return gmdate('Y-m-01', (int) strtotime($apply_from . '-01 -1 month'));
    }

    /**
     * 再計算の計画を作る。
     *
     * @param array<int, array<string, mixed>> $rows       id・user_id・work_date・expense_type・commute_type・distance_km・is_round_trip・is_zero_suppressed
     * @param array<string, true>              $confirmed  確定済みの月度（キーは "社員ID|YYYY-MM"）
     * @param callable(string): string         $label_for  勤務日 → 月度ラベル 'YYYY-MM'
     * @return array{updates: array<int, array{id:int, unit_price:string, amount:int}>, months: string[], users: int, skipped_confirmed: int}
     */
    public static function plan(array $rows, array $confirmed, string $apply_from, string $unit_price, string $mode, callable $label_for): array
    {
        $updates = [];
        $months  = [];
        $users   = [];
        $skipped = 0;
        $price   = (float) $unit_price;

        foreach ($rows as $row) {
            $label = $label_for((string) $row['work_date']);
            if ($label < $apply_from) {
                continue;
            }
            $user_id = (int) $row['user_id'];
            if (isset($confirmed[$user_id . '|' . $label])) {
                $skipped++;
                continue;
            }

            $distance = (float) $row['distance_km'];
            if ((int) $row['is_zero_suppressed'] === 1) {
                $amount = 0; // 有給日などの0円処理は単価が変わっても0円のまま
            } elseif ($row['expense_type'] === 'commute') {
                $amount = ExpenseCalculator::commute_amount($distance, (string) $row['commute_type'], $price, $mode);
            } else {
                $amount = ExpenseCalculator::vehicle_amount($distance, (int) $row['is_round_trip'] === 1, $price, $mode);
            }

            $updates[]       = ['id' => (int) $row['id'], 'unit_price' => $unit_price, 'amount' => $amount];
            $months[$label]  = true;
            $users[$user_id] = true;
        }

        $month_list = array_keys($months);
        sort($month_list);

        return ['updates' => $updates, 'months' => $month_list, 'users' => count($users), 'skipped_confirmed' => $skipped];
    }
}
