<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 弥生給与計算 CSV（タイムレコーダー連携形式）の行を組み立てる純粋ロジック
 * （03_attendance_management.md §4.2「弥生給与計算 CSV 出力仕様」・§7.2）。
 * DB/WordPressに触れない。
 *
 * 値の出どころ：
 * ・出勤日数・有給取得日数・総労働時間・法定内残業・法定外残業・深夜労働 …
 *   wp_monthly_summary の total_*（提出時に確定した集計値。週次法定外残業を含む）
 * ・休日出勤時間 … 日別データ（wp_daily_attendance）のうち休日出勤フラグの日の実労働時間
 * ・所定内労働時間 … 総労働時間から法定内残業・法定外残業・休日出勤（残業分を除く）を引いた残り
 * ・欠勤日数 … 常に0（稼働日カレンダーが未実装のため。弥生側で手入力する。ユーザー確認済み）
 *
 * 時間は「時間」単位の小数第2位（0.01h単位。§4.2）、有給取得日数は小数第1位（半日＝0.5）。
 * 文字コードの変換（Shift-JIS）と改行コード（CR+LF）は to_csv() / to_sjis() で行う（§7.2）。
 */
final class YayoiCsvBuilder
{
    public const HEADERS = [
        '従業員コード',
        '年',
        '月',
        '出勤日数',
        '欠勤日数',
        '有給取得日数',
        '総労働時間',
        '所定内労働時間',
        '法定内残業時間',
        '法定外残業時間',
        '深夜労働時間',
        '休日出勤時間',
    ];

    /**
     * 社員1人・1か月分の行。
     *
     * @param array<string, mixed> $summary wp_monthly_summary の1行（total_* を使う）
     * @param array<string, array<string, mixed>> $days AttendanceGridService::month_data()['days']
     * @return array<int, string> HEADERS と同じ並び
     */
    public static function row(string $employee_code, string $year_month, array $summary, array $days): array
    {
        [$year, $month] = array_map('intval', explode('-', $year_month));

        $actual  = (int) ($summary['total_actual_minutes'] ?? 0);
        $legal   = (int) ($summary['total_overtime_legal'] ?? 0);
        $illegal = (int) ($summary['total_overtime_illegal'] ?? 0);

        $holiday = self::holiday_work_minutes($days);
        $scheduled_inside = max(0, $actual - $legal - $illegal - ($holiday['total'] - $holiday['overtime']));

        return [
            $employee_code,
            (string) $year,
            (string) $month,
            (string) (int) ($summary['total_work_days'] ?? 0),
            '0',
            number_format((float) ($summary['total_paid_leave_days'] ?? 0), 1, '.', ''),
            self::hours($actual),
            self::hours($scheduled_inside),
            self::hours($legal),
            self::hours($illegal),
            self::hours((int) ($summary['total_late_night_min'] ?? 0)),
            self::hours($holiday['total']),
        ];
    }

    /**
     * 休日出勤フラグの日の実労働時間の合計と、そのうち日次の残業（法定内・法定外）に数えた分。
     *
     * @param array<string, array<string, mixed>> $days
     * @return array{total:int, overtime:int}
     */
    public static function holiday_work_minutes(array $days): array
    {
        $total    = 0;
        $overtime = 0;
        foreach ($days as $day) {
            if ((string) ($day['attendance_flag'] ?? '') !== AttendanceFlagCalculator::HOLIDAY_WORK) {
                continue;
            }
            $total    += max(0, (int) ($day['actual_minutes'] ?? 0));
            $overtime += max(0, (int) ($day['overtime_legal_min'] ?? 0)) + max(0, (int) ($day['overtime_illegal_min'] ?? 0));
        }
        return ['total' => $total, 'overtime' => min($overtime, $total)];
    }

    /** 分 → 時間（小数第2位。例：90分 → "1.50"）。 */
    public static function hours(int $minutes): string
    {
        return number_format(round($minutes / 60, 2), 2, '.', '');
    }

    /**
     * 行の配列をCSV文字列（UTF-8・CR+LF区切り）にする。先頭に見出し行を付ける。
     *
     * @param array<int, array<int, string>> $rows
     */
    public static function to_csv(array $rows): string
    {
        $lines = [self::line(self::HEADERS)];
        foreach ($rows as $row) {
            $lines[] = self::line($row);
        }
        return implode("\r\n", $lines) . "\r\n";
    }

    /** UTF-8 → Shift-JIS（Windows機種依存文字を含むCP932）。 */
    public static function to_sjis(string $utf8): string
    {
        if (function_exists('mb_convert_encoding')) {
            return (string) mb_convert_encoding($utf8, 'SJIS-win', 'UTF-8');
        }
        return (string) iconv('UTF-8', 'CP932//TRANSLIT', $utf8);
    }

    /** @param array<int, string> $fields */
    private static function line(array $fields): string
    {
        $out = [];
        foreach ($fields as $field) {
            $field = (string) $field;
            if (preg_match('/[",\r\n]/', $field)) {
                $field = '"' . str_replace('"', '""', $field) . '"';
            }
            $out[] = $field;
        }
        return implode(',', $out);
    }
}
