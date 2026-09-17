<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

use IMS\Module\Timecard\Repository as TimecardRepository;
use IMS\Support\UserRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 勤怠フラグの設定を行うサービス（03_attendance_management.md §3.2）。
 *
 * 打刻データ（02）を読む → AttendanceFlagCalculator で最終値を算出する → 保存する、
 * という DailyAttendanceService::recalculate() と同じ流れを「人が明示的にフラグを
 * 指定する」入口として提供する。
 *
 * 3c時点ではこのサービス自体に画面・APIは無い（未接続）。3e（スタッフ向け月次勤務表画面）の
 * 勤怠フラグ・プルダウンから、このメソッドをそのまま呼び出す想定。
 */
final class AttendanceFlagService
{
    /**
     * 指定ユーザー・勤務日の勤怠フラグを設定する。
     *
     * @return true|\WP_Error
     */
    public static function set_flag(int $user_id, string $work_date, string $flag, ?int $hourly_leave_minutes = null)
    {
        if (!in_array($flag, AttendanceFlagCalculator::FLAGS, true)) {
            return new \WP_Error('validation', '不正な勤怠フラグです。');
        }

        $stored_hourly_minutes = null;
        if ($flag === AttendanceFlagCalculator::HOURLY_LEAVE) {
            if (!is_int($hourly_leave_minutes) || $hourly_leave_minutes <= 0) {
                return new \WP_Error('validation', '時間休の時間数（分）を正しく入力してください。');
            }
            $stored_hourly_minutes = $hourly_leave_minutes;
        }

        $scheduled_minutes = (int) round(UserRepository::get_scheduled_work_hours($user_id) * 60);
        $logs = TimecardRepository::logs_for_date($user_id, $work_date);
        $raw  = WorkTimeCalculator::calculate_day($logs, $work_date, $scheduled_minutes);

        $metrics = AttendanceFlagCalculator::apply($flag, $scheduled_minutes, $raw, $stored_hourly_minutes);
        if ($metrics === null) {
            return new \WP_Error('incomplete', 'この日はまだ退勤打刻が完了していないため、フラグを反映できません。');
        }

        $saved = DailyAttendanceRepository::save($user_id, $work_date, $scheduled_minutes, $flag, $stored_hourly_minutes, $metrics);
        if (!$saved) {
            return new \WP_Error('db_error', '勤怠フラグの保存に失敗しました。');
        }

        return true;
    }
}
