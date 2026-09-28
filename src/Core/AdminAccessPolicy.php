<?php

declare(strict_types=1);

namespace IMS\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * wp-admin に入れないロール（general_staff / approver）に対して、例外的に開いてよい画面かどうかを
 * 判定する純粋ロジック（00_portal.md §4.3 の例外。ユーザー確認済み）。DB/WordPressに触れない。
 *
 * 許可の中身はコアでは決めない。各モジュールが `ims_wp_admin_limited_access` フィルタで
 * ['pages' => [admin.php?page= のスラッグ…], 'actions' => [admin-post.php の action…]] を返し、
 * AuthGuard がそれをここに渡して判定する（コアはモジュールの画面を知らない）。
 */
final class AdminAccessPolicy
{
    /**
     * @param string $script  リクエストされた wp-admin のファイル名（$pagenow。例：'admin.php'）
     * @param string $page    admin.php の ?page=
     * @param string $action  admin-post.php の action
     * @param array{pages?: array<int, string>, actions?: array<int, string>} $allow
     */
    public static function is_allowed(string $script, string $page, string $action, array $allow): bool
    {
        if ($script === 'admin.php') {
            return $page !== '' && in_array($page, $allow['pages'] ?? [], true);
        }
        if ($script === 'admin-post.php') {
            return $action !== '' && in_array($action, $allow['actions'] ?? [], true);
        }
        return false;
    }

    /** 許可リストに1件でも画面があるか（メニューの整理をするかどうかの判定に使う）。 */
    public static function has_any(array $allow): bool
    {
        return ($allow['pages'] ?? []) !== [];
    }
}
