<?php

declare(strict_types=1);

namespace IMS\Module\Travel;

/**
 * 通勤交通費の自動生成の判定（04 §3.1）。DB/WPに触れない純粋関数。
 *
 * 通勤レコードの distance_km は「往復距離」を保存する（ExpenseCalculator 冒頭コメント参照）。
 */
final class CommutePlanner
{
    public const FULL = 'full'; // 通常どおり金額を計算して生成
    public const ZERO = 'zero'; // 0円処理（amount=0・is_zero_suppressed=1）
    public const NONE = 'none'; // 生成しない

    /** 勤怠フラグが 0円処理になるもの（有給・法定振替休・所定振替休）。 */
    private const ZERO_FLAGS = ['paid_leave', 'legal_substitute', 'scheduled_substitute'];

    /**
     * その日の通勤費をどう扱うか。
     * ・有給／振替休 … 打刻の有無にかかわらず 0円処理
     * ・それ以外で出勤打刻あり … 全額（半日休も出勤した事実があるので全額）
     * ・出勤打刻なし（欠勤）… 生成しない
     */
    public static function decide(string $attendance_flag, bool $has_clock_in): string
    {
        if (in_array($attendance_flag, self::ZERO_FLAGS, true)) {
            return self::ZERO;
        }
        return $has_clock_in ? self::FULL : self::NONE;
    }

    /**
     * 適用する単価。社員個別の単価（01のユーザーメタ）があればそれを、無ければ組織共通単価を使う。
     * どちらも無ければ '0.00'（あとから「ガソリン単価の変更」で再計算できる）。
     */
    public static function resolve_unit_price(float $user_price, ?string $org_price): string
    {
        if ($user_price > 0) {
            return number_format($user_price, 2, '.', '');
        }
        return $org_price !== null && (float) $org_price > 0 ? number_format((float) $org_price, 2, '.', '') : '0.00';
    }

    /**
     * 新しい通勤レコード（business_id は NULL＝要選択。サジェストは画面側）。
     * 通勤の片道距離が未登録（0以下）なら生成しない（null）。
     *
     * @return array<string, mixed>|null
     */
    public static function new_record(int $user_id, string $work_date, string $decision, float $one_way_km, string $unit_price, string $mode): ?array
    {
        if ($decision === self::NONE || $one_way_km <= 0) {
            return null;
        }
        $round_trip_km = round($one_way_km * 2, 2);
        $zero          = $decision === self::ZERO;

        return [
            'user_id'            => $user_id,
            'work_date'          => $work_date,
            'expense_type'       => 'commute',
            'commute_type'       => 'round_trip',
            'is_round_trip'      => 0,
            'business_id'        => null,
            'distance_km'        => number_format($round_trip_km, 2, '.', ''),
            'unit_price'         => $unit_price,
            'amount'             => $zero ? 0 : ExpenseCalculator::commute_amount($round_trip_km, 'round_trip', (float) $unit_price, $mode),
            'is_auto_generated'  => 1,
            'is_zero_suppressed' => $zero ? 1 : 0,
        ];
    }

    /**
     * 既にある通勤レコードを、いまの判定に合わせて直す更新内容（変更なしなら null）。
     * ・0円処理にすべき日 → 0円にする（スタッフが手で変えていても。§3.1 の有給連動と同じ扱い）
     * ・0円処理だった日が通常の日に戻った → 保存済みの距離・往復/片道・単価で金額を計算し直す
     * ・それ以外は触らない（スタッフの編集を上書きしない）
     *
     * @param array<string, mixed> $existing
     * @return array<string, mixed>|null
     */
    public static function reconcile(array $existing, string $decision, string $mode): ?array
    {
        $zeroed = (int) $existing['is_zero_suppressed'] === 1;

        if ($decision === self::ZERO) {
            return $zeroed && (int) $existing['amount'] === 0 ? null : ['amount' => 0, 'is_zero_suppressed' => 1];
        }
        if ($decision === self::FULL && $zeroed) {
            $amount = ExpenseCalculator::commute_amount((float) $existing['distance_km'], (string) $existing['commute_type'], (float) $existing['unit_price'], $mode);
            return ['amount' => $amount, 'is_zero_suppressed' => 0];
        }
        return null;
    }
}
