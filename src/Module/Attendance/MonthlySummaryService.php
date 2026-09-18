<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

use IMS\Support\Capabilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 月次締め・提出・承認フローのサービス（03_attendance_management.md §3.4）。3f-1で提出を追加。
 *
 * 権限判定・自己承認禁止・ステータス遷移の妥当性チェックはすべてここに集約し、
 * MonthlySummaryRepository は保存だけを行う（AttendanceFlagService等と同方針）。
 *
 * 操作権限マトリクス（§3.4）：
 *   提出             … 本人可／hr_admin・administratorは全員分可
 *   チェック者承認・差し戻し … 担当者（first_approver_id）のみ可／hr_admin・administratorは全員分可
 *   最終承認・差し戻し       … hr_admin・administratorのみ可
 * 自己承認の禁止：チェック者・最終管理者は自身が提出者である月度を承認できない。
 *
 * 3f-1時点のスコープ：提出のみ。チェック者承認・差し戻しは3f-2、最終承認・差し戻しは3f-3で追加する。
 */
final class MonthlySummaryService
{
    /**
     * 指定ユーザー・年月の月次勤務表を提出する（新規提出・再提出とも）。
     *
     * 提出時に、その月度の事業別時間割当てが未入力の日が無いかをチェックする（§3.3）。
     * 1日でも未入力があれば提出をブロックし、該当日の一覧を返す。
     *
     * @return true|\WP_Error
     */
    public static function submit(int $actor_id, int $target_user_id, string $year_month)
    {
        if (!MonthlySummaryCalculator::is_valid_year_month($year_month)) {
            return new \WP_Error('validation', '対象年月の形式が正しくありません。');
        }
        if (!self::can_submit($actor_id, $target_user_id)) {
            return new \WP_Error('forbidden', 'この月度を提出する権限がありません。');
        }

        $existing = MonthlySummaryRepository::find($target_user_id, $year_month);
        $current_status = $existing !== null ? (string) $existing['status'] : MonthlySummaryCalculator::DRAFT;
        if (!in_array($current_status, MonthlySummaryCalculator::SUBMITTABLE_FROM, true)) {
            return new \WP_Error('invalid_status', 'この月度は現在提出できる状態ではありません。');
        }

        $grid = AttendanceGridService::month_data($target_user_id, $year_month);

        $unallocated_dates = [];
        foreach ($grid['days'] as $date => $day) {
            if ($day['needs_allocation']) {
                $unallocated_dates[] = $date;
            }
        }
        if ($unallocated_dates !== []) {
            return new \WP_Error(
                'unallocated_days',
                '事業別時間が未入力の日があります（' . count($unallocated_dates) . '日）：' . implode('、', $unallocated_dates),
                $unallocated_dates
            );
        }

        $totals = MonthlySummaryCalculator::aggregate($grid['days']);

        if (!MonthlySummaryRepository::submit($target_user_id, $year_month, $totals)) {
            return new \WP_Error('db_error', '提出の保存に失敗しました。');
        }

        return true;
    }

    /** 提出できるか（本人、またはhr_admin以上）。 */
    public static function can_submit(int $actor_id, int $target_user_id): bool
    {
        return $actor_id === $target_user_id || Capabilities::can_manage_users();
    }
}
