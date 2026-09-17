<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

use IMS\Module\Timecard\Repository as TimecardRepository;
use IMS\Support\UserRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 02_打刻管理モジュールの打刻ログ → 03_勤怠管理モジュールの日次集計、を橋渡しするサービス（§3.1・§3.2・§6.2）。
 *
 * 02の `ims_timecard_clocked_out` フック（退勤打刻完了時）から呼ばれる（Bootstrap::init() 参照）。
 * 打刻データを読む（02）→ 算出する（WorkTimeCalculator・AttendanceFlagCalculator、
 * いずれも純粋関数）→ 書く（03）の3段構成にし、業務ロジック自体は算出クラス側に集約する
 * （二重実装を避ける）。
 *
 * 退勤打刻のたびに呼ばれるため、既に勤怠フラグが設定されている日（例：有給申請済みの日に
 * 打刻もある等）を打刻由来の値だけで上書きしないよう、保存済みのフラグを読み直してから
 * AttendanceFlagCalculator に通す（3c）。フラグ未設定（新規の日）は 'none' として扱われ、
 * 3b時点と同じ「打刻から算出」の挙動になる。
 *
 * 既知のスコープ外（次スライス以降で対応）：
 * ・打刻修正（2e）による再計算 … 修正時に ims_timecard_clocked_out が再発火しないため、
 *   修正後の日は本サービスでは再計算されない。
 * ・過去分の一括再計算（バックフィル） … 本モジュール導入前に確定していた打刻データは対象外。
 */
final class DailyAttendanceService
{
    /**
     * 指定ユーザー・勤務日の実労働時間等を再計算し、wp_daily_attendance へ保存する。
     * 未完了の1日（休憩が閉じていない・フラグなしで打刻も未完了等）は算出結果が null に
     * なるため何もしない。
     */
    public static function recalculate(int $user_id, string $work_date): void
    {
        $existing = DailyAttendanceRepository::find($user_id, $work_date);
        $flag = $existing !== null ? (string) $existing['attendance_flag'] : AttendanceFlagCalculator::NONE;
        $hourly_leave_minutes = $existing !== null && $existing['hourly_leave_minutes'] !== null
            ? (int) $existing['hourly_leave_minutes']
            : null;

        $scheduled_minutes = (int) round(UserRepository::get_scheduled_work_hours($user_id) * 60);
        $logs = TimecardRepository::logs_for_date($user_id, $work_date);
        $raw  = WorkTimeCalculator::calculate_day($logs, $work_date, $scheduled_minutes);

        $metrics = AttendanceFlagCalculator::apply($flag, $scheduled_minutes, $raw, $hourly_leave_minutes);
        if ($metrics === null) {
            return;
        }

        DailyAttendanceRepository::save($user_id, $work_date, $scheduled_minutes, $flag, $hourly_leave_minutes, $metrics);
    }
}
