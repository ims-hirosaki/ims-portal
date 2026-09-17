<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 事業別時間割当て（wp_project_hours）の時刻変換・バリデーションを行う純粋ロジック
 * （03_attendance_management.md §3.3）。
 *
 * このクラスはDB/WordPressに触れない。時刻は「work_date 0時からの経過分」（int）で
 * 統一して扱う。日をまたぐ勤務（例：22:00出勤〜翌2:00退勤）は 1440 を超える値
 * （26:00 なら 1560）で表現する。MySQL の time型が最大838:59:59まで扱える
 * （§5.4）ことに対応させた設計。
 *
 * 4条件バリデーション（§3.3）：
 * ・条件1（時系列整合）：各行の start < end
 * ・条件2（範囲内）　　：全行が clock_in〜clock_out の範囲内
 * ・条件3（重複なし）　：同日の複数行で時間帯が重複していない
 * ・条件4（合計一致）　：Σ(end-start) ＝ 実労働時間（分）
 *
 * 「実労働時間」は打刻由来の実働分（WorkTimeCalculator::calculate_day()の actual_minutes）
 * であり、wp_daily_attendance.actual_minutes（勤怠フラグ適用後の値。時間休等の加算を含み得る）
 * ではない。事業に割り当てるのは「実際に働いた時間帯」のみのため（§3.3 冒頭）。
 */
final class ProjectHourCalculator
{
    /**
     * 'H:i' または 'H:i:s' 形式の文字列を「work_date 0時からの経過分」に変換する。
     * 時は24時間制に丸めない（26:00 のような表記をそのまま1560分として受け付ける）。
     * 形式が不正なら null。
     */
    public static function parse_minutes(string $value): ?int
    {
        $value = trim($value);
        if (!preg_match('/^(\d{1,3}):([0-5]\d)(?::([0-5]\d))?$/', $value, $m)) {
            return null;
        }
        return ((int) $m[1]) * 60 + (int) $m[2];
    }

    /** 「work_date 0時からの経過分」を 'H:i:s' 形式に戻す（時は24を超えてよい）。 */
    public static function format_minutes(int $minutes): string
    {
        $minutes = max(0, $minutes);
        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * 4条件バリデーションを行う。違反がなければ空配列を返す。
     *
     * @param array<int, array{business_id:int, start_time:string, end_time:string}> $rows
     * @return array<int, array{code:string, row?:int, expected?:int, actual?:int}>
     *         code: invalid_time_format|invalid_business|invalid_order|out_of_range|overlapping|total_mismatch
     */
    public static function validate(array $rows, int $clock_in_minutes, int $clock_out_minutes, int $target_actual_minutes): array
    {
        $errors = [];
        $intervals = [];

        foreach ($rows as $i => $row) {
            $business_id = (int) ($row['business_id'] ?? 0);
            if ($business_id <= 0) {
                $errors[] = ['code' => 'invalid_business', 'row' => $i];
            }

            $start = self::parse_minutes((string) ($row['start_time'] ?? ''));
            $end   = self::parse_minutes((string) ($row['end_time'] ?? ''));
            if ($start === null || $end === null) {
                $errors[] = ['code' => 'invalid_time_format', 'row' => $i];
                continue;
            }

            if ($start >= $end) {
                $errors[] = ['code' => 'invalid_order', 'row' => $i]; // 条件1
            }
            if ($start < $clock_in_minutes || $end > $clock_out_minutes) {
                $errors[] = ['code' => 'out_of_range', 'row' => $i]; // 条件2
            }

            $intervals[$i] = ['start' => $start, 'end' => $end];
        }

        // 条件3（重複なし）：開始時刻の昇順に並べて隣接区間だけ比較すればよい
        $sorted = $intervals;
        uasort($sorted, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);
        $prev = null;
        foreach ($sorted as $i => $interval) {
            if ($prev !== null && $interval['start'] < $prev['end']) {
                $errors[] = ['code' => 'overlapping', 'row' => $i];
            }
            $prev = $interval;
        }

        // 条件4（合計一致）
        $total = 0;
        foreach ($intervals as $interval) {
            $total += max(0, $interval['end'] - $interval['start']);
        }
        if ($total !== $target_actual_minutes) {
            $errors[] = ['code' => 'total_mismatch', 'expected' => $target_actual_minutes, 'actual' => $total];
        }

        return $errors;
    }
}
