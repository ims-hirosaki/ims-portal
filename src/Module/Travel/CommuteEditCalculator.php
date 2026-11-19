<?php

declare(strict_types=1);

namespace IMS\Module\Travel;

/**
 * スタッフによる通勤レコードの編集（事業・往復/片道）の入力チェックと更新内容（04 §3.1・§3.3①）。
 * DB/WPに触れない純粋関数。
 */
final class CommuteEditCalculator
{
    public const TYPES = ['round_trip', 'one_way'];

    /**
     * 更新内容を作る。入力が正しくなければ ['error' => [コード, メッセージ]]、正しければ ['update' => 更新するカラムと値]。
     *
     * @param array<string, mixed> $record         更新前の通勤レコード
     * @param int|null             $business_id    新しい事業（変えないなら null）
     * @param string|null          $commute_type   新しい往復/片道（変えないなら null）
     * @param array<int, bool>     $active_business_ids 選べる事業（有効な事業のID => true）
     * @return array{error?: array{0:string,1:string}, update?: array<string, mixed>}
     */
    public static function build_update(array $record, ?int $business_id, ?string $commute_type, array $active_business_ids, string $mode): array
    {
        if ($business_id === null && $commute_type === null) {
            return ['error' => ['validation', '変更する項目を指定してください。']];
        }

        $update = [];
        if ($business_id !== null) {
            if (!isset($active_business_ids[$business_id])) {
                return ['error' => ['validation', '選べない事業です。']];
            }
            $update['business_id'] = $business_id;
        }

        if ($commute_type !== null) {
            if (!in_array($commute_type, self::TYPES, true)) {
                return ['error' => ['validation', '往復／片道の指定が正しくありません。']];
            }
            $update['commute_type'] = $commute_type;
            // 0円処理の日は、往復・片道を変えても0円のまま（有給・振替休）
            $update['amount'] = (int) $record['is_zero_suppressed'] === 1
                ? 0
                : ExpenseCalculator::commute_amount((float) $record['distance_km'], $commute_type, (float) $record['unit_price'], $mode);
        }
        return ['update' => $update];
    }
}
