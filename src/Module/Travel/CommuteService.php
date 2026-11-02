<?php

declare(strict_types=1);

namespace IMS\Module\Travel;

use IMS\Module\Attendance\DailyAttendanceRepository;
use IMS\Module\Attendance\MonthlySummaryService;
use IMS\Module\Attendance\SalaryCycleSettings;
use IMS\Module\Timecard\Repository as TimecardRepository;
use IMS\Support\UserRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 通勤交通費の自動生成（04 §3.1）。
 *
 * その日の出勤打刻（02）と勤怠フラグ（03）から、通勤レコードを作る／0円処理にする。
 * 何度呼んでも結果は同じ（べき等）。スタッフが編集した内容は上書きしない。
 * 提出済み以降の月度（読み取り専用）には何もしない（§3.5。差し戻し後に再び編集できる状態で呼ばれたとき反映される）。
 * 事業（business_id）は NULL で作る。サジェストは画面側で行う。
 */
final class CommuteService
{
    public const RESULT_CREATED   = 'created';
    public const RESULT_UPDATED   = 'updated';
    public const RESULT_UNCHANGED = 'unchanged';
    public const RESULT_LOCKED    = 'locked';
    public const RESULT_FAILED    = 'failed';

    /** 02・03のフックから呼ぶ入口。打刻や勤怠フラグ更新の本処理を妨げないよう、例外は握りつぶす。 */
    public static function on_day_changed(int $user_id, string $work_date): void
    {
        try {
            self::sync_day($user_id, $work_date);
        } catch (\Throwable $e) {
            // ベストエフォート：交通費側の失敗で打刻・勤怠の処理を止めない。
        }
    }

    /** 02 の打刻イベント（ims_timecard_punched）用。出勤打刻のときだけ動く。 */
    public static function on_punched(int $user_id, string $punch_type, string $work_date): void
    {
        if ($punch_type === 'clock_in') {
            self::on_day_changed($user_id, $work_date);
        }
    }

    /** 1日分を同期する。 */
    public static function sync_day(int $user_id, string $work_date): string
    {
        $year_month = SalaryCycleSettings::year_month_for_date($work_date);
        if (!MonthlySummaryService::is_editable($user_id, $year_month)) {
            return self::RESULT_LOCKED;
        }

        $daily        = DailyAttendanceRepository::find($user_id, $work_date);
        $flag         = $daily !== null ? (string) $daily['attendance_flag'] : 'none';
        $has_clock_in = false;
        foreach (TimecardRepository::logs_for_date($user_id, $work_date) as $log) {
            if ($log['punch_type'] === 'clock_in') {
                $has_clock_in = true;
                break;
            }
        }

        $decision = CommutePlanner::decide($flag, $has_clock_in);
        $mode     = CalcSettings::rounding_mode();
        $existing = ExpenseRepository::find_commute($user_id, $work_date);

        if ($existing !== null) {
            $update = CommutePlanner::reconcile($existing, $decision, $mode);
            if ($update === null) {
                return self::RESULT_UNCHANGED;
            }
            return ExpenseRepository::update((int) $existing['id'], $user_id, $update) ? self::RESULT_UPDATED : self::RESULT_FAILED;
        }

        $price = CommutePlanner::resolve_unit_price(UserRepository::get_travel_unit_price($user_id), GasolinePriceService::current_price());
        $row   = CommutePlanner::new_record($user_id, $work_date, $decision, UserRepository::get_commute_one_way_km($user_id), $price, $mode);
        if ($row === null) {
            return self::RESULT_UNCHANGED;
        }
        return ExpenseRepository::insert($row) ? self::RESULT_CREATED : self::RESULT_FAILED;
    }
}
