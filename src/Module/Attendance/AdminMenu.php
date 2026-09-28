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
 *
 * 3p-2：approver は wp-admin に入れない（00 §4.3）が、チェック承認のために「月次提出状況」「勤務表の確認」と、
 * そこから行うチェック承認・差し戻し・印刷の処理だけを ims_wp_admin_limited_access フィルタで許可する
 * （00 §4.3 の例外。ユーザー確認済み）。最終承認・CSV・取り消しは、各処理の権限チェックで従来どおり拒否される。
 */
final class AdminMenu
{
    /** 「勤怠管理」の親スラッグ（＝月次提出状況の画面）。 */
    public const ATTENDANCE_PARENT = 'ims-monthly-submissions';

    /** 「共通マスタ」の親スラッグ（＝事業マスタの画面）。 */
    public const MASTERS_PARENT = 'ims-businesses';

    /** approver に許可する admin-post.php の処理。 */
    private const APPROVER_ACTIONS = [
        'ims_monthly_check_approve',
        'ims_monthly_check_reject',
        AttendancePrintPage::ACTION,
    ];

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu'], 9); // サブメニューより先に登録する
        add_filter('ims_wp_admin_limited_access', [self::class, 'limited_access'], 10, 2);
    }

    /**
     * @param array{pages: array<int, string>, actions: array<int, string>} $allow
     * @return array{pages: array<int, string>, actions: array<int, string>}
     */
    public static function limited_access(array $allow, \WP_User $user): array
    {
        if (!$user->has_cap('ims_approve')) {
            return $allow;
        }
        $allow['pages']   = array_merge($allow['pages'] ?? [], [self::ATTENDANCE_PARENT, AdminAttendanceGridPage::MENU_SLUG]);
        $allow['actions'] = array_merge($allow['actions'] ?? [], self::APPROVER_ACTIONS);
        return $allow;
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
