<?php

declare(strict_types=1);

namespace IMS\Module\User\Auth;

use IMS\Support\Crypto;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 「ポータル設定 > 認証設定」画面（Google OAuth のクライアント情報を設定）。
 *
 * 権限：ims_manage_system（administrator のみ）。
 * client_secret は Crypto で暗号化して wp_options に保存する（平文で持たない）。
 * リダイレクトURI（Google Cloud Console に登録する値）を画面に表示する。
 */
final class AdminAuthSettingsPage
{
    public const PARENT_SLUG = 'ims-portal-settings';
    private const MENU_SLUG   = 'ims-auth-settings';
    private const CAP         = 'ims_manage_system';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu'], 9);
        add_action('admin_post_ims_save_auth_settings', [self::class, 'handle_save']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function register_menu(): void
    {
        add_menu_page(
            'ポータル設定',
            'ポータル設定',
            self::CAP,
            self::PARENT_SLUG,
            [self::class, 'render'],
            'dashicons-admin-settings',
            27
        );
        add_submenu_page(
            self::PARENT_SLUG,
            '認証設定',
            '認証設定',
            self::CAP,
            self::PARENT_SLUG,
            [self::class, 'render']
        );
    }

    public static function enqueue(string $hook): void
    {
        if (!str_contains($hook, self::PARENT_SLUG)) {
            return;
        }
        wp_enqueue_style('ims-portal-tokens', IMS_PORTAL_URL . 'assets/css/ims-tokens.css', [], IMS_PORTAL_VERSION);
        wp_enqueue_style('ims-admin', IMS_PORTAL_URL . 'assets/css/admin.css', ['ims-portal-tokens'], IMS_PORTAL_VERSION);
    }

    public static function handle_save(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('権限がありません。', 'ims-portal'));
        }
        check_admin_referer('ims_save_auth_settings');

        $existing = get_option(GoogleOAuth::OPTION, []);

        $client_id      = sanitize_text_field(wp_unslash($_POST['client_id'] ?? ''));
        $client_secret  = trim((string) wp_unslash($_POST['client_secret'] ?? ''));
        $allowed_domain = sanitize_text_field(wp_unslash($_POST['allowed_domain'] ?? 'ims-hirosaki.com'));
        $enabled        = !empty($_POST['enabled']);

        // シークレットは入力があった場合のみ更新（空なら既存を維持）
        $secret_enc = $existing['client_secret_enc'] ?? '';
        if ($client_secret !== '') {
            $secret_enc = Crypto::encrypt($client_secret);
        }

        update_option(GoogleOAuth::OPTION, [
            'enabled'           => $enabled,
            'client_id'         => $client_id,
            'client_secret_enc' => $secret_enc,
            'allowed_domain'    => ltrim($allowed_domain, '@'),
        ]);

        wp_safe_redirect(add_query_arg([
            'page'       => self::PARENT_SLUG,
            'ims_notice' => 'saved',
        ], admin_url('admin.php')));
        exit;
    }

    public static function render(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('権限がありません。', 'ims-portal'));
        }

        $opt = get_option(GoogleOAuth::OPTION, []);
        $has_secret = !empty($opt['client_secret_enc']);
        $redirect_uri = GoogleOAuth::redirect_uri();
        ?>
        <div class="wrap ims-admin">
            <h1>認証設定（Google アカウントログイン）</h1>
            <?php if (($_GET['ims_notice'] ?? '') === 'saved') : ?>
                <div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>
            <?php endif; ?>
            <?php if (!Crypto::available()) : ?>
                <div class="notice notice-error"><p>サーバーで OpenSSL が利用できないため、シークレットを安全に保存できません。ホスティング環境をご確認ください。</p></div>
            <?php endif; ?>

            <div class="ims-user-card" style="max-width:760px;">
                <h2>セットアップ手順</h2>
                <p class="description" style="margin:0 0 8px;">
                    Google Cloud Console で OAuth 2.0 クライアントIDを作成し、下記の「承認済みリダイレクトURI」を登録してから、発行された クライアントID / シークレット を入力してください。
                </p>
                <p>
                    <label class="ims-label">承認済みリダイレクトURI（この値を Google 側に登録）</label>
                    <code style="display:inline-block;padding:6px 10px;background:var(--indigo-tint,#EAEEF2);border-radius:6px;">
                        <?php echo esc_html($redirect_uri); ?>
                    </code>
                </p>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ims-user-card" style="max-width:760px;">
                <?php wp_nonce_field('ims_save_auth_settings'); ?>
                <input type="hidden" name="action" value="ims_save_auth_settings">
                <h2>クライアント情報</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="enabled">Google ログインを有効化</label></th>
                        <td><label><input type="checkbox" id="enabled" name="enabled" value="1" <?php checked(!empty($opt['enabled'])); ?>> 有効にする</label></td>
                    </tr>
                    <tr>
                        <th><label for="client_id">クライアントID</label></th>
                        <td><input type="text" id="client_id" name="client_id" class="regular-text"
                                   value="<?php echo esc_attr($opt['client_id'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="client_secret">クライアントシークレット</label></th>
                        <td>
                            <input type="password" id="client_secret" name="client_secret" class="regular-text"
                                   placeholder="<?php echo $has_secret ? '設定済み（変更する場合のみ入力）' : '未設定'; ?>"
                                   autocomplete="new-password">
                            <p class="description">保存時に暗号化されます。空欄のまま保存すると既存の値を維持します。</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="allowed_domain">許可ドメイン</label></th>
                        <td><input type="text" id="allowed_domain" name="allowed_domain" class="regular-text"
                                   value="<?php echo esc_attr($opt['allowed_domain'] ?? 'ims-hirosaki.com'); ?>">
                            <p class="description">このドメインのメールアドレスのみ Google ログインを許可します（@ は不要）。</p></td>
                    </tr>
                </table>
                <p><button type="submit" class="button button-primary">保存する</button></p>
            </form>
        </div>
        <?php
    }
}
