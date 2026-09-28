<?php

declare(strict_types=1);

namespace IMS\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 認証ガード（00_portal.md §4 準拠）。
 *
 * ・未認証ユーザーが /portal/ 配下にアクセス → /portal/login/ へリダイレクト
 * ・employment_status = 退職 のユーザー → アクセス拒否
 * ・general_staff / approver が wp-admin にアクセス → /portal/ へ強制リダイレクト
 *   例外：各モジュールが `ims_wp_admin_limited_access` フィルタで許可した画面・処理だけは通す
 *   （approver のチェック承認用。00 §4.3。判定は AdminAccessPolicy）。
 *
 * 安全設計メモ：
 * 本番サーバーへ直接デプロイする運用のため、このガードのバグが
 * 管理者アカウントの締め出しに直結しないよう、判定は必ず
 * Roles::can_access_wp_admin()（capabilityベース）を経由し、
 * administrator は常に除外する。
 */
final class AuthGuard
{
    private const REDIRECT_PARAM = 'redirect_to';

    public static function init(): void
    {
        // Router::dispatch（template_redirect, 優先度10）より先に判定する
        add_action('template_redirect', [self::class, 'guard_portal_request'], 5);

        // wp-admin 側のブロックは admin_init で行う（init フックだと早すぎてユーザー情報が
        // 確定していない場合があるため、08 §3 の方針通り admin_init を使用）
        add_action('admin_init', [self::class, 'guard_wp_admin_access']);

        // 例外的に wp-admin の一部だけ使えるユーザーには、使えない標準メニュー（ダッシュボード等）を出さない
        add_action('admin_menu', [self::class, 'trim_limited_admin_menu'], 999);

        // ログイン後のデフォルトリダイレクト先を /portal/ にする
        add_filter('login_redirect', [self::class, 'login_redirect'], 10, 3);
    }

    /**
     * /portal/ 配下へのリクエストを検査する。
     */
    public static function guard_portal_request(): void
    {
        if (!Router::is_portal_request()) {
            return;
        }

        // ログイン画面自体は未認証でもアクセスできる必要がある（モジュール実装後、
        // slug 'login' として登録される想定。Phase 0 時点ではプレースホルダ判定のみ）
        if (self::current_slug_is_login()) {
            return;
        }

        if (!is_user_logged_in()) {
            self::redirect_to_login();
            return;
        }

        $user = wp_get_current_user();

        if (self::is_retired($user)) {
            wp_logout();
            self::redirect_to_login(retired: true);
            return;
        }
    }

    /**
     * wp-admin へのアクセスを検査する。general_staff / approver はブロックする。
     * ただし、モジュールが許可した画面・処理（limited_access()）だけは通す。
     */
    public static function guard_wp_admin_access(): void
    {
        // Ajax リクエストは除外する（管理画面UIの非同期処理を壊さないため）
        if (wp_doing_ajax()) {
            return;
        }

        if (!is_user_logged_in()) {
            return; // 未ログインは wp-login.php 側の標準フローに任せる
        }

        $user = wp_get_current_user();

        if (Roles::can_access_wp_admin($user)) {
            return;
        }

        global $pagenow;
        $page   = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash((string) $_REQUEST['action'])) : '';
        if (AdminAccessPolicy::is_allowed((string) $pagenow, $page, $action, self::limited_access($user))) {
            return;
        }

        wp_safe_redirect(home_url('/portal/'));
        exit;
    }

    /**
     * wp-admin に入れないユーザーに、例外として許可する画面・処理（各モジュールが登録する）。
     *
     * @return array{pages: array<int, string>, actions: array<int, string>}
     */
    public static function limited_access(\WP_User $user): array
    {
        $allow = apply_filters('ims_wp_admin_limited_access', ['pages' => [], 'actions' => []], $user);
        return [
            'pages'   => array_values(array_map('strval', (array) ($allow['pages'] ?? []))),
            'actions' => array_values(array_map('strval', (array) ($allow['actions'] ?? []))),
        ];
    }

    /**
     * 例外的に wp-admin の一部だけ使えるユーザーから、使えない標準メニューを外す。
     * 開いても /portal/ に戻されるだけのメニューを出さないため（見た目の整理。アクセス制御は guard_wp_admin_access）。
     */
    public static function trim_limited_admin_menu(): void
    {
        $user = wp_get_current_user();
        if (!$user->exists() || Roles::can_access_wp_admin($user)) {
            return;
        }
        if (!AdminAccessPolicy::has_any(self::limited_access($user))) {
            return;
        }
        remove_menu_page('index.php');   // ダッシュボード
        remove_menu_page('profile.php'); // プロフィール
    }

    public static function login_redirect(string $redirect_to, string $requested_redirect_to, $user): string
    {
        if (!($user instanceof \WP_User)) {
            return $redirect_to;
        }

        if (!Roles::can_access_wp_admin($user)) {
            return home_url('/portal/');
        }

        return $redirect_to;
    }

    private static function redirect_to_login(bool $retired = false): void
    {
        $current_url = home_url(add_query_arg([], $_SERVER['REQUEST_URI'] ?? '/portal/'));
        $args = [self::REDIRECT_PARAM => rawurlencode($current_url)];
        if ($retired) {
            $args['reason'] = 'retired';
        }
        wp_safe_redirect(add_query_arg($args, home_url('/portal/login/')));
        exit;
    }

    /**
     * Phase 0 時点のプレースホルダ。01_user_management モジュール実装後、
     * Router に 'login' slug が登録されたら、その定数を参照する形に更新する。
     */
    private static function current_slug_is_login(): bool
    {
        return get_query_var('ims_portal_page') === 'login';
    }

    private static function is_retired(\WP_User $user): bool
    {
        $status = get_user_meta($user->ID, 'employment_status', true);
        return $status === '退職';
    }
}
