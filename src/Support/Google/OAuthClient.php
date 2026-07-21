<?php

declare(strict_types=1);

namespace IMS\Support\Google;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google OAuth 2.0 の低レベルクライアント（06_google_workspace_integration.md Tier1）。
 *
 * ・認証URLの組み立て
 * ・認可コード → アクセストークンの交換
 * ・アクセストークン → ユーザー情報（email / email_verified / hd）の取得
 *
 * HTTP通信は WordPress コアの wp_remote_* を使用する（08 §3）。
 * 業務ロジック（ユーザー突合・auth_type検証・セッション発行）は上位の
 * Module\User\Auth\GoogleOAuth が担う。
 */
final class OAuthClient
{
    private const AUTH_ENDPOINT     = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT    = 'https://oauth2.googleapis.com/token';
    private const USERINFO_ENDPOINT = 'https://openidconnect.googleapis.com/v1/userinfo';

    /**
     * 同意画面へのリダイレクトURLを組み立てる。
     * $hd を渡すと Google 側でそのドメインのアカウントを優先表示する（最終検証はサーバー側で行う）。
     */
    public static function build_auth_url(string $client_id, string $redirect_uri, string $state, string $hd = ''): string
    {
        $params = [
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
            'prompt'        => 'select_account',
        ];
        if ($hd !== '') {
            $params['hd'] = $hd;
        }
        return self::AUTH_ENDPOINT . '?' . http_build_query($params);
    }

    /**
     * 認可コードをアクセストークンに交換する。
     * @return array<string, mixed>|\WP_Error
     */
    public static function exchange_code(string $client_id, string $client_secret, string $code, string $redirect_uri)
    {
        $response = wp_remote_post(self::TOKEN_ENDPOINT, [
            'timeout' => 15,
            'body'    => [
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => $redirect_uri,
                'grant_type'    => 'authorization_code',
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['access_token'])) {
            return new \WP_Error('oauth_token_error', 'アクセストークンの取得に失敗しました。');
        }
        return $body;
    }

    /**
     * アクセストークンでユーザー情報を取得する。
     * @return array<string, mixed>|\WP_Error 期待キー：email, email_verified, hd, name
     */
    public static function fetch_userinfo(string $access_token)
    {
        $response = wp_remote_get(self::USERINFO_ENDPOINT, [
            'timeout' => 15,
            'headers' => ['Authorization' => 'Bearer ' . $access_token],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['email'])) {
            return new \WP_Error('oauth_userinfo_error', 'ユーザー情報の取得に失敗しました。');
        }
        return $body;
    }
}
