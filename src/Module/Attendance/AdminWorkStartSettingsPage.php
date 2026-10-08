<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

use IMS\Module\User\Auth\AdminAuthSettingsPage;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 「ポータル設定 > 所定の始業時刻」画面（03 §3.3「有給日の自動割り当て」。00 §4.4）。
 *
 * 権限：ims_manage_system（administrator のみ）。給与計算サイクル設定・打刻丸め設定と同じ権限レベル。
 */
final class AdminWorkStartSettingsPage
{
    private const MENU_SLUG = 'ims-work-start-settings';
    private const CAP       = 'ims_manage_system';
    private const NONCE     = 'ims_save_work_start_settings';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu'], 12);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_action('admin_post_ims_save_work_start_settings', [self::class, 'handle_save']);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            AdminAuthSettingsPage::PARENT_SLUG, // 「ポータル設定」
            '所定の始業時刻',
            '所定の始業時刻',
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

        $minutes = WorkStartSettings::parse(sanitize_text_field(wp_unslash($_POST['start_time'] ?? '')));
        if ($minutes === null) {
            wp_safe_redirect(add_query_arg(['ims_notice' => 'invalid'], admin_url('admin.php?page=' . self::MENU_SLUG)));
            exit;
        }
        WorkStartSettings::update($minutes);

        wp_safe_redirect(add_query_arg(['ims_notice' => 'saved'], admin_url('admin.php?page=' . self::MENU_SLUG)));
        exit;
    }

    public static function render(): void
    {
        self::guard();

        $current = WorkStartSettings::format(WorkStartSettings::minutes());
        $notice  = sanitize_key($_GET['ims_notice'] ?? '');
        ?>
        <div class="wrap ims-admin">
            <h1>所定の始業時刻</h1>

            <?php if ($notice === 'saved') : ?>
                <div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>
            <?php elseif ($notice === 'invalid') : ?>
                <div class="notice notice-error is-dismissible"><p>時刻の形式が正しくありません。</p></div>
            <?php endif; ?>

            <p class="ims-note">
                全社員に共通の始業時刻です。有給を取った日は、この時刻から所定労働時間の分だけ、
                事業別時間を自動で記録します（例：始業 8:30・所定 8時間 → 8:30〜16:30）。<br>
                打刻や遅刻の判定には使いません。
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE); ?>
                <input type="hidden" name="action" value="ims_save_work_start_settings">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="start_time">始業時刻</label></th>
                        <td><input type="time" name="start_time" id="start_time" value="<?php echo esc_attr($current); ?>" required></td>
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
