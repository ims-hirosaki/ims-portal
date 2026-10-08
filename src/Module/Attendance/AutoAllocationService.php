<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

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
 * 呼び出し側（AttendanceFlagService）で月次提出後の編集ロックを確認済みの前提。
 * 自動割り当ては補助機能のため、失敗しても勤怠フラグの保存自体は失敗扱いにしない（戻り値は参考）。
 */
final class AutoAllocationService
{
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
}
