<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

use IMS\Module\Timecard\Repository as TimecardRepository;
use IMS\Support\UserRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 事業別時間の自動割り当てを保存するサービス（03 §3.3）。
 * どの行を作るか・上書きしてよいかの判定は AutoAllocationCalculator（純粋関数）に任せる。
 *
 * ・3q-2 有給日：勤怠フラグを有給にしたとき、全社共通の所定の始業時刻（WorkStartSettings）から
 *   所定労働時間分を、有給のデフォルト事業（is_default_for_paid_leave）へ割り当てる。
 *   有給を外したときは、自動割り当てのまま手を加えていない行を取り除く。
 *
 * ・3q-4 通常勤務日：退勤打刻（ims_timecard_clocked_out）のとき、社員のデフォルト事業（default_business_id）へ
 *   出勤〜退勤（丸め後）から休憩を除いた時間を割り当てる。手で入力済みの日・有給や振替休の日・
 *   提出後（編集ロック中）の月は行わない。割り当てた事業名は、退勤の完了メッセージで本人に知らせる（3q-5）。
 *
 * on_flag_changed() は呼び出し側（AttendanceFlagService）で月次提出後の編集ロックを確認済みの前提。
 * 自動割り当ては補助機能のため、失敗しても勤怠フラグの保存自体は失敗扱いにしない（戻り値は参考）。
 */
final class AutoAllocationService
{
    /**
     * このリクエストの退勤打刻で自動割り当てした事業名（user_id => [work_date => 事業名]）。
     * 退勤の完了メッセージ（3q-5）に使う。リクエストをまたいでは残さない。
     *
     * @var array<int, array<string, string>>
     */
    private static array $assigned_on_clock_out = [];

    /**
     * 勤怠フラグが変わった直後に呼ぶ。
     */
    public static function on_flag_changed(int $user_id, string $work_date, string $old_flag, string $new_flag): bool
    {
        $existing = ProjectHourRepository::for_date($user_id, $work_date);

        if ($new_flag === AttendanceFlagCalculator::PAID_LEAVE) {
            if (!AutoAllocationCalculator::can_replace($existing)) {
                return false; // 手で入力した行がある日は触らない
            }
            $business = BusinessRepository::default_for_paid_leave();
            $rows = AutoAllocationCalculator::paid_leave_rows(
                $business !== null && (int) $business['is_active'] === 1 ? (int) $business['business_id'] : null,
                WorkStartSettings::minutes(),
                (int) round(UserRepository::get_scheduled_work_hours($user_id) * 60)
            );
            return $rows !== [] && ProjectHourRepository::replace_day($user_id, $work_date, $rows);
        }

        if ($old_flag === AttendanceFlagCalculator::PAID_LEAVE && AutoAllocationCalculator::should_clear_on_flag_off($existing)) {
            return ProjectHourRepository::replace_day($user_id, $work_date, []);
        }

        return false;
    }

    /**
     * 退勤打刻の直後に呼ぶ（ims_timecard_clocked_out。日次勤怠の再計算より後の優先度で登録する）。
     */
    public static function on_clocked_out(int $user_id, string $work_date): bool
    {
        $business_id = UserRepository::get_default_business_id($user_id);
        if ($business_id === null) {
            return false;
        }
        $business = BusinessRepository::find($business_id);
        if ($business === null || (int) $business['is_active'] !== 1) {
            return false;
        }

        if (!MonthlySummaryService::is_editable($user_id, SalaryCycleSettings::year_month_for_date($work_date))) {
            return false;
        }

        $daily = DailyAttendanceRepository::find($user_id, $work_date);
        $flag  = $daily !== null ? (string) $daily['attendance_flag'] : AttendanceFlagCalculator::NONE;
        if ($flag === AttendanceFlagCalculator::PAID_LEAVE || AttendanceFlagCalculator::requires_no_allocation($flag)) {
            return false;
        }

        if (!AutoAllocationCalculator::can_replace(ProjectHourRepository::for_date($user_id, $work_date))) {
            return false; // 手で入力済みの日は上書きしない
        }

        $scheduled_minutes = (int) round(UserRepository::get_scheduled_work_hours($user_id) * 60);
        $raw = WorkTimeCalculator::calculate_day(
            TimecardRepository::logs_for_date($user_id, $work_date),
            $work_date,
            $scheduled_minutes,
            TimeRoundingSettings::minutes()
        );
        if ($raw === null) {
            return false;
        }

        $rows = AutoAllocationCalculator::default_business_rows(
            $business_id,
            (int) $raw['rounded_clock_in_minutes'],
            (int) $raw['rounded_clock_out_minutes'],
            $raw['rounded_breaks'] ?? []
        );
        if ($rows === [] || !ProjectHourRepository::replace_day($user_id, $work_date, $rows)) {
            return false;
        }

        self::$assigned_on_clock_out[$user_id][$work_date] = (string) $business['business_name'];
        return true;
    }

    /** このリクエストの退勤打刻で自動割り当てした事業名。無ければ null（3q-5 の完了メッセージ用）。 */
    public static function assigned_business_name(int $user_id, string $work_date): ?string
    {
        return self::$assigned_on_clock_out[$user_id][$work_date] ?? null;
    }
}
