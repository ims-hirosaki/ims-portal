<?php

declare(strict_types=1);

namespace IMS\Module\Timecard;

use IMS\Support\Google\ChatNotifier;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 02_time_tracking（打刻管理）モジュールのエントリポイント。
 *
 * core を改修せず、フックで自己登録する（08 §6・§10.3 準拠）：
 * ・ims_register_schema … 独自テーブルDDLの寄与（attendance_logs / attendance_corrections）
 * ・ims_portal_register_page … /portal/timecard/ の登録（2b）
 * ・ims_portal_register_tile … ダッシュボードタイル（2g）
 * ・ims_portal_summary_contribute … サマリー/バッジへの寄与（2g）
 * ・ims_portal_dashboard_top … ダッシュボードのステータスカード（2g）
 *
 * ims-portal.php の boot() から Bootstrap::init() を呼ぶ。
 */
final class Bootstrap
{
    public static function init(): void
    {
        // DBスキーマの寄与（Installer が収集して dbDelta する）
        add_filter('ims_register_schema', [Schema::class, 'contribute']);

        // フロント：打刻コンソール（2b）
        TimecardPage::init();

        // 打刻API（2c）。rest_api_init は管理画面文脈でも走るため is_admin() の外で登録する。
        RestController::init();

        // Google Chat 通知のリトライ受け口。
        // WP-Cron は管理画面以外の文脈でも走るため、is_admin() の外で登録する。
        ChatNotifier::init();

        // ダッシュボード連携（タイル・summary・ステータスカード）(2g)
        DashboardIntegration::init();

        // 管理画面
        if (is_admin()) {
            AdminSettingsPage::init();   // ポータル設定 > 打刻システム設定 (2a)
            AdminLogSearchPage::init();  // 社員管理 > 打刻ログ照会 (2f)
        }
    }
}
