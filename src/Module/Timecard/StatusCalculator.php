<?php

declare(strict_types=1);

namespace IMS\Module\Timecard;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 打刻ログから「現在の勤務状態」と「実勤務時間」を算出する純粋ロジック（02 §3.1・§5.2）。
 *
 * このクラスは DB にも WordPress にも触れない。1日分の打刻ログ配列を受け取り、
 * 状態・活性ボタン・実労働秒数を返すだけなので、単体でテストできる。
 * Repository（2b）や RestController（2c）はここを呼ぶだけにして、判定ロジックの
 * 二重実装を避ける。
 *
 * ログの1件は次の形の配列を想定する（Repository が整形して渡す）：
 *   ['punch_type' => 'clock_in'|'break_in'|'break_out'|'clock_out', 'punched_at' => 'Y-m-d H:i:s']
 * punched_at の昇順に並んでいることを前提とする。
 */
final class StatusCalculator
{
    // 状態コード（§3.1）
    public const BEFORE  = 'before';   // 出勤前
    public const WORKING = 'working';  // 勤務中
    public const BREAK   = 'break';    // 休憩中
    public const AFTER   = 'after';    // 退勤後

    // 打刻種別
    public const CLOCK_IN  = 'clock_in';
    public const BREAK_IN  = 'break_in';
    public const BREAK_OUT = 'break_out';
    public const CLOCK_OUT = 'clock_out';

    /**
     * 1日分の打刻ログから現在状態を判定する（§3.1 の表を実装）。
     *
     * @param array<int, array{punch_type:string, punched_at:string}> $logs 昇順の打刻ログ
     * @return string self::BEFORE|WORKING|BREAK|AFTER
     */
    public static function status(array $logs): string
    {
        if ($logs === []) {
            return self::BEFORE;
        }

        $has_clock_in  = false;
        $has_clock_out = false;
        $last_type     = '';

        foreach ($logs as $log) {
            $type = (string) ($log['punch_type'] ?? '');
            if ($type === self::CLOCK_IN) {
                $has_clock_in = true;
            }
            if ($type === self::CLOCK_OUT) {
                $has_clock_out = true;
            }
            $last_type = $type;
        }

        // 退勤後：当日 clock_out がある（§3.1「退勤後」）
        if ($has_clock_out) {
            return self::AFTER;
        }
        // 出勤前：clock_in がない
        if (!$has_clock_in) {
            return self::BEFORE;
        }
        // 休憩中：最終ログが break_in
        if ($last_type === self::BREAK_IN) {
            return self::BREAK;
        }
        // 勤務中：最終ログが clock_in または break_out
        return self::WORKING;
    }

    /**
     * 現在状態で押下できる打刻種別を返す（§3.1「活性ボタン」列）。
     *
     * 休憩中の【退勤】は、DB的にはケースA（休憩補完）へ分岐する例外操作として
     * 活性にする（§3.2）。UI では退勤ボタンを押すと補完ポップアップが出る。
     * 2b では全ボタンが「準備中」ダミーのため、この活性集合は
     * 「見た目の活性/非活性」の制御にのみ使う。
     *
     * @param array<int, array{punch_type:string, punched_at:string}> $logs
     * @return array<int, string> 活性にする punch_type の配列
     */
    public static function active_buttons(array $logs): array
    {
        return match (self::status($logs)) {
            self::BEFORE  => [self::CLOCK_IN],
            self::WORKING => [self::BREAK_IN, self::CLOCK_OUT],
            self::BREAK   => [self::BREAK_OUT, self::CLOCK_OUT], // 退勤は例外処理へ（§3.2）
            self::AFTER   => [],
            default       => [],
        };
    }

    /**
     * 実労働時間（秒）を算出する：勤務時間 − 総休憩時間（§5.2「実勤務時間（経過）」）。
     *
     * ・clock_in がなければ 0。
     * ・clock_out があればその時刻まで、なければ $now（＝経過時間）まで。
     * ・休憩は break_in→break_out の対で差し引く。break_out がまだない
     *   （休憩中）場合は、$now までを休憩として差し引く。
     *
     * @param array<int, array{punch_type:string, punched_at:string}> $logs 昇順
     * @param int $now 現在時刻の UNIX 秒（サーバー時刻）
     * @return int 実労働秒数（負にはならない）
     */
    public static function worked_seconds(array $logs, int $now): int
    {
        $clock_in_ts  = null;
        $clock_out_ts = null;
        $break_total  = 0;
        $break_start  = null;

        foreach ($logs as $log) {
            $type = (string) ($log['punch_type'] ?? '');
            $ts   = self::ts((string) ($log['punched_at'] ?? ''));
            if ($ts === null) {
                continue;
            }

            switch ($type) {
                case self::CLOCK_IN:
                    if ($clock_in_ts === null) {
                        $clock_in_ts = $ts;
                    }
                    break;
                case self::BREAK_IN:
                    $break_start = $ts;
                    break;
                case self::BREAK_OUT:
                    if ($break_start !== null) {
                        $break_total += max(0, $ts - $break_start);
                        $break_start = null;
                    }
                    break;
                case self::CLOCK_OUT:
                    $clock_out_ts = $ts;
                    break;
            }
        }

        if ($clock_in_ts === null) {
            return 0;
        }

        $end = $clock_out_ts ?? $now;

        // 休憩中のまま（break_out 未）なら、now までを休憩として差し引く
        if ($break_start !== null) {
            $break_total += max(0, $end - $break_start);
        }

        return max(0, ($end - $clock_in_ts) - $break_total);
    }

    /**
     * 秒数を「○時間○分」の日本語表記にする（§5.2 の実勤務時間表示）。
     * 秒は切り捨てる（分単位表示）。
     */
    public static function format_duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours   = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        return sprintf('%d時間 %d分', $hours, $minutes);
    }

    /** 状態コード → 日本語ラベル（§5.2） */
    public static function status_label(string $status): string
    {
        return match ($status) {
            self::BEFORE  => '出勤前',
            self::WORKING => '勤務中',
            self::BREAK   => '休憩中',
            self::AFTER   => '退勤済',
            default       => '—',
        };
    }

    /**
     * 状態コード → CSS 修飾子（バッジ色分け用）。
     * 07 デザインシステムのトークンに対応：勤務中=moss、休憩中=gold、退勤済=indigo、出勤前=sub。
     */
    public static function status_variant(string $status): string
    {
        return match ($status) {
            self::WORKING => 'working',
            self::BREAK   => 'break',
            self::AFTER   => 'after',
            self::BEFORE  => 'before',
            default       => 'before',
        };
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
