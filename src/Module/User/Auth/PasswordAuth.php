<?php

declare(strict_types=1);

namespace IMS\Module\User\Auth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ID・パスワード認証（01_user_management.md §3.1 ②）。
 *
 * ・WordPress 標準のパスワード認証を利用する。
 * ・auth_type = google_sso のユーザーがパスワードでログインすることを拒否する
 *   （認証方式の取り違えを防ぐ。wp-login.php 経由も含めて共通で効く）。
 * ・ポータルのログインフォームからの送信を処理する。
 */
final class PasswordAuth
{
    public static function init(): void
    {
        // wp_authenticate_username_password（優先度20）の後にauth_typeで最終判定する
        add_filter('authenticate', [self::class, 'enforce_auth_type'], 30, 3);
    }

    /**
     * @param \WP_User|\WP_Error|null $user
     * @return \WP_User|\WP_Error|null
     */
    public static function enforce_auth_type($user, string $username, string $password)
    {
        if ($user instanceof \WP_User) {
            if (get_user_meta($user->ID, 'auth_type', true) === 'google_sso') {
                return new \WP_Error(
                    'ims_google_only',
                    '<strong>エラー</strong>：このアカウントは Google アカウントでログインしてください。'
                );
            }
            if (get_user_meta($user->ID, 'employment_status', true) === '退職') {
                return new \WP_Error('ims_retired', '<strong>エラー</strong>：このアカウントは無効化されています。');
            }
        }
        return $user;
    }

    /**
     * ポータルのログインフォーム（POST）を処理する。LoginPage::render から呼ばれる。
     */
    public static function handle_login(): void
    {
        if (!isset($_POST['ims_login_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ims_login_nonce'])), 'ims_password_login')) {
            self::fail('セッションが無効です。もう一度お試しください。');
        }

        $email    = sanitize_email(wp_unslash($_POST['ims_login_email'] ?? ''));
        $password = (string) ($_POST['ims_login_password'] ?? '');
        $remember = !empty($_POST['ims_login_remember']);

        if ($email === '' || $password === '') {
            self::fail('メールアドレスとパスワードを入力してください。');
        }

        $user = wp_signon([
            'user_login'    => $email,
            'user_password' => $password,
            'remember'      => $remember,
        ], is_ssl());

        if (is_wp_error($user)) {
            self::fail('メールアドレスまたはパスワードが正しくありません。');
        }

        wp_safe_redirect(home_url('/portal/'));
        exit;
    }

    private static function fail(string $message): void
    {
        wp_safe_redirect(add_query_arg('auth_error', rawurlencode($message), home_url('/portal/login/')));
        exit;
    }
}
