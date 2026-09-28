<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

use IMS\Module\User\EmployeeRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 弥生給与計算CSVの出力対象を集め、CSVを組み立てる（03_attendance_management.md §4.2。3i-2）。
 * 読み取り専用。行の値の計算は YayoiCsvBuilder（純粋関数）に任せる。
 *
 * 出力対象：指定年月が「確定済み」（confirmed）の社員のみ（§4.2「承認済みデータ」）。
 * 退職者も含める（退職月の給与計算に必要なため）。社員番号が未登録の社員は
 * 弥生側で突き合わせできないため出力せず、件数を画面に表示する。
 */
final class YayoiCsvExportService
{
    /**
     * $with_rows = false のときは件数の確認だけを行い、勤務表データを読まない（画面表示用）。
     *
     * @return array{count:int, rows: array<int, array<int, string>>, missing_code: array<int, string>}
     *         count … 出力できる社員数 / rows … CSVの行（社員番号順）
     *         missing_code … 確定済みだが社員番号が未登録の社員名
     */
    public static function collect(string $year_month, bool $with_rows = true): array
    {
        $count        = 0;
        $rows         = [];
        $missing_code = [];

        foreach (EmployeeRepository::list_employees(true) as $user) {
            $summary = MonthlySummaryRepository::find($user->ID, $year_month);
            if ($summary === null || (string) $summary['status'] !== MonthlySummaryCalculator::CONFIRMED) {
                continue;
            }

            $code = trim((string) get_user_meta($user->ID, 'employee_code', true));
            if ($code === '') {
                $missing_code[] = (string) $user->display_name;
                continue;
            }

            $count++;
            if (!$with_rows) {
                continue;
            }
            $days = AttendanceGridService::month_data($user->ID, $year_month)['days'];
            $rows[$code . "\0" . $user->ID] = YayoiCsvBuilder::row($code, $year_month, $summary, $days);
        }

        ksort($rows, SORT_STRING);

        return ['count' => $count, 'rows' => array_values($rows), 'missing_code' => $missing_code];
    }

    /** ダウンロード用のCSV本体（Shift-JIS・CR+LF）。 */
    public static function csv(array $rows): string
    {
        return YayoiCsvBuilder::to_sjis(YayoiCsvBuilder::to_csv($rows));
    }

    public static function filename(string $year_month): string
    {
        return 'yayoi_kintai_' . str_replace('-', '', $year_month) . '.csv';
    }
}
