<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 事業別時間の自動割り当て（03 §3.3「有給日の自動割り当て」「通常勤務日のデフォルト事業自動割り当て」）の
 * 行を組み立てる純粋ロジック。DB/WordPressに触れない。
 *
 * 返す行は ProjectHourRepository::replace_day() にそのまま渡せる形
 * （['business_id', 'start_minutes', 'end_minutes', 'is_auto_assigned' => true]）。
 */
final class AutoAllocationCalculator
{
    /**
     * 有給日の割り当て：所定の始業時刻から所定労働時間分を、休憩を挟まない1つの時間帯で割り当てる。
     * 割当先の事業が無い・所定労働時間が0以下なら空配列。
     *
     * @return array<int, array{business_id:int, start_minutes:int, end_minutes:int, is_auto_assigned:bool}>
     */
    public static function paid_leave_rows(?int $business_id, int $start_minutes, int $scheduled_minutes): array
    {
        if ($business_id === null || $business_id <= 0 || $scheduled_minutes <= 0) {
            return [];
        }
        return [[
            'business_id'      => $business_id,
            'start_minutes'    => $start_minutes,
            'end_minutes'      => $start_minutes + $scheduled_minutes,
            'is_auto_assigned' => true,
        ]];
    }

    /**
     * 通常勤務日の割り当て：出勤〜退勤（丸め後）から休憩時間帯を除いた区間を、すべてデフォルト事業に割り当てる。
     * 休憩が複数回あれば複数行に分割する。0分の区間は作らない。
     *
     * @param array<int, array{0:int, 1:int}> $breaks 休憩区間（丸め後。WorkTimeCalculator の rounded_breaks）
     * @return array<int, array{business_id:int, start_minutes:int, end_minutes:int, is_auto_assigned:bool}>
     */
    public static function default_business_rows(?int $business_id, int $clock_in_minutes, int $clock_out_minutes, array $breaks): array
    {
        if ($business_id === null || $business_id <= 0 || $clock_out_minutes <= $clock_in_minutes) {
            return [];
        }

        $sorted = $breaks;
        usort($sorted, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        $rows   = [];
        $cursor = $clock_in_minutes;
        foreach ($sorted as [$break_start, $break_end]) {
            $break_start = max($break_start, $clock_in_minutes);
            $break_end   = min($break_end, $clock_out_minutes);
            if ($break_end <= $break_start) {
                continue;
            }
            if ($break_start > $cursor) {
                $rows[] = self::row($business_id, $cursor, $break_start);
            }
            $cursor = max($cursor, $break_end);
        }
        if ($clock_out_minutes > $cursor) {
            $rows[] = self::row($business_id, $cursor, $clock_out_minutes);
        }
        return $rows;
    }

    /**
     * 既存の割り当てを自動割り当てで置き換えてよいか。割り当てが無い、または自動割り当ての行だけのとき true
     * （スタッフが手で入力・修正した行がある日は上書きしない）。
     *
     * @param array<int, array<string, mixed>> $existing ProjectHourRepository::for_date() の行
     */
    public static function can_replace(array $existing): bool
    {
        foreach ($existing as $row) {
            if ((int) ($row['is_auto_assigned'] ?? 0) !== 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * 有給を外したときに、自動割り当ての行を取り除くか。自動割り当ての行だけが残っているとき true。
     *
     * @param array<int, array<string, mixed>> $existing
     */
    public static function should_clear_on_flag_off(array $existing): bool
    {
        return $existing !== [] && self::can_replace($existing);
    }

    /** @return array{business_id:int, start_minutes:int, end_minutes:int, is_auto_assigned:bool} */
    private static function row(int $business_id, int $start, int $end): array
    {
        return ['business_id' => $business_id, 'start_minutes' => $start, 'end_minutes' => $end, 'is_auto_assigned' => true];
    }
}
