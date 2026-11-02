<?php

declare(strict_types=1);

namespace IMS\Module\Travel;

/**
 * スタッフ画面「交通費」タブの表示用データの組み立て（04 §4.1）。DB/WPに触れない純粋関数。
 */
final class TravelTabCalculator
{
    /** 0円処理になる勤怠フラグ（CommutePlanner と同じ）と、画面の理由ラベル。 */
    private const ZERO_REASON = [
        'paid_leave'           => '有給',
        'legal_substitute'     => '法定振替休',
        'scheduled_substitute' => '所定振替休',
    ];

    /**
     * 期間の1日ごとに1行（通勤は1日1件）。通勤レコードの無い日は record = null。
     *
     * @param array<string, array<string, mixed>>        $commutes_by_date 勤務日 → 通勤レコード
     * @param array<int, array{id:int, name:string, color:string}> $businesses 事業ID → 事業
     * @param array<string, string>                      $flags_by_date    勤務日 → 勤怠フラグ
     * @return array<int, array<string, mixed>>
     */
    public static function rows(string $start, string $end, array $commutes_by_date, array $businesses, array $flags_by_date, string $mode): array
    {
        $rows = [];
        for ($ts = strtotime($start . ' 00:00:00 UTC'), $last = strtotime($end . ' 00:00:00 UTC'); $ts <= $last; $ts += 86400) {
            $date    = gmdate('Y-m-d', $ts);
            $weekday = (int) gmdate('w', $ts);
            $rec     = $commutes_by_date[$date] ?? null;

            $row = [
                'date'          => $date,
                'weekday'       => $weekday,
                'is_weekend'    => $weekday === 0 || $weekday === 6,
                'record'        => $rec,
                'content'       => null,
                'distance'      => null,
                'amount'        => null,
                'business'      => null,
                'business_unset' => false,
                'is_zero'       => false,
                'zero_label'    => null,
            ];

            if ($rec !== null) {
                $type             = (string) $rec['commute_type'] === 'one_way' ? 'one_way' : 'round_trip';
                $row['content']   = $type === 'one_way' ? '通勤（片道）' : '通勤（往復）';
                $row['distance']  = ExpenseCalculator::commute_distance((float) $rec['distance_km'], $type, $mode);
                $row['amount']    = (int) $rec['amount'];
                $bid              = $rec['business_id'] !== null ? (int) $rec['business_id'] : 0;
                $row['business']  = $bid > 0 ? ($businesses[$bid] ?? ['id' => $bid, 'name' => '（削除された事業）', 'color' => '#999999']) : null;
                $row['business_unset'] = $bid === 0;
                if ((int) $rec['is_zero_suppressed'] === 1) {
                    $row['is_zero']    = true;
                    $reason            = self::ZERO_REASON[$flags_by_date[$date] ?? ''] ?? null;
                    $row['zero_label'] = $reason !== null ? "※{$reason}（0円処理）" : '※0円処理';
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * 事業別の集計（参考）と月合計。事業未設定は business_id 0 にまとめる。
     * 並びは事業マスタの順（$businesses の並び）、未設定は最後。
     *
     * @param array<int, array<string, mixed>> $rows rows() の結果
     * @param array<int, array{id:int, name:string, color:string}> $businesses
     * @return array{by_business: array<int, array{id:int, name:string, color:?string, amount:int, days:int}>, total_amount:int, total_days:int, unset_days:int}
     */
    public static function summary(array $rows, array $businesses): array
    {
        $acc = [];
        $total = 0;
        $days  = 0;
        foreach ($rows as $row) {
            if ($row['record'] === null) {
                continue;
            }
            $key = $row['business'] !== null ? (int) $row['business']['id'] : 0;
            $acc[$key] ??= ['amount' => 0, 'days' => 0];
            $acc[$key]['amount'] += (int) $row['amount'];
            $acc[$key]['days']++;
            $total += (int) $row['amount'];
            $days++;
        }

        $by = [];
        foreach ($businesses as $id => $b) {
            if (isset($acc[$id])) {
                $by[$id] = ['id' => $id, 'name' => $b['name'], 'color' => $b['color']] + $acc[$id];
            }
        }
        foreach ($acc as $id => $a) { // マスタに無い事業ID
            if ($id !== 0 && !isset($by[$id])) {
                $by[$id] = ['id' => $id, 'name' => '（削除された事業）', 'color' => '#999999'] + $a;
            }
        }
        if (isset($acc[0])) {
            $by[0] = ['id' => 0, 'name' => '未設定', 'color' => null] + $acc[0];
        }

        return ['by_business' => $by, 'total_amount' => $total, 'total_days' => $days, 'unset_days' => $acc[0]['days'] ?? 0];
    }

    public static function weekday_label(int $weekday): string
    {
        return ['日', '月', '火', '水', '木', '金', '土'][$weekday] ?? '';
    }
}
