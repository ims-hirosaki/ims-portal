<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * wp-admin の親メニュー「勤怠管理」「共通マスタ」（00_portal.md §4.4。ユーザー確認済み）。
 *
 * どちらも、親メニュー自体を最初のサブメニューの画面にする（WordPress の定石）。
 * 親メニューのスラッグ＝最初の画面のスラッグなので、従来の画面URL（admin.php?page=…）は変わらない。
 * ・勤怠管理   … 月次提出状況（AdminMonthlySubmissionsPage）／勤務表の確認／打刻ログ照会（02）
 * ・共通マスタ … 事業マスタ（AdminBusinessesPage）
 *
 * 親メニューの権限は、中の画面のうちもっとも低い権限に合わせる（見えない画面は WordPress が自動で隠す）。
 */
final class AdminMenu
{
    /** 「勤怠管理」の親スラッグ（＝月次提出状況の画面）。 */
    public const ATTENDANCE_PARENT = 'ims-monthly-submissions';

    /** 「共通マスタ」の親スラッグ（＝事業マスタの画面）。 */
    public const MASTERS_PARENT = 'ims-businesses';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu'], 9); // サブメニューより先に登録する
    }

    public static function register_menu(): void
    {
        add_menu_page(
            '勤怠管理',
            '勤怠管理',
            'ims_approve',
            self::ATTENDANCE_PARENT,
            [AdminMonthlySubmissionsPage::class, 'render'],
            'dashicons-calendar-alt',
            26.3 // 社員管理（26）とポータル設定（27）の間
        );

        add_menu_page(
            '共通マスタ',
            '共通マスタ',
            'ims_manage_masters',
            self::MASTERS_PARENT,
            [AdminBusinessesPage::class, 'render'],
            'dashicons-database',
            26.6
        );
    }
}
