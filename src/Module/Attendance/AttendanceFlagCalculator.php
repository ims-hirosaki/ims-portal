<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 勤怠フラグが労働時間集計に与える影響を算出する純粋ロジック（03_attendance_management.md §3.2）。
 *
 * このクラスはDB/WordPressに触れない。WorkTimeCalculator（3b）が打刻ログから算出した
 * 「打刻由来の実労働時間」（$raw。未完了の日は null）に勤怠フラグのルールを適用して、
 * wp_daily_attendance へ保存する最終値を組み立てるだけの役割（二重実装を避ける）。
 *
 * 残業（法定内・法定外）と深夜労働は、常に「打刻由来の実労働時間（$raw）」のみを基準に
 * 判定する（ユーザー確認済み）。時間休・半日休の加算分は actual_minutes（総労働時間の集計）
 * にのみ反映し、残業判定には一切関与しない。
 *
 * 「同日に設定できるフラグは1種類のみ」（§3.2 排他制御）という制約は、
 * wp_daily_attendance.attendance_flag が単一のENUM列であることで自動的に満たされる
 * （新しいフラグを保存すれば古いフラグは上書きされる）。UI上の変更確認ダイアログは
 * 3e（画面）側の責務。
 */
final class AttendanceFlagCalculator
{
    public const NONE                 = 'none';
    public const PAID_LEAVE           = 'paid_leave';
    public const HOURLY_LEAVE         = 'hourly_leave';
    public const HALF_DAY_AM          = 'half_day_am';
    public const HALF_DAY_PM          = 'half_day_pm';
    public const HOLIDAY_WORK         = 'holiday_work';
    public const LEGAL_SUBSTITUTE     = 'legal_substitute';
    public const SCHEDULED_SUBSTITUTE = 'scheduled_substitute';

    /** wp_daily_attendance.attendance_flag が取り得る値（DDLのENUMと一致させる）。 */
    public const FLAGS = [
        self::NONE,
        self::PAID_LEAVE,
        self::HOURLY_LEAVE,
        self::HALF_DAY_AM,
        self::HALF_DAY_PM,
        self::HOLIDAY_WORK,
        self::LEGAL_SUBSTITUTE,
        self::SCHEDULED_SUBSTITUTE,
    ];

    /** これらのフラグの日は事業別時間割当てが不要（§3.2「事業別割当て要否」列）。3dで使用する。 */
    public const NO_ALLOCATION_FLAGS = [self::LEGAL_SUBSTITUTE, self::SCHEDULED_SUBSTITUTE];

    /**
     * フラグを適用した最終値を算出する。
     *
     * @param string $flag self::FLAGS のいずれか
     * @param int $scheduled_minutes 所定労働時間（分）
     * @param array{actual_minutes:int, overtime_legal_min:int, overtime_illegal_min:int, late_night_minutes:int}|null $raw
     *        WorkTimeCalculator::calculate_day() の結果。打刻が未完了・存在しなければ null。
     * @param int|null $hourly_leave_minutes 時間休の時間数（分）。flag が hourly_leave のときのみ使う。
     * @return array{actual_minutes:int, overtime_legal_min:int, overtime_illegal_min:int, late_night_minutes:int}|null
     *         null は「この時点では算出できない」（フラグなし・休日出勤で打刻が未完了）ことを表す。
     *         呼び出し側は null のとき wp_daily_attendance を更新しない。
     */
    public static function apply(string $flag, int $scheduled_minutes, ?array $raw, ?int $hourly_leave_minutes): ?array
    {
        switch ($flag) {
            case self::PAID_LEAVE:
                // 実打刻があっても所定時間を優先する（§3.2）。実働を無視するため残業・深夜は発生しない。
                return [
                    'actual_minutes'       => $scheduled_minutes,
                    'overtime_legal_min'   => 0,
                    'overtime_illegal_min' => 0,
                    'late_night_minutes'   => 0,
                ];

            case self::LEGAL_SUBSTITUTE:
            case self::SCHEDULED_SUBSTITUTE:
                // 実打刻があっても無視し、労働時間0とする（§3.2）。
                return [
                    'actual_minutes'       => 0,
                    'overtime_legal_min'   => 0,
                    'overtime_illegal_min' => 0,
                    'late_night_minutes'   => 0,
                ];

            case self::HOURLY_LEAVE:
                // 実打刻時間 ＋ 時間休時間数（§3.2）。残業・深夜の判定は実打刻分のみを基準にする。
                return [
                    'actual_minutes'       => ($raw['actual_minutes'] ?? 0) + max(0, $hourly_leave_minutes ?? 0),
                    'overtime_legal_min'   => $raw['overtime_legal_min'] ?? 0,
                    'overtime_illegal_min' => $raw['overtime_illegal_min'] ?? 0,
                    'late_night_minutes'   => $raw['late_night_minutes'] ?? 0,
                ];

            case self::HALF_DAY_AM:
            case self::HALF_DAY_PM:
                // 実打刻時間 ＋ 所定時間の50%（§3.2）。残業・深夜の判定は実打刻分のみを基準にする。
                $half = (int) round($scheduled_minutes * 0.5);
                return [
                    'actual_minutes'       => ($raw['actual_minutes'] ?? 0) + $half,
                    'overtime_legal_min'   => $raw['overtime_legal_min'] ?? 0,
                    'overtime_illegal_min' => $raw['overtime_illegal_min'] ?? 0,
                    'late_night_minutes'   => $raw['late_night_minutes'] ?? 0,
                ];

            case self::HOLIDAY_WORK:
            case self::NONE:
            default:
                // 実打刻から算出（§3.2）。打刻が未完了・存在しなければ算出しない。
                return $raw;
        }
    }

    /** 勤怠フラグの日本語ラベル（§3.2 の表）。3e（画面実装）で使う想定。 */
    public static function label(string $flag): string
    {
        return match ($flag) {
            self::NONE                 => 'フラグなし（通常出勤）',
            self::PAID_LEAVE           => '有給',
            self::HOURLY_LEAVE         => '時間休',
            self::HALF_DAY_AM          => '半日休（午前）',
            self::HALF_DAY_PM          => '半日休（午後）',
            self::HOLIDAY_WORK         => '休日出勤',
            self::LEGAL_SUBSTITUTE     => '法定振替休',
            self::SCHEDULED_SUBSTITUTE => '所定振替休',
            default                    => $flag,
        };
    }

    /** この勤怠フラグの日は事業別時間割当てが不要か（§3.2「事業別割当て要否」列。3dで使用）。 */
    public static function requires_no_allocation(string $flag): bool
    {
        return in_array($flag, self::NO_ALLOCATION_FLAGS, true);
    }
}
