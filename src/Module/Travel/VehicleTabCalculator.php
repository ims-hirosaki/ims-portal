<?php

declare(strict_types=1);

namespace IMS\Module\Travel;

/**
 * 「車両借り上げ」タブの表示データ（04 §4.1「車両借り上げタブ」）。DB/WPに触れない純粋関数。
 * 事業ごとに区切って明細を並べ、事業別の小計を出す。
 */
final class VehicleTabCalculator
{
    /**
     * @param array<int, array<string, mixed>> $records 車両借り上げのレコード
     * @param array<int, array{id:int, name:string, color:string}> $businesses 事業ID → 事業（マスタ順）
     * @param int $filter_business_id 0 なら全事業
     * @return array{groups: array<int, array<string, mixed>>, total_amount:int, total_count:int}
     *         groups は事業マスタ順。各グループ：business・rows・subtotal・count。
     */
    public static function groups(array $records, array $businesses, string $mode, int $filter_business_id = 0): array
    {
        $by = [];
        foreach ($records as $r) {
            $bid = (int) $r['business_id'];
            if ($filter_business_id > 0 && $bid !== $filter_business_id) {
                continue;
            }
            $round = (int) $r['is_round_trip'] === 1;
            $by[$bid][] = [
                'id'        => (int) $r['id'],
                'date'      => (string) $r['work_date'],
                'from'      => (string) $r['route_from'],
                'to'        => (string) $r['route_to'],
                'is_round'  => $round,
                'distance'  => ExpenseCalculator::vehicle_distance((float) $r['distance_km'], $round, $mode),
                'amount'    => (int) $r['amount'],
            ];
        }

        $order = array_keys($businesses);
        foreach (array_keys($by) as $bid) {
            if (!in_array($bid, $order, true)) {
                $order[] = $bid; // マスタに無い事業は最後
            }
        }

        $groups = [];
        $total  = 0;
        $count  = 0;
        foreach ($order as $bid) {
            if (!isset($by[$bid])) {
                continue;
            }
            $rows = $by[$bid];
            usort($rows, static fn(array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
            $subtotal = array_sum(array_column($rows, 'amount'));
            $groups[] = [
                'business' => $businesses[$bid] ?? ['id' => $bid, 'name' => '（削除された事業）', 'color' => '#999999'],
                'rows'     => $rows,
                'subtotal' => $subtotal,
                'count'    => count($rows),
            ];
            $total += $subtotal;
            $count += count($rows);
        }

        return ['groups' => $groups, 'total_amount' => $total, 'total_count' => $count];
    }
}
