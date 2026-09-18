<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 月次締め・提出・承認のステータス定義と、提出時の集計値算出を行う純粋ロジック
 * （03_attendance_management.md §3.4・§3.5）。
 *
 * このクラスはDB/WordPressに触れない。集計対象の日別データは
 * AttendanceGridService::month_data() の 'days' をそのまま渡す想定。
 */
final class MonthlySummaryCalculator
{
    public const DRAFT               = 'draft';
    public const SUBMITTED           = 'submitted';
    public const CHECKED             = 'checked';
    public const REJECTED_BY_CHECKER = 'rejected_by_checker';
    public const REJECTED_BY_ADMIN   = 'rejected_by_admin';
    public const CONFIRMED           = 'confirmed';

    public const STATUSES = [
        self::DRAFT,
        self::SUBMITTED,
        self::CHECKED,
        self::REJECTED_BY_CHECKER,
        self::REJECTED_BY_ADMIN,
        self::CONFIRMED,
    ];

    /** 提出（新規・再提出とも）を受け付けられる元ステータス（§3.4 状態遷移図）。 */
    public const SUBMITTABLE_FROM = [self::DRAFT, self::REJECTED_BY_CHECKER, self::REJECTED_BY_ADMIN];

    /** 出勤日数にカウントする勤怠フラグ（§4.2 弥生CSV「出勤日数」定義に準拠）。 */
    private const WORK_DAY_FLAGS = [
        AttendanceFlagCalculator::NONE,
        AttendanceFlagCalculator::HOLIDAY_WORK,
        AttendanceFlagCalculator::HOURLY_LEAVE,
        AttendanceFlagCalculator::HALF_DAY_AM,
        AttendanceFlagCalculator::HALF_DAY_PM,
    ];

    /**
     * 1か月分の日別データ（AttendanceGridService::month_data()['days']）から、
     * wp_monthly_summary の total_* に保存する集計値を算出する。
     *
     * 週次法定外残業（週40h超の追加分）は複数月をまたいで日曜始まりの週を合算する
     * 必要があり、ここでは扱わない。3g（月次データスナップショット）で
     * total_overtime_illegal に加算する方針（3b実装時にユーザー確認済み）。
     *
     * @param array<string, array<string, mixed>> $days
     * @return array{
     *   total_work_days:int, total_actual_minutes:int, total_overtime_legal:int,
     *   total_overtime_illegal:int, total_late_night_min:int, total_paid_leave_days:float
     * }
     */
    public static function aggregate(array $days): array
    {
        $total_work_days       = 0;
        $total_actual_minutes  = 0;
        $total_overtime_legal  = 0;
        $total_overtime_illegal = 0;
        $total_late_night      = 0;
        $total_paid_leave_days = 0.0;

        foreach ($days as $day) {
            $flag = (string) ($day['attendance_flag'] ?? AttendanceFlagCalculator::NONE);

            if (in_array($flag, self::WORK_DAY_FLAGS, true)) {
                $total_work_days++;
            }
            if ($flag === AttendanceFlagCalculator::PAID_LEAVE) {
                $total_paid_leave_days += 1.0;
            } elseif (in_array($flag, [AttendanceFlagCalculator::HALF_DAY_AM, AttendanceFlagCalculator::HALF_DAY_PM], true)) {
                $total_paid_leave_days += 0.5;
            }

            $total_actual_minutes   += (int) ($day['actual_minutes'] ?? 0);
            $total_overtime_legal   += (int) ($day['overtime_legal_min'] ?? 0);
            $total_overtime_illegal += (int) ($day['overtime_illegal_min'] ?? 0);
            $total_late_night       += (int) ($day['late_night_minutes'] ?? 0);
        }

        return [
            'total_work_days'        => $total_work_days,
            'total_actual_minutes'   => $total_actual_minutes,
            'total_overtime_legal'   => $total_overtime_legal,
            'total_overtime_illegal' => $total_overtime_illegal,
            'total_late_night_min'   => $total_late_night,
            'total_paid_leave_days'  => $total_paid_leave_days,
        ];
    }

    /** 'Y-m' 形式かどうか（DB/WPに触れない純粋関数）。 */
    public static function is_valid_year_month(string $year_month): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}$/', $year_month);
    }

    /** 画面表示用のステータス名（非技術者向けの平易な日本語。3f-4）。 */
    public static function label(string $status): string
    {
        return match ($status) {
            self::DRAFT               => '未提出',
            self::SUBMITTED           => '提出済み（確認待ち）',
            self::CHECKED             => 'チェック済み（最終承認待ち）',
            self::REJECTED_BY_CHECKER => '差し戻されました（チェック担当者より）',
            self::REJECTED_BY_ADMIN   => '差し戻されました（最終承認者より）',
            self::CONFIRMED           => '確定済み',
            default                   => $status,
        };
    }
}
