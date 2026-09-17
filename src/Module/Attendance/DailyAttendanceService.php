<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

use IMS\Module\Timecard\Repository as TimecardRepository;
use IMS\Support\UserRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 02_打刻管理モジュールの打刻ログ → 03_勤怠管理モジュールの日次集計、を橋渡しするサービス（§3.1・§6.2）。
 *
 * 02の `ims_timecard_clocked_out` フック（退勤打刻完了時）から呼ばれる（Bootstrap::init() 参照）。
 * 打刻データを読む（02）→ 算出する（WorkTimeCalculator、純粋関数）→ 書く（03）の
 * 3段構成にし、業務ロジック自体は WorkTimeCalculator に集約する（二重実装を避ける）。
 *
 * 3b時点で扱わないもの（既知のスコープ外。次スライスで対応）：
 * ・打刻修正（2e）による再計算 … 修正時に ims_timecard_clocked_out が再発火しないため、
 *   修正後の日は本サービスでは再計算されない。3c以降で修正フックへの対応を検討する。
 * ・過去分の一括再計算（バックフィル） … 本モジュール導入前に確定していた打刻データは
 *   対象外。必要になった場合は別途ツールを用意する。
 */
final class DailyAttendanceService
{
    /**
     * 指定ユーザー・勤務日の実労働時間等を再計算し、wp_daily_attendance へ保存する。
     * 未完了の1日（休憩が閉じていない等）は WorkTimeCalculator が null を返すため何もしない。
     */
    public static function recalculate(int $user_id, string $work_date): void
    {
        $logs = TimecardRepository::logs_for_date($user_id, $work_date);
        $scheduled_minutes = (int) round(UserRepository::get_scheduled_work_hours($user_id) * 60);

        $metrics = WorkTimeCalculator::calculate_day($logs, $work_date, $scheduled_minutes);
        if ($metrics === null) {
            return;
        }

        DailyAttendanceRepository::save($user_id, $work_date, $scheduled_minutes, $metrics);
    }
}
