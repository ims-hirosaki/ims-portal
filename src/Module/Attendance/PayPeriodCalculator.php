<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 給与計算サイクル設定（SalaryCycleSettings::closing_day()）から、月次勤務表の
 * 対象期間・提出期限を算出する純粋ロジック（03_attendance_management.md §2.1）。
 *
 * このクラスはDB/WordPressに触れない（date()/mktime()は使うがWP関数ではない）。
 *
 * 締め日設定と対象期間・提出期限の対応（ユーザー確認済み。要件定義書の記載を補完する）：
 * ・当月20日／当月25日：対象期間自体が前月にずれる。年月ラベルは対象期間の終了日が
 *   属する月（§2.1の例：「20日締め → 対象期間 = 前月21日 〜 当月20日」）。
 *   提出期限は対象期間の終了日と同じ（締め＝提出期限）。
 * ・当月末日：対象期間はそのままカレンダー月。提出期限も月末日。
 * ・翌月20日／翌月25日：対象期間は普通のカレンダー月のまま。提出・締め処理の期限だけが
 *   翌月にずれる（スタッフは普通のカレンダー月として入力し、翌月の指定日までに提出すればよい）。
 *
 * 「年月」（year_month）は常に 'Y-m' 形式（例：'2026-09'）で、対象期間の終了日が
 * 属する月を指す（締め日の種類によらず統一）。
 */
final class PayPeriodCalculator
{
    /**
     * 指定した年月（対象期間の終了日が属する月）の対象期間・提出期限を算出する。
     *
     * @return array{start:string, end:string, deadline:string} 'Y-m-d'
     */
    public static function period_for(string $closing_day, string $year_month): array
    {
        [$year, $month] = self::parse_year_month($year_month);

        return match ($closing_day) {
            'day20' => self::shifted_period($year, $month, 20),
            'day25' => self::shifted_period($year, $month, 25),
            'next20' => self::calendar_month_with_deadline($year, $month, 20, 1),
            'next25' => self::calendar_month_with_deadline($year, $month, 25, 1),
            // 'eom' および不正値は「当月末日締め」として扱う（安全側のフォールバック）。
            default => self::calendar_month_with_deadline($year, $month, 0, 0),
        };
    }

    /**
     * 指定した勤務日が、どの年月（'Y-m'）の対象期間に属するかを判定する。
     * 前後1ヶ月の候補について period_for() の範囲に収まるかを調べる
     * （締め日の種類によって対象期間の開始・終了が月をまたぐため）。
     */
    public static function year_month_for_date(string $closing_day, string $work_date): string
    {
        $base = substr($work_date, 0, 7); // 'Y-m'
        foreach ([0, 1, -1] as $offset) {
            $candidate = self::add_months($base, $offset);
            $period = self::period_for($closing_day, $candidate);
            if ($work_date >= $period['start'] && $work_date <= $period['end']) {
                return $candidate;
            }
        }
        return $base; // 通常到達しないフォールバック
    }

    /**
     * 「前月(day+1)日 〜 当月day日」の対象期間（当月20日／当月25日締め用）。
     * 提出期限は対象期間の終了日と同じ。
     */
    private static function shifted_period(int $year, int $month, int $day): array
    {
        $end = sprintf('%04d-%02d-%02d', $year, $month, $day);

        $prev_ym = self::add_months(sprintf('%04d-%02d', $year, $month), -1);
        [$prev_year, $prev_month] = self::parse_year_month($prev_ym);
        $start = sprintf('%04d-%02d-%02d', $prev_year, $prev_month, $day + 1);

        return ['start' => $start, 'end' => $end, 'deadline' => $end];
    }

    /**
     * カレンダー月そのままの対象期間＋$month_offsetヶ月後のday日を提出期限とする
     * （当月末日締め＝$day=0,$month_offset=0／翌月20日・25日締め用）。
     * $day=0 のときは月末日を期限にする。
     */
    private static function calendar_month_with_deadline(int $year, int $month, int $day, int $month_offset): array
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = self::last_day_of_month($year, $month);

        if ($day === 0 && $month_offset === 0) {
            return ['start' => $start, 'end' => $end, 'deadline' => $end];
        }

        $deadline_ym = self::add_months(sprintf('%04d-%02d', $year, $month), $month_offset);
        [$deadline_year, $deadline_month] = self::parse_year_month($deadline_ym);
        $deadline = sprintf('%04d-%02d-%02d', $deadline_year, $deadline_month, $day);

        return ['start' => $start, 'end' => $end, 'deadline' => $deadline];
    }

    private static function last_day_of_month(int $year, int $month): string
    {
        $days = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
        return sprintf('%04d-%02d-%02d', $year, $month, $days);
    }

    /** @return array{0:int, 1:int} */
    private static function parse_year_month(string $year_month): array
    {
        $parts = array_map('intval', explode('-', $year_month));
        return [$parts[0] ?? 0, $parts[1] ?? 1];
    }

    private static function add_months(string $year_month, int $delta): string
    {
        [$year, $month] = self::parse_year_month($year_month);
        return date('Y-m', mktime(0, 0, 0, $month + $delta, 1, $year));
    }
}
