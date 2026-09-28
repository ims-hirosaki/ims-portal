<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 印刷用勤務表（03_attendance_management.md §4.2「PDF 印刷用出力」。3k）の
 * 事業別明細を組み立てる純粋ロジック。DB/WordPressに触れない。
 */
final class AttendancePrintCalculator
{
    /**
     * 事業別明細（事業ごとの合計時間・割り当てがあった日数）。
     * 割り当てのある事業だけを、事業マスタの並び順で返す。事業マスタに無い事業ID
     * （無効化された事業など）への割り当ても落とさず、末尾に並べる（監査で合計が合うように）。
     *
     * @param array<string, array<string, mixed>> $days AttendanceGridService::month_data()['days']
     * @param array<int, array{id:int, name:string, color:string}> $businesses
     * @return array<int, array{id:int, name:string, color:string, minutes:int, days:int}>
     */
    public static function business_breakdown(array $days, array $businesses): array
    {
        $minutes = [];
        $day_count = [];
        foreach ($days as $day) {
            $seen = [];
            foreach ($day['allocations'] ?? [] as $a) {
                $id = (int) $a['business_id'];
                $minutes[$id] = ($minutes[$id] ?? 0) + max(0, (int) $a['end_minutes'] - (int) $a['start_minutes']);
                $seen[$id] = true;
            }
            foreach (array_keys($seen) as $id) {
                $day_count[$id] = ($day_count[$id] ?? 0) + 1;
            }
        }

        $rows  = [];
        $known = [];
        foreach ($businesses as $b) {
            $known[$b['id']] = true;
            if (!isset($minutes[$b['id']])) {
                continue;
            }
            $rows[] = $b + ['minutes' => $minutes[$b['id']], 'days' => $day_count[$b['id']] ?? 0];
        }
        ksort($minutes);
        foreach ($minutes as $id => $m) {
            if (isset($known[$id])) {
                continue;
            }
            $rows[] = [
                'id'      => $id,
                'name'    => '（現在は使われていない事業）',
                'color'   => '#BBBBBB',
                'minutes' => $m,
                'days'    => $day_count[$id] ?? 0,
            ];
        }
        return $rows;
    }

    /** 分 → 「12時間30分」。 */
    public static function format_hours(int $minutes): string
    {
        return sprintf('%d時間%02d分', intdiv($minutes, 60), $minutes % 60);
    }
}
