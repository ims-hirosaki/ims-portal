<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 02_打刻管理モジュールの打刻ログから、実労働時間・残業・深夜労働を算出する
 * 純粋ロジック（03_attendance_management.md §3.1）。
 *
 * このクラスは DB にも WordPress にも触れない。1日分の打刻ログ配列（Module\Timecard\Repository
 * が返すのと同じ形）と所定労働時間を受け取り、結果を返すだけなので単体でテストできる
 * （Module\Timecard\StatusCalculator と同方針）。
 *
 * 3b時点のスコープ：日次の算出のみ。週次法定外残業（週40時間超）は複数日の
 * `wp_daily_attendance.actual_minutes` を日曜始まりで合算しないと算出できないため、
 * ここでは扱わない（月次集計を行う3gで、日曜始まりの週ごとに合算して算出する）。
 *
 * 勤怠フラグ（有給・時間休等）による労働時間補正は3c（勤怠フラグ管理）で行う。
 * ここでは打刻実績のみから「フラグなし出勤」の値を算出する。
 */
final class WorkTimeCalculator
{
    /** 法定労働時間（1日・分）＝8時間。§3.1「法定内残業」「法定外残業（日次）」の基準値。 */
    public const LEGAL_DAILY_MINUTES = 480;

    /** 深夜帯の開始時刻（時）。§3.1「深夜労働：22:00〜翌5:00」。 */
    private const NIGHT_START_HOUR = 22;
    /** 深夜帯の終了時刻（時）。 */
    private const NIGHT_END_HOUR = 5;

    /**
     * 1日分の打刻ログから、実労働時間・残業・深夜労働（分）を算出する。
     *
     * 退勤打刻がまだない、または休憩が閉じていない（休憩中のまま）「未完了の1日」は
     * 算出できないため null を返す。呼び出し側（DailyAttendanceService）は
     * null のときは wp_daily_attendance を更新しない。
     *
     * @param array<int, array{punch_type:string, punched_at:string}> $logs punched_at昇順の当日ログ
     * @param string $work_date 'Y-m-d'。深夜帯の判定基準日（§3.1）。
     * @param int $scheduled_minutes 所定労働時間（分）。01モジュールの scheduled_work_hours 由来。
     * @return array{actual_minutes:int, overtime_legal_min:int, overtime_illegal_min:int, late_night_minutes:int}|null
     */
    public static function calculate_day(array $logs, string $work_date, int $scheduled_minutes): ?array
    {
        $intervals = self::work_intervals($logs);
        if ($intervals === null) {
            return null;
        }

        $actual_seconds = 0;
        foreach ($intervals as [$start, $end]) {
            $actual_seconds += max(0, $end - $start);
        }
        $actual_minutes = intdiv($actual_seconds, 60);

        $overtime_legal_min = 0;
        if ($scheduled_minutes < self::LEGAL_DAILY_MINUTES) {
            // 所定が8h未満の場合のみ発生（§3.1）。所定と8hの差の範囲でしか生じない。
            $overtime_legal_min = max(0, min($actual_minutes, self::LEGAL_DAILY_MINUTES) - $scheduled_minutes);
        }
        $overtime_illegal_min = max(0, $actual_minutes - self::LEGAL_DAILY_MINUTES);

        return [
            'actual_minutes'       => $actual_minutes,
            'overtime_legal_min'   => $overtime_legal_min,
            'overtime_illegal_min' => $overtime_illegal_min,
            'late_night_minutes'   => self::late_night_minutes($intervals, $work_date),
        ];
    }

