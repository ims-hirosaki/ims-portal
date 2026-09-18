<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 03_attendance_management（勤怠管理）モジュールのエントリポイント。
 *
 * 3a時点のスコープ：給与計算サイクル設定・事業マスタ（引き継ぎ書_phase3a.md §8.1）。
 * 手当マスタは01_user_managementモジュールが先行実装済みのため、ここでは扱わない
 * （Schema.php 冒頭コメント参照）。
 * 3bで日次勤怠集計（実労働時間・残業・深夜労働の算出）を追加した。
 * 3cで勤怠フラグ（有給・時間休等）が労働時間へ与える影響を組み込んだ
 * （AttendanceFlagCalculator・AttendanceFlagService）。
 * 3dで事業別時間割当て（wp_project_hours・4条件バリデーション）を追加した
 * （ProjectHourCalculator・ProjectHourRepository・ProjectHourService）。
 * 3c・3dのサービス群は書き込みAPIがまだ無く、次スライスで
 * スタッフ向け月次勤務表画面（3e）から呼び出す想定。
 * 3eでスタッフ向け月次勤務表グリッド `/portal/attendance/` を追加した（表示のみ）。
 * 3e-2で書き込み系（勤怠フラグの変更・事業別時間割当ての保存）を、
 * Module\Attendance\RestController（REST API）経由で追加した。
 * 3e-2以降の修正で打刻の丸め設定（TimeRoundingSettings）を追加した。要件定義書には無い
 * 追加仕様（ユーザー確認済み）で、出退勤・休憩を丸めた時刻を勤怠管理の基準にする
 * （詳細は WorkTimeCalculator 冒頭コメント参照）。
 * 続けて、月次勤務表グリッドを給与計算サイクル設定（締め日）連動の対象期間に対応させた
 * （PayPeriodCalculator。要件定義書には無い追加仕様。ユーザー確認済み）。
 * 3f-1で月次締め・提出フロー（wp_monthly_summary・MonthlySummaryService::submit()）を
 * 追加した。3f-2でチェック者承認・差し戻し（check_approve/check_reject）、
 * 3f-3で最終承認・差し戻し（final_approve/final_reject）を追加し、
 * Module\Timecard\MonthlyClosing::is_locked() を実データ（confirmed ステータス）に
 * 接続した。3f-1〜3f-3時点では画面・APIはまだ無く、3f-4（画面）でまとめて接続する想定。
 *
 * core を改修せず、フックで自己登録する（08 §6 準拠）：
 * ・ims_register_schema … wp_businesses / wp_daily_attendance / wp_project_hours /
 *   wp_monthly_summary のDDL寄与
 * ・ims_seed_initial_data … 事業マスタの初期データ（本社業務）投入（べき等）
 * ・ims_timecard_clocked_out … 02モジュールの退勤打刻完了時、日次勤怠集計を再計算する（3b・3c）
 * ・ims_timecard_punch_corrected … 02モジュールの打刻修正完了時、日次勤怠集計を再計算する
 *   （実機確認で発見：修正しても再計算されず古い値が残るバグの修正）
 * ・ims_portal_register_page … /portal/attendance/ の登録（3e）
 * ・rest_api_init … 勤怠フラグ・事業別時間割当ての書き込みAPI登録（3e-2）
 *
 * ims-portal.php の boot() から Bootstrap::init() を呼ぶ。
 */
final class Bootstrap
{
    public static function init(): void
    {
        // DBスキーマの寄与（Installer が収集して dbDelta する）
        add_filter('ims_register_schema', [Schema::class, 'contribute']);

        // 初期データ投入（有効化時。既に存在すれば何もしない＝べき等）
        add_action('ims_seed_initial_data', [self::class, 'seed_default_business']);

        // 02モジュールの退勤打刻完了時・打刻修正完了時に日次勤怠集計を再計算する（3b・3c・§6.2）。
        // is_admin() の外で登録する（打刻・修正はフロント・REST API文脈で発生するため）。
        // どちらのフックも (user_id, work_date, ...) の順で渡るため同じコールバックでよい。
        add_action('ims_timecard_clocked_out', [DailyAttendanceService::class, 'recalculate'], 10, 2);
        add_action('ims_timecard_punch_corrected', [DailyAttendanceService::class, 'recalculate'], 10, 2);

        // フロント：月次勤務表グリッド（3e）。is_admin() の外で登録する（ポータル画面のため）。
        AttendanceGridPage::init();

        // 書き込みAPI（3e-2）。rest_api_init は管理画面文脈でも走るため is_admin() の外で登録する。
        RestController::init();

        // 管理画面
        if (is_admin()) {
            AdminBusinessesPage::init();           // 社員管理 > 事業マスタ
            AdminSalaryCycleSettingsPage::init();  // ポータル設定 > 給与計算サイクル設定
            AdminTimeRoundingSettingsPage::init(); // ポータル設定 > 打刻丸め設定
        }
    }

    /**
     * 事業マスタの初期データ（本社業務）を1件投入する
     * （§2.3「初期設定は「本社業務」相当の事業に設定する」）。
     * 既に1件でも登録があればスキップする（再有効化でも重複しない）。
     */
    public static function seed_default_business(): void
    {
        global $wpdb;
        $table = Schema::businesses_table();

        $existing = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($existing > 0) {
            return;
        }

        $wpdb->insert($table, [
            'business_code'             => 'honsha',
            'business_name'             => '本社業務',
            'color_code'                => '#1E3A5F',
            'client_name'               => null,
            'is_default_for_paid_leave' => 1,
            'is_active'                 => 1,
            'sort_order'                => 1,
        ]);
    }
}
