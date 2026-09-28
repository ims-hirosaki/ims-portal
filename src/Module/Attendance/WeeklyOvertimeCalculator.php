<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 週次の法定外残業（週40時間超）を算出する純粋ロジック（03_attendance_management.md §3.1・§7.1）。
 * DB/WordPressに触れない。
 *
 * ルール：
 * ・週は日曜日始まり。月次締めの対象期間をまたぐ週は、対象期間内の日だけを数える（§7.1）。
 *   よって前月・翌月のデータは参照しない（渡された $days だけで完結する）。
 * ・週の労働時間は「打刻由来の実働時間」から日次の法定外残業（1日8時間超）を除いた分で合計する
 *   （§3.1「日次法定外として計上済みの分を除く」）。
 * ・有給・時間休・半日休の加算分は数えない。日次の残業判定と同じく、打刻由来の実働のみを
 *   基準にする（AttendanceFlagCalculator の方針。ユーザー確認済み）。有給・振替休の日は
 *   実打刻があっても労働時間0として扱う（§3.2）。
 * ・週の合計が2400分（40時間）を超えた分を週次の法定外残業とする。
 *
 * 法定内残業との二重計上の解消（要件定義書に無い追加仕様。ユーザー確認済み）：
 *   所定が8時間未満の社員では、週40時間を超えた時間の一部がすでに日次の「法定内残業」として
 *   数えられている場合がある。同じ時間を両方に数えないよう、週次の法定外残業になった分は
 *   法定内残業から差し引く。1日の8時間以内の時間は「所定内 → 法定内残業」の順に積み上がる
 *   ものとみなし、40時間を超えた分はその日の後ろ側（＝法定内残業の部分）から先に充てる。
 */
final class WeeklyOvertimeCalculator
{
    public const WEEKLY_LIMIT_MINUTES = 2400;

    /** 実打刻があっても労働時間0として扱うフラグ（§3.2）。 */
    private const ZERO_WORK_FLAGS = [
        AttendanceFlagCalculator::PAID_LEAVE,
        AttendanceFlagCalculator::LEGAL_SUBSTITUTE,
        AttendanceFlagCalculator::SCHEDULED_SUBSTITUTE,
    ];

    /**
     * @param array<string, array<string, mixed>> $days AttendanceGridService::month_data()['days']（キーは 'Y-m-d'）
     * @return array{weekly_illegal_min:int, legal_reduction_min:int}
     *         weekly_illegal_min  … total_overtime_illegal に加算する分
     *         legal_reduction_min … total_overtime_legal から差し引く分
     */
    public static function calculate(array $days): array
    {
        $dates = array_keys($days);
        sort($dates);

        $week_totals     = []; // 週の開始日（日曜）=> それまでの累計
        $weekly_illegal  = 0;
        $legal_reduction = 0;

        foreach ($dates as $date) {
            $day  = $days[$date];
            $week = self::week_start((string) $date);

            $within_daily_limit = max(0, self::worked_minutes($day) - (int) ($day['overtime_illegal_min'] ?? 0));

            $before = $week_totals[$week] ?? 0;
            $after  = $before + $within_daily_limit;
            $week_totals[$week] = $after;

            $excess = max(0, $after - self::WEEKLY_LIMIT_MINUTES) - max(0, $before - self::WEEKLY_LIMIT_MINUTES);
            if ($excess <= 0) {
                continue;
            }

            $weekly_illegal  += $excess;
            $legal_reduction += min($excess, max(0, (int) ($day['overtime_legal_min'] ?? 0)));
        }

        return [
            'weekly_illegal_min'  => $weekly_illegal,
            'legal_reduction_min' => $legal_reduction,
        ];
    }

    /** その日の打刻由来の実働時間（分）。有給・振替休の日は0。 */
    private static function worked_minutes(array $day): int
    {
        $flag = (string) ($day['attendance_flag'] ?? AttendanceFlagCalculator::NONE);
        if (in_array($flag, self::ZERO_WORK_FLAGS, true)) {
            return 0;
        }
        return max(0, (int) ($day['rounded_actual_minutes'] ?? 0));
    }

    /** その日が属する週（日曜始まり）の開始日 'Y-m-d'。 */
    public static function week_start(string $date): string
    {
        $ts  = strtotime($date . ' 00:00:00');
        $dow = (int) date('w', $ts);
        return date('Y-m-d', strtotime("-{$dow} days", $ts));
    }
}
