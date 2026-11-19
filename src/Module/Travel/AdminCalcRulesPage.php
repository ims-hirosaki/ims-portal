<?php

declare(strict_types=1);

namespace IMS\Module\Travel;

use IMS\Module\User\Auth\AdminAuthSettingsPage;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 「ポータル設定 > 交通費の計算ルール」画面（04 §2.2。00 §4.4）。
 *
 * 距離の小数点処理を選ぶ。保存するだけで、すでに作られた交通費の金額は書き換えない
 * （新しい交通費の計算から使われる）。
 * 権限：ims_manage_masters（hr_admin・administrator。§2.1 のガソリン単価画面と同じ）。
 */
final class AdminCalcRulesPage
{
    private const MENU_SLUG = 'ims-travel-calc-rules';
    private const CAP       = 'ims_manage_masters';
    private const NONCE     = 'ims_save_travel_calc_rules';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu'], 13);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_action('admin_post_ims_save_travel_calc_rules', [self::class, 'handle_save']);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            AdminAuthSettingsPage::PARENT_SLUG, // 「ポータル設定」
            '交通費の計算ルール',
            '交通費の計算ルール',
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

    /** 選択肢（保存値 => 画面の文言）。 */
    public static function choices(): array
    {
        return [
            ExpenseCalculator::ROUND_FLOOR => '切り捨て（例：12.4km → 12km）',
            ExpenseCalculator::ROUND_ROUND => '四捨五入（例：12.4km → 12km、12.5km → 13km）',
            ExpenseCalculator::ROUND_CEIL  => '切り上げ（例：12.4km → 13km）',
        ];
    }

    public static function handle_save(): void
    {
        self::guard();
        check_admin_referer(self::NONCE);

        $mode = sanitize_key(wp_unslash($_POST['rounding_mode'] ?? ''));
        if (!in_array($mode, ExpenseCalculator::rounding_modes(), true)) {
            wp_safe_redirect(add_query_arg(['ims_notice' => 'invalid'], admin_url('admin.php?page=' . self::MENU_SLUG)));
            exit;
        }
        CalcSettings::update_rounding_mode($mode);

        wp_safe_redirect(add_query_arg(['ims_notice' => 'saved'], admin_url('admin.php?page=' . self::MENU_SLUG)));
        exit;
    }

    public static function render(): void
    {
        self::guard();

        $current = CalcSettings::rounding_mode();
        $notice  = sanitize_key($_GET['ims_notice'] ?? '');
        ?>
        <div class="wrap ims-admin">
            <h1>交通費の計算ルール</h1>

            <?php if ($notice === 'saved') : ?>
                <div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>
            <?php elseif ($notice === 'invalid') : ?>
                <div class="notice notice-error is-dismissible"><p>選択肢が正しくありません。</p></div>
            <?php endif; ?>

            <p class="ims-note">
                通勤の交通費と車両借り上げの金額を計算するとき、距離の小数点以下をどう扱うかを決めます。
                <strong>距離を先に丸めてから</strong>、距離 × 単価で金額を出します（金額の円未満は切り捨て）。<br>
                例（単価 27円/km・12.4km）：切り捨て 324円 ／ 四捨五入 324円 ／ 切り上げ 351円<br>
                すでに作られた交通費の金額は変わりません。これから計算するものに使われます。
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE); ?>
                <input type="hidden" name="action" value="ims_save_travel_calc_rules">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">距離の小数点以下</th>
                        <td>
                            <?php foreach (self::choices() as $value => $label) : ?>
                                <label style="display:block;margin-bottom:6px;">
                                    <input type="radio" name="rounding_mode" value="<?php echo esc_attr($value); ?>" <?php checked($current, $value); ?>>
                                    <?php echo esc_html($label); ?>
                                </label>
                            <?php endforeach; ?>
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
