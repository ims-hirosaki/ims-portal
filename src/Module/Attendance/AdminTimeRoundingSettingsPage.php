<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

use IMS\Module\User\Auth\AdminAuthSettingsPage;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 「ポータル設定 > 打刻丸め設定」画面（要件定義書に無い追加仕様。ユーザー確認済み）。
 *
 * 権限：ims_manage_system（administrator のみ）。給与計算に影響する設定のため、
 * 給与計算サイクル設定・打刻システム設定と同じ権限レベルにする。
 *
 * 丸め方向（出勤=切り上げ／退勤=切り下げ／休憩開始=切り下げ／休憩終了=切り上げ）自体は
 * 固定で、ここで変更できるのは丸め単位（分）のみ（WorkTimeCalculator 冒頭コメント参照）。
 */
final class AdminTimeRoundingSettingsPage
{
    private const MENU_SLUG = 'ims-time-rounding-settings';
    private const CAP       = 'ims_manage_system';
    private const NONCE     = 'ims_save_time_rounding_settings';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu'], 12);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_action('admin_post_ims_save_time_rounding_settings', [self::class, 'handle_save']);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            AdminAuthSettingsPage::PARENT_SLUG, // 「ポータル設定」
            '打刻丸め設定',
            '打刻丸め設定',
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

        TimeRoundingSettings::update((int) ($_POST['rounding_minutes'] ?? 15));

        wp_safe_redirect(add_query_arg(['ims_notice' => 'saved'], admin_url('admin.php?page=' . self::MENU_SLUG)));
        exit;
    }

    public static function render(): void
    {
        self::guard();

        $current = TimeRoundingSettings::minutes();
        $notice  = sanitize_key($_GET['ims_notice'] ?? '');
        ?>
        <div class="wrap ims-admin">
            <h1>打刻丸め設定</h1>

            <?php if ($notice === 'saved') : ?>
                <div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>
            <?php endif; ?>

            <p class="ims-note">
                打刻そのもの（打刻ログ）は変更されません。ここで設定するのは、勤怠として集計する際に
                出退勤・休憩の時刻をどこまで丸めるかです。<br>
                出勤・休憩終了は後ろ側へ、退勤・休憩開始は前側へ丸められます
                （例：出勤 8:20 → 8:30、退勤 17:29 → 17:15）。
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE); ?>
                <input type="hidden" name="action" value="ims_save_time_rounding_settings">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="rounding_minutes">丸め単位</label></th>
                        <td>
                            <select name="rounding_minutes" id="rounding_minutes">
                                <?php foreach (TimeRoundingSettings::UNITS as $unit) : ?>
                                    <option value="<?php echo esc_attr((string) $unit); ?>" <?php selected($current, $unit); ?>>
                                        <?php echo $unit === 1 ? '丸めない' : esc_html($unit . '分'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
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
