<?php

declare(strict_types=1);

namespace IMS\Module\User\Auth;

use IMS\Core\Layout;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `/portal/login/` の本番ログイン画面（01_user_management.md §3.1）。
 * Phase 0 の LoginPagePlaceholder を置き換える。
 *
 * ・二方式（Google アカウント / ID・パスワード）を1画面に提示する。
 * ・OAuth の起点（?ims_oauth=start）とコールバック（?code=&state=）、
 *   およびパスワードフォームのPOSTを、描画前に処理する。
 */
final class LoginPage
{
    public static function init(): void
    {
        add_action('ims_portal_register_page', static function (string $router_class): void {
            $router_class::register('login', self::class);
        });
    }

    public static function render(): void
    {
        // ── 描画前のアクション処理（リダイレクトはここで完結させる） ──

        // OAuth コールバック（Google からの復帰）
        if (isset($_GET['code']) || isset($_GET['error'])) {
            GoogleOAuth::handle_callback();
            return;
        }
        // OAuth 起点
        if (($_GET['ims_oauth'] ?? '') === 'start') {
            GoogleOAuth::start();
            return;
        }
        // パスワードログインのPOST
        if (($_POST['ims_action'] ?? '') === 'password_login') {
            PasswordAuth::handle_login();
            return;
        }
        // 既にログイン済みならポータルへ
        if (is_user_logged_in()) {
            wp_safe_redirect(home_url('/portal/'));
            exit;
        }

        // ── 画面描画 ──
        $error = isset($_GET['auth_error']) ? sanitize_text_field(wp_unslash($_GET['auth_error'])) : '';
        $google_ready = GoogleOAuth::is_configured();

        Layout::render_header('ログイン');
        ?>
        <div class="ims-login">
            <div class="ims-login-card">
                <div class="ims-login-brand">
                    <div class="ims-login-logo">IMS Hirosaki</div>
                    <p class="ims-login-sub">社内業務ポータル</p>
                </div>

                <?php if ($error !== '') : ?>
                    <div class="ims-login-error"><?php echo esc_html($error); ?></div>
                <?php endif; ?>

                <?php if ($google_ready) : ?>
                    <a class="ims-google-btn" href="<?php echo esc_url(GoogleOAuth::start_url()); ?>">
                        <span class="ims-google-mark">G</span>
                        Google アカウントでログイン
                    </a>
                    <div class="ims-login-divider"><span>または</span></div>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url(home_url('/portal/login/')); ?>" class="ims-login-form">
                    <?php wp_nonce_field('ims_password_login', 'ims_login_nonce'); ?>
                    <input type="hidden" name="ims_action" value="password_login">

                    <label class="ims-field">
                        <span>メールアドレス</span>
                        <input type="email" name="ims_login_email" autocomplete="username" required>
                    </label>
                    <label class="ims-field">
                        <span>パスワード</span>
                        <input type="password" name="ims_login_password" autocomplete="current-password" required>
                    </label>
                    <label class="ims-remember">
                        <input type="checkbox" name="ims_login_remember" value="1"> ログイン状態を保持する
                    </label>

                    <button type="submit" class="btn btn-primary ims-login-submit">ID・パスワードでログイン</button>
                </form>

                <p class="ims-login-foot">
                    <a href="<?php echo esc_url(wp_lostpassword_url()); ?>">パスワードをお忘れの方</a>
                </p>
            </div>
            <p class="ims-login-note">Google アカウントをお持ちの社員は上のボタンから、それ以外の方は ID・パスワードでログインしてください。</p>
        </div>
        <?php
        Layout::render_footer();
    }
}
