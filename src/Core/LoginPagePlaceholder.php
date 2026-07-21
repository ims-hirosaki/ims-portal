<?php

declare(strict_types=1);

namespace IMS\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Phase 0 確認用の暫定ログインページ。
 *
 * ⚠ これは 01_user_management モジュールの本実装ではない。
 * Google SSO・二方式認証・ドメイン制限は 01 モジュールで実装する。
 * ここでは Phase 0 の骨格（Router / AuthGuard）が正しく動作することを
 * 確認するための、WordPress標準ログインフォームへのリンクのみを提供する。
 * 01 実装時にこのクラスとファイルは置き換える。
 */
final class LoginPagePlaceholder
{
    public static function init(): void
    {
        add_action('ims_portal_register_page', static function (string $router_class): void {
            $router_class::register('login', self::class);
        });
    }

    public static function render(): void
    {
        if (is_user_logged_in()) {
            wp_safe_redirect(home_url('/portal/'));
            exit;
        }

        Layout::render_header('ログイン');

        $reason = isset($_GET['reason']) ? sanitize_text_field(wp_unslash($_GET['reason'])) : '';
        ?>
        <div class="card" style="max-width:420px;margin:40px auto;">
            <div class="card-header"><h2><?php esc_html_e('ログイン（Phase 0 暫定）', 'ims-portal'); ?></h2></div>
            <div class="card-body">
                <?php if ($reason === 'retired') : ?>
                    <p class="hint" style="color:var(--vermillion,#B8472F);">
                        <?php esc_html_e('退職済みのアカウントのため、アクセスできません。', 'ims-portal'); ?>
                    </p>
                <?php endif; ?>
                <p class="hint">
                    <?php esc_html_e('Google SSO・パスワード認証の統合UIは 01_user_management モジュールで実装予定です。', 'ims-portal'); ?>
                </p>
                <p>
                    <a class="btn btn-primary" href="<?php echo esc_url(wp_login_url(home_url('/portal/'))); ?>">
                        <?php esc_html_e('WordPress標準ログインへ', 'ims-portal'); ?>
                    </a>
                </p>
            </div>
        </div>
        <?php

        Layout::render_footer();
    }
}
