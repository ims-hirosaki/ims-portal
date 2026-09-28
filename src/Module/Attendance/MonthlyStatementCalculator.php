<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 集計表タブ（月次総括表。03_attendance_management.md §4.1「集計表タブ」。3l）の値を組み立てる
 * 純粋ロジック。DB/WordPressに触れない。印刷用勤務表（3k）も月次集計の値はここから取る。
 *
 * 04（交通費・車両借上げ）が未実装のため、交通費・車両借り上げの内訳と、それらを含む総支給額は
 * 扱わない。ここでの「支給額の小計」は基本給＋各手当のみ。
 */
final class MonthlyStatementCalculator
{
    /**
     * 月次集計の値。提出済み以降（submitted / checked / confirmed）は提出時に確定した total_*、
     * 未提出・差し戻し中は今の入力内容から計算した参考値（is_final = false）。
     *
     * @param array<string, mixed>|null $summary wp_monthly_summary の1行
     * @param array<string, array<string, mixed>> $days AttendanceGridService::month_data()['days']
     * @return array{is_final:bool, total_work_days:int, total_actual_minutes:int, total_overtime_legal:int,
     *               total_overtime_illegal:int, total_late_night_min:int, total_paid_leave_days:float}
     */
    public static function totals(?array $summary, array $days): array
    {
        $status = $summary !== null ? (string) $summary['status'] : MonthlySummaryCalculator::DRAFT;
        $is_final = $summary !== null
            && ($summary['total_actual_minutes'] ?? null) !== null
            && !in_array($status, MonthlySummaryCalculator::SUBMITTABLE_FROM, true);

        $source = $is_final ? $summary : MonthlySummaryCalculator::aggregate($days);

        return [
            'is_final'               => $is_final,
            'total_work_days'        => (int) $source['total_work_days'],
            'total_actual_minutes'   => (int) $source['total_actual_minutes'],
            'total_overtime_legal'   => (int) $source['total_overtime_legal'],
            'total_overtime_illegal' => (int) $source['total_overtime_illegal'],
            'total_late_night_min'   => (int) $source['total_late_night_min'],
            'total_paid_leave_days'  => (float) $source['total_paid_leave_days'],
        ];
    }

    /**
     * 時間休の取得日数と合計時間（分）。
     *
     * @param array<string, array<string, mixed>> $days
     * @return array{days:int, minutes:int}
     */
    public static function hourly_leave(array $days): array
    {
        $count   = 0;
        $minutes = 0;
        foreach ($days as $day) {
            if ((string) ($day['attendance_flag'] ?? '') !== AttendanceFlagCalculator::HOURLY_LEAVE) {
                continue;
            }
            $count++;
            $minutes += max(0, (int) ($day['hourly_leave_minutes'] ?? 0));
        }
        return ['days' => $count, 'minutes' => $minutes];
    }

    /**
     * 給与・手当（§4.1「スナップショット値を表示」）。最終承認で記録された値（snapshot_*）だけを使う。
     * 最終承認前（または3gより前に確定した月で未記録）は recorded = false を返し、金額は出さない。
     *
     * 手当の並びは手当マスタの表示順。無効化された手当も記録されていれば表示する（§2.2）。
     * 手当マスタから消えた手当IDは「（削除された手当）」として末尾に出し、合計からは落とさない。
     *
     * @param array<string, mixed>|null $summary
     * @param array<int, array<string, mixed>> $allowance_masters MasterRepository::all('allowance')（表示順）
     * @return array{recorded:bool, base_salary:?int, allowances: array<int, array{name:string, amount:int}>, subtotal:?int}
     */
    public static function salary(?array $summary, array $allowance_masters): array
    {
        $empty = ['recorded' => false, 'base_salary' => null, 'allowances' => [], 'subtotal' => null];
        if ($summary === null || (string) $summary['status'] !== MonthlySummaryCalculator::CONFIRMED) {
            return $empty;
        }

        $json = $summary['snapshot_allowances'] ?? null;
        if (($summary['snapshot_base_salary'] ?? null) === null && ($json === null || $json === '')) {
            return $empty; // 3gより前に確定した月など、記録が無い
        }

        $amounts = [];
        if (is_string($json) && $json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $id => $amount) {
                    $amounts[(int) $id] = (int) $amount;
                }
            }
        }

        $allowances = [];
        foreach ($allowance_masters as $m) {
            $id = (int) $m['id'];
            if (!array_key_exists($id, $amounts)) {
                continue;
            }
            $allowances[] = ['name' => (string) $m['allowance_name'], 'amount' => $amounts[$id]];
            unset($amounts[$id]);
        }
        ksort($amounts);
        foreach ($amounts as $amount) {
            $allowances[] = ['name' => '（削除された手当）', 'amount' => $amount];
        }

        $base = $summary['snapshot_base_salary'] !== null ? (int) $summary['snapshot_base_salary'] : null;

        return [
            'recorded'    => true,
            'base_salary' => $base,
            'allowances'  => $allowances,
            'subtotal'    => ($base ?? 0) + array_sum(array_column($allowances, 'amount')),
        ];
    }

    /** 金額表記（「215,000円」）。 */
    public static function yen(int $amount): string
    {
        return number_format($amount) . '円';
    }

    /** 日数表記（1.5 → 「1.5日」、2.0 → 「2日」）。 */
    public static function days_label(float $days): string
    {
        $s = number_format($days, 1, '.', '');
        return (str_ends_with($s, '.0') ? substr($s, 0, -2) : $s) . '日';
    }
}
