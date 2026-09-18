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
 * 打刻の丸め（ユーザー確認済み・要件定義書に無い追加仕様）：
 * ・打刻ログそのもの（wp_attendance_logs）は一切変更しない。生の実時刻は必ず残す。
 * ・勤怠として管理する労働時間（actual_minutes・残業・深夜労働・事業別時間割当ての範囲/合計）は、
 *   打刻を丸め単位（TimeRoundingSettings）で丸めた時刻を基準に算出する。
 *   - 出勤：切り上げ（例：8:20→8:30）／退勤：切り下げ（例：17:29→17:15）
 *     … 実労働時間を実際より少なく見積もる方向（内側に丸める）。
 *   - 休憩開始：切り下げ／休憩終了：切り上げ … 休憩を実際より長く見積もる方向。
 *     出退勤と同じく「実労働時間を過大計上しない」方向に統一している。
 * ・丸め後の出退勤時刻は rounded_clock_in_minutes / rounded_clock_out_minutes として
 *   戻り値に含め、呼び出し側が wp_daily_attendance に「丸め後の時刻」を別データとして
 *   保存できるようにする（打刻の生データとは別に、勤怠管理上の時刻を残す）。
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
     * 1日分の打刻ログから、丸め後の実労働時間・残業・深夜労働（分）を算出する。
     *
     * 退勤打刻がまだない、または休憩が閉じていない（休憩中のまま）「未完了の1日」は
     * 算出できないため null を返す。呼び出し側（DailyAttendanceService等）は
     * null のときは wp_daily_attendance を更新しない。
     *
     * @param array<int, array{punch_type:string, punched_at:string}> $logs punched_at昇順の当日ログ
     * @param string $work_date 'Y-m-d'。深夜帯の判定・丸めの基準日（§3.1）。
     * @param int $scheduled_minutes 所定労働時間（分）。01モジュールの scheduled_work_hours 由来。
     * @param int $rounding_minutes 丸め単位（分）。TimeRoundingSettings::minutes() 由来。1以下は丸めなし。
     * @return array{
     *     actual_minutes:int, overtime_legal_min:int, overtime_illegal_min:int, late_night_minutes:int,
     *     rounded_clock_in_minutes:int, rounded_clock_out_minutes:int,
     *     rounded_breaks: array<int, array{0:int, 1:int}>
     * }|null
     */
    public static function calculate_day(array $logs, string $work_date, int $scheduled_minutes, int $rounding_minutes = 1): ?array
    {
        $boundaries = self::extract_boundaries($logs);
        if ($boundaries === null) {
            return null;
        }

        $midnight = self::ts($work_date . ' 00:00:00');
        if ($midnight === null) {
            return null;
        }

        $unit = max(1, $rounding_minutes);
        $rounded = self::round_boundaries($boundaries, $midnight, $unit);
        if ($rounded === null) {
            return null; // 丸めの結果、退勤が出勤以前になった等の異常値
        }

        $intervals = self::intervals_from_boundaries($rounded['clock_in'], $rounded['clock_out'], $rounded['breaks']);

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

        $to_minutes = static fn(int $ts): int => (int) round(($ts - $midnight) / 60);

        return [
            'actual_minutes'       => $actual_minutes,
            'overtime_legal_min'   => $overtime_legal_min,
            'overtime_illegal_min' => $overtime_illegal_min,
            'late_night_minutes'   => self::late_night_minutes($intervals, $work_date),

            'rounded_clock_in_minutes'  => $to_minutes($rounded['clock_in']),
            'rounded_clock_out_minutes' => $to_minutes($rounded['clock_out']),
            'rounded_breaks'            => array_map(
                static fn(array $b): array => [$to_minutes($b[0]), $to_minutes($b[1])],
                $rounded['breaks']
            ),
        ];
    }

    /**
     * 指定した「分」を丸め単位で切り上げる（例：unit=15のとき 8:20(=500分)→8:30(=510分)）。
     * 出勤・休憩終了の丸めに使う（DB/WPに触れない純粋関数）。
     */
    public static function round_up(int $minutes, int $unit): int
    {
        if ($unit <= 1) {
            return $minutes;
        }
        $remainder = $minutes % $unit;
        return $remainder === 0 ? $minutes : $minutes + ($unit - $remainder);
    }

    /**
     * 指定した「分」を丸め単位で切り下げる（例：unit=15のとき 17:29(=1049分)→17:15(=1035分)）。
     * 退勤・休憩開始の丸めに使う（DB/WPに触れない純粋関数）。
     */
    public static function round_down(int $minutes, int $unit): int
    {
        if ($unit <= 1) {
            return $minutes;
        }
        return $minutes - ($minutes % $unit);
    }

    /**
     * 打刻ログから、出退勤時刻・休憩区間を「work_date 0時からの経過分」で取り出す（3e：グリッド表示用）。
     * ProjectHourCalculator と同じ表現（日をまたぐ場合は1440分を超える値）を使うため、
     * 事業別時間割当てのブロックと同じ座標系でグリッド描画できる。
     *
     * calculate_day() と異なり、未完了の1日（退勤前・休憩中）でも取れる範囲の値を返す
     * （グリッドは「打刻の進行状況」もそのまま表示したいため）。丸めは行わない生の値。
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

    /**
     * 打刻ログから出退勤・休憩の「生の」境界（UNIX秒）を検証つきで取り出す。
     * 出退勤が揃っていない、休憩が閉じていない（休憩中のまま）、退勤が出勤以前の
     * いずれかに該当する「未完了の1日」は null を返す。
     *
     * @param array<int, array{punch_type:string, punched_at:string}> $logs
     * @return array{clock_in:int, clock_out:int, breaks: array<int, array{0:int, 1:int}>}|null
     */
    private static function extract_boundaries(array $logs): ?array
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

        if ($clock_in_ts === null || $clock_out_ts === null || $break_start !== null) {
            return null;
        }
        if ($clock_out_ts <= $clock_in_ts) {
            return null;
        }

        usort($breaks, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        return ['clock_in' => $clock_in_ts, 'clock_out' => $clock_out_ts, 'breaks' => $breaks];
    }

    /**
     * 生の境界（UNIX秒）を丸め単位で丸める。出勤は切り上げ・退勤は切り下げ・
     * 休憩開始は切り下げ・休憩終了は切り上げる（クラス冒頭コメント参照）。
     *
     * 丸めた結果、退勤が出勤以前になった場合は null（丸め単位が勤務時間に対して
     * 粗すぎる異常値）。休憩は丸めた結果 終了<=開始 になったものは「無かった」ものとして
     * 除外する（丸め単位未満の極端に短い休憩のみ想定される稀なケース）。
     *
     * @param array{clock_in:int, clock_out:int, breaks: array<int, array{0:int, 1:int}>} $boundaries
     * @return array{clock_in:int, clock_out:int, breaks: array<int, array{0:int, 1:int}>}|null
     */
    private static function round_boundaries(array $boundaries, int $midnight, int $unit): ?array
    {
        $to_min = static fn(int $ts): int => (int) round(($ts - $midnight) / 60);
        $to_ts  = static fn(int $min): int => $midnight + $min * 60;

        $clock_in  = $to_ts(self::round_up($to_min($boundaries['clock_in']), $unit));
        $clock_out = $to_ts(self::round_down($to_min($boundaries['clock_out']), $unit));
        if ($clock_out <= $clock_in) {
            return null;
        }

        $breaks = [];
        foreach ($boundaries['breaks'] as [$break_start, $break_end]) {
            $rounded_start = $to_ts(self::round_down($to_min($break_start), $unit));
            $rounded_end   = $to_ts(self::round_up($to_min($break_end), $unit));
            if ($rounded_end > $rounded_start) {
                $breaks[] = [$rounded_start, $rounded_end];
            }
        }

        return ['clock_in' => $clock_in, 'clock_out' => $clock_out, 'breaks' => $breaks];
    }

    /**
     * 出退勤・休憩の境界（UNIX秒）から「実際に働いた時間帯」の一覧を組み立てる
     * （休憩区間を除いた区間。clock_in〜clock_out から breaks を差し引く）。
     *
     * @param array<int, array{0:int, 1:int}> $breaks
     * @return array<int, array{0:int, 1:int}>
     */
    private static function intervals_from_boundaries(int $clock_in, int $clock_out, array $breaks): array
    {
        $intervals = [];
        $cursor = $clock_in;
        foreach ($breaks as [$break_s, $break_e]) {
            if ($break_s > $cursor) {
                $intervals[] = [$cursor, $break_s];
            }
            $cursor = max($cursor, $break_e);
        }
        if ($cursor < $clock_out) {
            $intervals[] = [$cursor, $clock_out];
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

    private static function ts(string $datetime): ?int
    {
        if ($datetime === '') {
            return null;
        }
        $ts = strtotime($datetime);
        return $ts === false ? null : $ts;
    }
}
