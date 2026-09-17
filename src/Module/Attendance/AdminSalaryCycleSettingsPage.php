<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

use IMS\Module\User\Auth\AdminAuthSettingsPage;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 「ポータル設定 > 給与計算サイクル設定」画面（03_attendance_management.md §2.1）。
 *
 * 権限：ims_manage_system（administrator のみ）。要件の
 * 「システム管理者（administrator）のみが変更できるプラグイン設定」に対応。
 *
 * 注：要件定義書の記載は「設定 > 給与計算サイクル」だが、既存実装で
 *     プラグイン設定は「ポータル設定」メニューに集約されているため
 *     （認証設定・外部ツール設定・打刻システム設定と同様）、一貫性を優先してその配下に置く。
 *
 * 締め日変更の制限（§2.1）：確定済み月度が存在する場合はブロックする。
 * 判定は SalaryCycleSettings::has_confirmed_months() に委譲する（wp_monthly_summary が
 * 存在しない現時点では常に false ＝ 制限なし）。
 */
final class AdminSalaryCycleSettingsPage
{
    private const MENU_SLUG = 'ims-salary-cycle-settings';
    private const CAP       = 'ims_manage_system';
    private const NONCE     = 'ims_save_salary_cycle_settings';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu'], 11);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_action('admin_post_ims_save_salary_cycle_settings', [self::class, 'handle_save']);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            AdminAuthSettingsPage::PARENT_SLUG, // 「ポータル設定」
            '給与計算サイクル設定',
            '給与計算サイクル設定',
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

    // ── ハンドラ ───────────────────────────────────────────

    public static function handle_save(): void
    {
        self::guard();
        check_admin_referer(self::NONCE);

        $closing_day = sanitize_key($_POST['closing_day'] ?? '');
        $payment_day = sanitize_key($_POST['payment_day'] ?? '');
        $current     = SalaryCycleSettings::all();

        // 締め日を実際に変更しようとした場合のみ、確定済み月度の有無をチェックする
        if ($closing_day !== $current['closing_day'] && SalaryCycleSettings::has_confirmed_months()) {
            self::redirect('locked');
        }

        SalaryCycleSettings::update([
            'closing_day' => $closing_day,
            'payment_day' => $payment_day,
        ]);

        self::redirect('saved');
    }

    // ── 画面描画 ───────────────────────────────────────────

    public static function render(): void
    {
        self::guard();

        $opt    = SalaryCycleSettings::all();
        $notice = sanitize_key($_GET['ims_notice'] ?? '');
        $locked = SalaryCycleSettings::has_confirmed_months();
        ?>
        <div class="wrap ims-admin">
            <h1>給与計算サイクル設定</h1>
            <?php self::render_notice($notice); ?>

            <?php if ($locked) : ?>
                <div class="notice notice-warning">
                    <p>確定済みの月度が存在するため、締め日は変更できません。変更する場合は該当月度のロック解除を先に行ってください。</p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE); ?>
                <input type="hidden" name="action" value="ims_save_salary_cycle_settings">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="closing_day">締め日</label></th>
                        <td>
                            <select name="closing_day" id="closing_day">
                                <?php foreach (SalaryCycleSettings::CLOSING_DAYS as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($opt['closing_day'], $value); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                各月度のタイムカード画面の対象期間を自動生成する基準です。<br>
                                例：当月末日締め → 対象期間 = 当月1日〜当月末日／当月20日締め → 対象期間 = 前月21日〜当月20日
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="payment_day">支払日</label></th>
                        <td>
                            <select name="payment_day" id="payment_day">
                                <?php foreach (SalaryCycleSettings::PAYMENT_DAYS as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($opt['payment_day'], $value); ?>>
                                        <?php echo esc_html($label); ?>
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

    private static function render_notice(string $notice): void
    {
        if ($notice === '') {
            return;
        }
        $map = [
            'saved'  => ['success', '設定を保存しました。'],
            'locked' => ['error', '確定済みの月度が存在するため、締め日を変更できませんでした。'],
        ];
        if (!isset($map[$notice])) {
            return;
        }
        [$type, $message] = $map[$notice];
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    private static function guard(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('この操作を行う権限がありません。', 'ims-portal'), '', ['response' => 403]);
        }
    }

    private static function redirect(string $notice): void
    {
        wp_safe_redirect(add_query_arg(['ims_notice' => $notice], admin_url('admin.php?page=' . self::MENU_SLUG)));
        exit;
    }
}
