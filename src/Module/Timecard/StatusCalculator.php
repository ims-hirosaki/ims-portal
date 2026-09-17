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

    // ── 状態遷移の検証（2c で追加） ─────────────────────────

    /** 検証OK */
    public const OK = 'ok';
    /** 休憩中の退勤。§3.2 ケースA の休憩補完が必要（2d で対応）。 */
    public const NEEDS_BREAK_COMPLETION = 'break_completion_required';

    /**
     * その打刻を「単独の1レコードとして」記録してよいかを判定する（§3.1 の活性表の裏返し）。
     *
     * 打刻API（2c）はクライアントのボタン活性制御を信用せず、必ずここを通す。
     * 判定材料は同じ `$logs` なので、UI の活性制御とサーバー検証が食い違うことはない。
     *
     * 戻り値が self::OK 以外はエラーコード。self::NEEDS_BREAK_COMPLETION だけは
     * 「不正な操作」ではなく「補完フローへ分岐せよ」の意味を持つ（§3.2 ケースA）。
     *
     * @param array<int, array{punch_type:string, punched_at:string}> $logs 当該 work_date の昇順ログ
     * @return string self::OK またはエラーコード
     */
    public static function validate_transition(array $logs, string $punch_type): string
    {
        if (!in_array($punch_type, [self::CLOCK_IN, self::BREAK_IN, self::BREAK_OUT, self::CLOCK_OUT], true)) {
            return 'unknown_punch_type';
        }

        $status = self::status($logs);

        // 退勤後は当日分の打刻を一切受け付けない（§3.1「すべて非活性」）
        if ($status === self::AFTER) {
            return 'already_clocked_out';
        }

        // 出勤は1日1回だけ（§5.1 制約・ルール）。アプリ層で担保する。
        if ($punch_type === self::CLOCK_IN) {
            return $status === self::BEFORE ? self::OK : 'already_clocked_in';
        }

        // 以降は出勤済みであることが前提
        if ($status === self::BEFORE) {
            return 'not_clocked_in';
        }

        return match ($punch_type) {
            self::BREAK_IN  => $status === self::BREAK ? 'already_on_break' : self::OK,
            self::BREAK_OUT => $status === self::BREAK ? self::OK : 'not_on_break',
            // 勤務中の退勤はそのまま記録。休憩中の退勤は補完フローへ（§3.2 ケースA）。
            self::CLOCK_OUT => $status === self::BREAK ? self::NEEDS_BREAK_COMPLETION : self::OK,
            default         => 'unknown_punch_type',
        };
    }

    /**
     * validate_transition() のエラーコードを利用者向けの日本語メッセージにする。
     * API のレスポンスにも、将来の画面表示にも同じ文言を使う。
     *
     * `currently_on_break`・`break_already_recorded`・`invalid_break_minutes`・
     * `invalid_break_range` は 2d（休憩補完付き退勤）のエラーコード。
     */
    public static function error_message(string $code): string
    {
        return match ($code) {
            'already_clocked_out'         => '本日はすでに退勤済みです。修正が必要な場合は打刻修正をご利用ください。',
            'already_clocked_in'          => '本日はすでに出勤打刻が済んでいます。',
            'not_clocked_in'              => 'まだ出勤打刻がありません。先に「出勤」を打刻してください。',
            'already_on_break'            => 'すでに休憩中です。',
            'not_on_break'                => '休憩中ではありません。',
            self::NEEDS_BREAK_COMPLETION  => '休憩中のため、先に「休憩終了」を打刻してから退勤してください。',
            'unknown_punch_type'          => '打刻の種別が不正です。',
            'currently_on_break'          => '現在休憩中です。休憩終了を打刻してから退勤してください。',
            'break_already_recorded'      => '本日はすでに休憩を記録済みです。通常の退勤をご利用ください。',
            'invalid_break_minutes'       => '入力した休憩時間が現在時刻を超えています。',
            'invalid_break_range'         => '入力した休憩の開始・終了時刻が勤務時間内に収まっていません。',
            default                       => 'この操作は現在の状態では実行できません。',
        };
    }

    /**
     * 当日ログに `break_in` が1件でも含まれるか（§3.2 ケースB の前提判定）。
     *
     * @param array<int, array{punch_type:string, punched_at:string}> $logs
     */
    public static function has_break_in(array $logs): bool
    {
        foreach ($logs as $log) {
            if (($log['punch_type'] ?? '') === self::BREAK_IN) {
                return true;
            }
        }
        return false;
    }

    /**
     * 1日分の打刻ログ（punched_at昇順に並べ直したもの）が時系列として矛盾していないかを
     * 検証する（§3.4 手順5「修正後の整合性チェック」）。打刻修正で1件の時刻を書き換えた後、
     * 呼び出し側が並べ直した配列を渡して使う。
     *
     * 検証する不変条件：
     * ・punched_at が厳密な単調増加であること（同時刻・逆転を許さない）
     * ・clock_in があるなら先頭・1件のみ
     * ・clock_out があるなら末尾・1件のみ
     * ・break_in の直後は必ず break_out（休憩は開始→終了の対で並ぶ）
     *
     * @param array<int, array{punch_type:string, punched_at:string}> $logs punched_at昇順
     */
    public static function is_chronologically_consistent(array $logs): bool
    {
        if ($logs === []) {
            return true;
        }

        $count = count($logs);
        $prev_ts = null;
        $clock_in_count  = 0;
        $clock_out_count = 0;

        foreach ($logs as $i => $log) {
            $ts = self::ts((string) ($log['punched_at'] ?? ''));
            if ($ts === null) {
                return false;
            }
            if ($prev_ts !== null && $ts <= $prev_ts) {
                return false; // 同時刻・逆転
            }
            $prev_ts = $ts;

            $type = (string) ($log['punch_type'] ?? '');
            if ($type === self::CLOCK_IN) {
                $clock_in_count++;
                if ($i !== 0) {
                    return false; // clock_in は先頭のみ
                }
            }
            if ($type === self::CLOCK_OUT) {
                $clock_out_count++;
                if ($i !== $count - 1) {
                    return false; // clock_out は末尾のみ
                }
            }
        }

        if ($clock_in_count > 1 || $clock_out_count > 1) {
            return false;
        }

        // break_in の直後は必ず break_out（対で並ぶ）
        foreach ($logs as $i => $log) {
            if (($log['punch_type'] ?? '') === self::BREAK_IN) {
                $next = $logs[$i + 1] ?? null;
                if ($next === null || ($next['punch_type'] ?? '') !== self::BREAK_OUT) {
                    return false;
                }
            }
        }

        return true;
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
