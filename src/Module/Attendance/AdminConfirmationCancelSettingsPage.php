<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

use IMS\Module\User\Auth\AdminAuthSettingsPage;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 「ポータル設定 > 確定の取り消し」画面（03 §3.5「確定の取り消し」。3n-2）。
 *
 * 権限：ims_manage_system（administrator のみ）。確定済みデータを変えられるかどうかを決める設定のため、
 * 給与計算サイクル設定・打刻丸め設定と同じ権限レベルにする。
 */
final class AdminConfirmationCancelSettingsPage
{
    private const MENU_SLUG = 'ims-confirmation-cancel-settings';
    private const CAP       = 'ims_manage_system';
    private const NONCE     = 'ims_save_confirmation_cancel_settings';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu'], 12);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_action('admin_post_ims_save_confirmation_cancel_settings', [self::class, 'handle_save']);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            AdminAuthSettingsPage::PARENT_SLUG, // 「ポータル設定」
            '確定の取り消し',
            '確定の取り消し',
            self::CAP,
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

    public static function enqueue(string $hook): void
    {
        if (!str_contains($hook, self::MENU_SLUG)) {
            return;
        }
        wp_enqueue_style('ims-portal-tokens', IMS_PORTAL_URL . 'assets/css/ims-tokens.css', [], IMS_PORTAL_VERSION);
        wp_enqueue_style('ims-admin', IMS_PORTAL_URL . 'assets/css/admin.css', ['ims-portal-tokens'], IMS_PORTAL_VERSION);
    }

    public static function handle_save(): void
    {
        self::guard();
        check_admin_referer(self::NONCE);

        ConfirmationCancelSettings::update(!empty($_POST['allowed']));

        wp_safe_redirect(add_query_arg(['ims_notice' => 'saved'], admin_url('admin.php?page=' . self::MENU_SLUG)));
        exit;
    }

    public static function render(): void
    {
        self::guard();

        $allowed = ConfirmationCancelSettings::is_allowed();
        $notice  = sanitize_key($_GET['ims_notice'] ?? '');
        ?>
        <div class="wrap ims-admin">
            <h1>確定の取り消し</h1>

            <?php if ($notice === 'saved') : ?>
                <div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>
            <?php endif; ?>

            <p class="ims-note">
                オンにすると、人事管理担当者以上が「月次提出状況」画面から、最終承認（確定）した月を取り消せます。
                取り消すときは理由の入力が必要で、誰が・いつ・なぜ取り消したかは記録として残ります。<br>
                テスト運用のあいだはオンにしておき、本番運用に入ったらオフにしてください。
                オフにすると、確定した月は変更できなくなります。
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE); ?>
                <input type="hidden" name="action" value="ims_save_confirmation_cancel_settings">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">確定の取り消し</th>
                        <td>
                            <label>
                                <input type="checkbox" name="allowed" value="1" <?php checked($allowed); ?>>
                                確定した月の取り消しを許可する
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button('保存する'); ?>
            </form>
        </div>
        <?php
    }

    private static function guard(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('この操作を行う権限がありません。', 'ims-portal'), '', ['response' => 403]);
        }
    }
}
