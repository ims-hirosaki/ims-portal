<?php

declare(strict_types=1);

namespace IMS\Module\User\Auth;

use IMS\Support\Crypto;
use IMS\Support\Google\OAuthClient;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google アカウントログイン（OAuth 2.0）の業務オーケストレーション
 * （01_user_management.md §3.1 ①）。
 *
 * フロー：
 *   1. /portal/login/?ims_oauth=start → 同意画面へリダイレクト（state発行）
 *   2. Google → redirect_uri（= /portal/login/）に code & state で戻る
 *   3. state検証 → コード交換 → userinfo取得
 *   4. メールドメイン検証（@ims-hirosaki.com）
 *   5. user_email で WordPress ユーザーを突合
 *   6. auth_type が google_sso であることを検証（違えば拒否）
 *   7. 退職者でないことを検証 → wp_set_auth_cookie でセッション発行 → /portal/ へ
 */
final class GoogleOAuth
{
    public const OPTION = 'ims_google_oauth';
    private const STATE_TRANSIENT_PREFIX = 'ims_oauth_state_';

    /**
     * 設定を取得（client_secret は復号する）。
     * @return array{enabled:bool, client_id:string, client_secret:string, allowed_domain:string}
     */
    public static function config(): array
    {
        $opt = get_option(self::OPTION, []);
        return [
            'enabled'        => !empty($opt['enabled']),
            'client_id'      => (string) ($opt['client_id'] ?? ''),
            'client_secret'  => Crypto::decrypt((string) ($opt['client_secret_enc'] ?? '')),
            'allowed_domain' => (string) ($opt['allowed_domain'] ?? 'ims-hirosaki.com'),
        ];
    }

    public static function is_configured(): bool
    {
        $c = self::config();
        return $c['enabled'] && $c['client_id'] !== '' && $c['client_secret'] !== '';
    }

    /** 同意画面リダイレクトURLとトークン交換で共通に使う redirect_uri */
    public static function redirect_uri(): string
    {
        return home_url('/portal/login/');
    }

    public static function start_url(): string
    {
        return add_query_arg('ims_oauth', 'start', home_url('/portal/login/'));
    }

    // ── ①→② 起点：同意画面へ ────────────────────────────

    public static function start(): void
    {
        if (!self::is_configured()) {
            self::fail('未設定です。管理者にお問い合わせください。');
        }
        $config = self::config();

        $state = wp_generate_password(32, false);
        set_transient(self::STATE_TRANSIENT_PREFIX . $state, 1, 300); // 5分有効

        $url = OAuthClient::build_auth_url(
            $config['client_id'],
            self::redirect_uri(),
            $state,
            $config['allowed_domain']
        );
        wp_redirect($url); // 外部（Google）へのリダイレクトのため wp_safe_redirect は使わない
        exit;
    }

    // ── ②→⑦ コールバック処理 ───────────────────────────

    public static function handle_callback(): void
    {
        $code  = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';

        // Google 側でエラー（同意拒否等）
        if (isset($_GET['error'])) {
            self::fail('Google認証がキャンセルされました。');
        }
        // state 検証（CSRF対策）
        if ($state === '' || !get_transient(self::STATE_TRANSIENT_PREFIX . $state)) {
            self::fail('認証セッションが無効です。もう一度お試しください。');
        }
        delete_transient(self::STATE_TRANSIENT_PREFIX . $state);

        if ($code === '') {
            self::fail('認可コードが取得できませんでした。');
        }

        $config = self::config();

        // コード → トークン
        $token = OAuthClient::exchange_code($config['client_id'], $config['client_secret'], $code, self::redirect_uri());
        if (is_wp_error($token)) {
            self::fail('トークンの取得に失敗しました。');
        }

        // トークン → ユーザー情報
        $info = OAuthClient::fetch_userinfo((string) $token['access_token']);
        if (is_wp_error($info)) {
            self::fail('ユーザー情報の取得に失敗しました。');
        }

        $email    = strtolower(sanitize_email((string) ($info['email'] ?? '')));
        $verified = !empty($info['email_verified']);

        if ($email === '' || !$verified) {
            self::fail('メールアドレスが確認できませんでした。');
        }

        // ドメイン制限（サーバーサイド検証）
        $domain = '@' . strtolower($config['allowed_domain']);
        if (!str_ends_with($email, $domain)) {
            self::fail('許可されていないドメインのアカウントです。');
        }

        // WordPress ユーザー突合
        $user = get_user_by('email', $email);
        if (!$user) {
            self::fail('このアカウントに対応する社員が登録されていません。');
        }

        // auth_type 検証
        if (get_user_meta($user->ID, 'auth_type', true) !== 'google_sso') {
            self::fail('このアカウントは Google ログインの対象ではありません。ID・パスワードでログインしてください。');
        }

        // 退職者拒否
        if (get_user_meta($user->ID, 'employment_status', true) === '退職') {
            self::fail('このアカウントは無効化されています。');
        }

        // セッション発行
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);
        do_action('wp_login', $user->user_login, $user);

        wp_safe_redirect(home_url('/portal/'));
        exit;
    }

    private static function fail(string $message): void
    {
        wp_safe_redirect(add_query_arg('auth_error', rawurlencode($message), home_url('/portal/login/')));
        exit;
    }
}