    /**
     * 打刻ログから「実際に働いた時間帯」の一覧（休憩区間を除いた区間）を組み立てる。
     * clock_in〜clock_out の間から、break_in〜break_out の各区間を差し引く。
     *
     * 未完了の1日（clock_in/clock_outが揃っていない・休憩が閉じていない）は null を返す。
     *
     * @param array<int, array{punch_type:string, punched_at:string}> $logs
     * @return array<int, array{0:int, 1:int}>|null [start_ts, end_ts] の配列（秒単位UNIX時刻）
     */
    private static function work_intervals(array $logs): ?array
    {
        $clock_in_ts  = null;
        $clock_out_ts = null;
        $break_start  = null;
        $breaks       = [];

        foreach ($logs as $log) {
            $type = (string) ($log['punch_type'] ?? '');
            $ts   = self::ts((string) ($log['punched_at'] ?? ''));
            if ($ts === null) {
                continue;
            }

            switch ($type) {
                case 'clock_in':
                    if ($clock_in_ts === null) {
                        $clock_in_ts = $ts;
                    }
                    break;
                case 'break_in':
                    $break_start = $ts;
                    break;
                case 'break_out':
                    if ($break_start !== null) {
                        $breaks[] = [$break_start, $ts];
                        $break_start = null;
                    }
                    break;
                case 'clock_out':
                    $clock_out_ts = $ts;
                    break;
            }
        }

        // 出退勤が揃っていない、または休憩中のままの1日は算出しない
        if ($clock_in_ts === null || $clock_out_ts === null || $break_start !== null) {
            return null;
        }
        if ($clock_out_ts <= $clock_in_ts) {
            return null;
        }

        usort($breaks, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        $intervals = [];
        $cursor = $clock_in_ts;
        foreach ($breaks as [$break_s, $break_e]) {
            if ($break_s > $cursor) {
                $intervals[] = [$cursor, $break_s];
            }
            $cursor = max($cursor, $break_e);
        }
        if ($cursor < $clock_out_ts) {
            $intervals[] = [$cursor, $clock_out_ts];
        }

        return $intervals;
    }

    /**
     * 実働区間のうち、深夜帯（22:00〜翌5:00）に重なる時間（分）を算出する（§3.1）。
     *
     * work_date を跨ぐ深夜帯には2通りあるため両方をチェックする：
     * ・work_date当日の夜（work_date 22:00 〜 翌日 5:00）… 日またぎ勤務の後半
     * ・work_date前日の夜からの続き（前日 22:00 〜 work_date 5:00）… 早朝出勤の前半
     *
     * @param array<int, array{0:int, 1:int}> $intervals
     */
    private static function late_night_minutes(array $intervals, string $work_date): int
    {
        $base = self::ts($work_date . ' 00:00:00');
        if ($base === null) {
            return 0;
        }

        $window_after_start  = $base + self::NIGHT_START_HOUR * 3600;
        $window_after_end    = $base + 24 * 3600 + self::NIGHT_END_HOUR * 3600;
        $window_before_start = $base - 24 * 3600 + self::NIGHT_START_HOUR * 3600;
        $window_before_end   = $base + self::NIGHT_END_HOUR * 3600;

        $total_seconds = 0;
        foreach ($intervals as [$start, $end]) {
            $total_seconds += self::overlap_seconds($start, $end, $window_after_start, $window_after_end);
            $total_seconds += self::overlap_seconds($start, $end, $window_before_start, $window_before_end);
        }

        return intdiv($total_seconds, 60);
    }

    private static function overlap_seconds(int $s1, int $e1, int $s2, int $e2): int
    {
        return max(0, min($e1, $e2) - max($s1, $s2));
    }

    /**
     * 打刻ログから、出退勤時刻・休憩区間を「work_date 0時からの経過分」で取り出す（3e：グリッド表示用）。
     * ProjectHourCalculator と同じ表現（日をまたぐ場合は1440分を超える値）を使うため、
     * 事業別時間割当てのブロックと同じ座標系でグリッド描画できる。
     *
     * calculate_day() と異なり、未完了の1日（退勤前・休憩中）でも取れる範囲の値を返す
     * （グリッドは「打刻の進行状況」もそのまま表示したいため）。
     *
     * @param array<int, array{punch_type:string, punched_at:string}> $logs
     * @return array{clock_in_minutes:?int, clock_out_minutes:?int, breaks: array<int, array{0:int, 1:int}>}
     */
    public static function punch_times(array $logs, string $work_date): array
    {
        $midnight = self::ts($work_date . ' 00:00:00');

        $clock_in_ts  = null;
        $clock_out_ts = null;
        $break_start  = null;
        $breaks       = [];

        foreach ($logs as $log) {
            $type = (string) ($log['punch_type'] ?? '');
            $ts   = self::ts((string) ($log['punched_at'] ?? ''));
            if ($ts === null) {
                continue;
            }

            switch ($type) {
                case 'clock_in':
                    if ($clock_in_ts === null) {
                        $clock_in_ts = $ts;
                    }
                    break;
                case 'break_in':
                    $break_start = $ts;
                    break;
                case 'break_out':
                    if ($break_start !== null) {
                        $breaks[] = [$break_start, $ts];
                        $break_start = null;
                    }
                    break;
                case 'clock_out':
                    $clock_out_ts = $ts;
                    break;
            }
        }

        $to_minutes = static function (?int $ts) use ($midnight): ?int {
            if ($ts === null || $midnight === null) {
                return null;
            }
            return (int) round(($ts - $midnight) / 60);
        };

        return [
            'clock_in_minutes'  => $to_minutes($clock_in_ts),
            'clock_out_minutes' => $to_minutes($clock_out_ts),
            'breaks'            => array_map(
                static fn(array $b): array => [$to_minutes($b[0]), $to_minutes($b[1])],
                $breaks
            ),
        ];
    }

    private static function ts(string $datetime): ?int
    {
        if ($datetime === '') {
            return null;
        }
        $ts = strtotime($datetime);
        return $ts === false ? null : $ts;
    }
}
