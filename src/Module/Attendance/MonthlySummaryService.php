<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

use IMS\Support\Capabilities;
use IMS\Support\UserRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 月次締め・提出・承認フローのサービス（03_attendance_management.md §3.4）。
 * 3f-1で提出、3f-2でチェック者承認・差し戻しを追加した。
 *
 * 権限判定・自己承認禁止・ステータス遷移の妥当性チェックはすべてここに集約し、
 * MonthlySummaryRepository は保存だけを行う（AttendanceFlagService等と同方針）。
 *
 * 操作権限マトリクス（§3.4）：
 *   提出             … 本人可／hr_admin・administratorは全員分可
 *   チェック者承認・差し戻し … 担当者（first_approver_id）のみ可／hr_admin・administratorは全員分可
 *   最終承認・差し戻し       … hr_admin・administratorのみ可
 * 自己承認の禁止：チェック者・最終管理者は自身が提出者である月度を承認できない
 *   （01モジュールの first_approver_id 自己設定禁止制約と連動。§3.4）。
 *
 * 3f-3で最終承認・差し戻しを追加した。これにより Module\Timecard\MonthlyClosing が
 * 締め後ロックの判定に使う confirmed ステータスが実際に立つようになる。
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

    /**
     * 指定ユーザー・年月の勤怠グリッド（勤怠フラグ・事業別時間割当て）を
     * 編集できる状態か（§4.1「ステータスが submitted 以降はグリッド全セルを
     * 読み取り専用にする」）。未提出（該当データなし）・差し戻し中は編集可。
     */
    public static function is_editable(int $user_id, string $year_month): bool
    {
        $existing = MonthlySummaryRepository::find($user_id, $year_month);
        $status   = $existing !== null ? (string) $existing['status'] : MonthlySummaryCalculator::DRAFT;
        return in_array($status, MonthlySummaryCalculator::SUBMITTABLE_FROM, true);
    }

    /** 指定ユーザー・年月の現在のステータス（未提出はdraft扱い）。 */
    public static function current_status(int $user_id, string $year_month): string
    {
        $existing = MonthlySummaryRepository::find($user_id, $year_month);
        return $existing !== null ? (string) $existing['status'] : MonthlySummaryCalculator::DRAFT;
    }

    /**
     * 画面表示用の状態まとめ（グリッド画面のステータスバナー用。3f-4b）。
     *
     * @return array{status:string, label:string, is_editable:bool, rejection_comment:?string}
     */
    public static function status_summary(int $user_id, string $year_month): array
    {
        $existing = MonthlySummaryRepository::find($user_id, $year_month);
        $status   = $existing !== null ? (string) $existing['status'] : MonthlySummaryCalculator::DRAFT;

        return [
            'status'            => $status,
            'label'             => MonthlySummaryCalculator::label($status),
            'is_editable'       => in_array($status, MonthlySummaryCalculator::SUBMITTABLE_FROM, true),
            'rejection_comment' => $existing['rejection_comment'] ?? null,
        ];
    }

    /**
     * チェック者承認（submitted → checked）。
     *
     * @return true|\WP_Error
     */
    public static function check_approve(int $actor_id, int $target_user_id, string $year_month)
    {
        return self::run_check_transition($actor_id, $target_user_id, $year_month, static function (int $id) use ($actor_id): bool {
            return MonthlySummaryRepository::check_approve($id, $actor_id);
        });
    }

    /**
     * チェック者差し戻し（submitted → rejected_by_checker）。差し戻し理由は必須（§3.4）。
     *
     * @return true|\WP_Error
     */
    public static function check_reject(int $actor_id, int $target_user_id, string $year_month, string $comment)
    {
        $comment = trim($comment);
        if ($comment === '') {
            return new \WP_Error('validation', '差し戻し理由を入力してください。');
        }

        return self::run_check_transition($actor_id, $target_user_id, $year_month, static function (int $id) use ($actor_id, $comment): bool {
            return MonthlySummaryRepository::check_reject($id, $actor_id, $comment);
        });
    }

    /**
     * チェック者承認・差し戻し共通の前処理（権限・自己承認禁止・ステータス検証）を行い、
     * 検証を通過したら $save コールバック（Repository呼び出し）を実行する。
     *
     * @param callable(int): bool $save
     * @return true|\WP_Error
     */
    private static function run_check_transition(int $actor_id, int $target_user_id, string $year_month, callable $save)
    {
        if (!MonthlySummaryCalculator::is_valid_year_month($year_month)) {
            return new \WP_Error('validation', '対象年月の形式が正しくありません。');
        }
        if ($actor_id === $target_user_id) {
            return new \WP_Error('forbidden_self', '自身が提出者である月次データを承認・差し戻しすることはできません。');
        }
        if (!self::can_check_approve($actor_id, $target_user_id)) {
            return new \WP_Error('forbidden', 'この月度をチェック承認する権限がありません。');
        }

        $existing = MonthlySummaryRepository::find($target_user_id, $year_month);
        if ($existing === null || (string) $existing['status'] !== MonthlySummaryCalculator::SUBMITTED) {
            return new \WP_Error('invalid_status', 'この月度は現在チェック承認できる状態ではありません。');
        }

        if (!$save((int) $existing['id'])) {
            return new \WP_Error('db_error', '保存に失敗しました。');
        }

        return true;
    }

    /**
     * チェック者承認・差し戻しの役割上の権限があるか（自己承認禁止は別途チェックする）。
     * hr_admin・administratorは全員分可。approverは対象者の担当チェック者（first_approver_id）
     * のときのみ可（§3.4）。
     */
    public static function can_check_approve(int $actor_id, int $target_user_id): bool
    {
        if (Capabilities::can_manage_users()) {
            return true;
        }
        if (Capabilities::can_approve()) {
            return UserRepository::get_first_approver_id($target_user_id) === $actor_id;
        }
        return false;
    }

    /**
     * 最終承認（checked → confirmed）。給与条件のスナップショット（snapshot_base_salary等）
     * は3g（月次データスナップショット）で、この遷移に合わせて書き込む想定
     * （3f-3時点ではNULLのまま確定する）。
     *
     * @return true|\WP_Error
     */
    public static function final_approve(int $actor_id, int $target_user_id, string $year_month)
    {
        return self::run_final_transition($actor_id, $target_user_id, $year_month, static function (int $id) use ($actor_id): bool {
            return MonthlySummaryRepository::final_approve($id, $actor_id);
        });
    }

    /**
     * 最終差し戻し（checked → rejected_by_admin）。差し戻し理由は必須（§3.4）。
     * confirmed からの差し戻しはUI上提供しない（§3.5）ため、対象は checked のみ。
     *
     * @return true|\WP_Error
     */
    public static function final_reject(int $actor_id, int $target_user_id, string $year_month, string $comment)
    {
        $comment = trim($comment);
        if ($comment === '') {
            return new \WP_Error('validation', '差し戻し理由を入力してください。');
        }

        return self::run_final_transition($actor_id, $target_user_id, $year_month, static function (int $id) use ($actor_id, $comment): bool {
            return MonthlySummaryRepository::final_reject($id, $actor_id, $comment);
        });
    }

    /**
     * 最終承認・差し戻し共通の前処理（権限・自己承認禁止・ステータス検証）。
     *
     * @param callable(int): bool $save
     * @return true|\WP_Error
     */
    private static function run_final_transition(int $actor_id, int $target_user_id, string $year_month, callable $save)
    {
        if (!MonthlySummaryCalculator::is_valid_year_month($year_month)) {
            return new \WP_Error('validation', '対象年月の形式が正しくありません。');
        }
        if ($actor_id === $target_user_id) {
            return new \WP_Error('forbidden_self', '自身が提出者である月次データを承認・差し戻しすることはできません。');
        }
        if (!self::can_final_approve()) {
            return new \WP_Error('forbidden', 'この月度を最終承認する権限がありません。');
        }

        $existing = MonthlySummaryRepository::find($target_user_id, $year_month);
        if ($existing === null || (string) $existing['status'] !== MonthlySummaryCalculator::CHECKED) {
            return new \WP_Error('invalid_status', 'この月度は現在最終承認できる状態ではありません。');
        }

        if (!$save((int) $existing['id'])) {
            return new \WP_Error('db_error', '保存に失敗しました。');
        }

        return true;
    }

    /**
     * 最終承認・差し戻しの役割上の権限があるか（自己承認禁止は別途チェックする）。
     * hr_admin・administratorのみ可（§3.4。締め処理の実行権限 ims_run_closing を流用する）。
     */
    public static function can_final_approve(): bool
    {
        return Capabilities::can_run_closing();
    }
}
