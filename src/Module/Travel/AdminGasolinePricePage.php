<?php

declare(strict_types=1);

namespace IMS\Module\Travel;

use IMS\Module\User\Auth\AdminAuthSettingsPage;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 「ポータル設定 > ガソリン単価の変更」画面（04 §2.1。00 §4.4）。
 *
 * 流れ：新しい単価と適用開始月を入力 → 確認画面（何件・どの月度が変わるか）→ 実行。
 * 確定済みの月度は変わらない。変更履歴（いつ・誰が・いくらに）を下に一覧する。
 * 権限：ims_manage_masters（hr_admin・administrator）。
 */
final class AdminGasolinePricePage
{
    private const MENU_SLUG    = 'ims-gasoline-price';
    private const CAP          = 'ims_manage_masters';
    private const NONCE_PREV   = 'ims_preview_gasoline_price';
    private const NONCE_APPLY  = 'ims_apply_gasoline_price';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu'], 14);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_action('admin_post_ims_preview_gasoline_price', [self::class, 'handle_preview']);
        add_action('admin_post_ims_apply_gasoline_price', [self::class, 'handle_apply']);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            AdminAuthSettingsPage::PARENT_SLUG, // 「ポータル設定」
            'ガソリン単価の変更',
            'ガソリン単価の変更',
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

    /** 確認画面を出す（DBは変更しない）。 */
    public static function handle_preview(): void
    {
        self::guard();
        check_admin_referer(self::NONCE_PREV);

        $price      = sanitize_text_field(wp_unslash($_POST['unit_price'] ?? ''));
        $apply_from = sanitize_text_field(wp_unslash($_POST['apply_from'] ?? ''));
        $result     = GasolinePriceService::preview($price, $apply_from);

        if ($result instanceof \WP_Error) {
            self::redirect(['ims_notice' => 'error', 'ims_msg' => $result->get_error_message()]);
        }
        self::render($result);
        exit;
    }

    public static function handle_apply(): void
    {
        self::guard();
        check_admin_referer(self::NONCE_APPLY);

        $result = GasolinePriceService::apply(
            get_current_user_id(),
            sanitize_text_field(wp_unslash($_POST['unit_price'] ?? '')),
            sanitize_text_field(wp_unslash($_POST['apply_from'] ?? ''))
        );
        if ($result instanceof \WP_Error) {
            self::redirect(['ims_notice' => 'error', 'ims_msg' => $result->get_error_message()]);
        }
        self::redirect(['ims_notice' => 'applied', 'n' => count($result['updates']), 'skip' => $result['skipped_confirmed']]);
    }

    /**
     * @param array<string, mixed>|null $preview 確認画面のとき、GasolinePriceService::preview() の結果
     */
    public static function render(?array $preview = null): void
    {
        self::guard();

        $notice  = sanitize_key($_GET['ims_notice'] ?? '');
        $current = GasolinePriceService::current_price();
        ?>
        <div class="wrap ims-admin">
            <h1>ガソリン単価の変更</h1>

            <?php if ($notice === 'applied') : ?>
                <div class="notice notice-success is-dismissible"><p>
                    単価を変更し、<?php echo (int) ($_GET['n'] ?? 0); ?>件の交通費を計算し直しました。
                    <?php if ((int) ($_GET['skip'] ?? 0) > 0) : ?>
                        確定済みの月の<?php echo (int) $_GET['skip']; ?>件は変更していません。
                    <?php endif; ?>
                </p></div>
            <?php elseif ($notice === 'error') : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['ims_msg'] ?? '保存できませんでした。'))); ?></p></div>
            <?php endif; ?>

            <p class="ims-note">
                ガソリン単価（1kmあたりの円）を変えると、<strong>適用開始月以降</strong>の交通費・車両借り上げの金額を計算し直します。
                <strong>確定済みの月は変わりません。</strong>実行前に、何件が変わるかを確認できます。<br>
                現在の単価：<strong><?php echo $current !== null ? esc_html($current) . ' 円/km' : '未設定'; ?></strong>
            </p>

            <?php if ($preview !== null) : ?>
                <?php self::render_confirm($preview); ?>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field(self::NONCE_PREV); ?>
                    <input type="hidden" name="action" value="ims_preview_gasoline_price">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="unit_price">新しい単価（円/km）</label></th>
                            <td><input type="text" inputmode="decimal" name="unit_price" id="unit_price" value="<?php echo esc_attr((string) $current); ?>" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="apply_from">適用開始月</label></th>
                            <td><input type="month" name="apply_from" id="apply_from" value="<?php echo esc_attr(gmdate('Y-m')); ?>" required></td>
                        </tr>
                    </table>
                    <?php submit_button('変更内容を確認する'); ?>
                </form>
            <?php endif; ?>

            <?php self::render_history(); ?>
        </div>
        <?php
    }

    /** @param array<string, mixed> $p */
    private static function render_confirm(array $p): void
    {
        $count = count($p['updates']);
        ?>
        <div class="notice notice-warning inline">
            <p>
                <strong><?php echo esc_html($p['price']); ?> 円/km</strong>（<?php echo esc_html($p['apply_from']); ?> 分から）に変更します。<br>
                計算し直す交通費：<strong><?php echo (int) $count; ?>件</strong>（<?php echo (int) $p['users']; ?>人）<br>
                変わる月：<?php echo $p['months'] ? esc_html(implode('、', $p['months'])) : 'なし'; ?><br>
                <?php if ($p['skipped_confirmed'] > 0) : ?>
                    確定済みのため変わらないもの：<?php echo (int) $p['skipped_confirmed']; ?>件<br>
                <?php endif; ?>
                実行すると元には戻せません（もう一度単価を変えることはできます）。
            </p>
        </div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
            <?php wp_nonce_field(self::NONCE_APPLY); ?>
            <input type="hidden" name="action" value="ims_apply_gasoline_price">
            <input type="hidden" name="unit_price" value="<?php echo esc_attr($p['price']); ?>">
            <input type="hidden" name="apply_from" value="<?php echo esc_attr($p['apply_from']); ?>">
            <?php submit_button('この内容で実行する', 'primary', 'submit', false); ?>
        </form>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>">やめる</a>
        <?php
    }

    private static function render_history(): void
    {
        $rows = GasolinePriceService::history();
        ?>
        <h2 style="margin-top:2em;">変更履歴</h2>
        <table class="widefat striped" style="max-width:860px;">
            <thead><tr><th>変更日時</th><th>変更前</th><th>変更後</th><th>適用開始月</th><th>計算し直した件数</th><th>変更した人</th></tr></thead>
            <tbody>
            <?php if (!$rows) : ?>
                <tr><td colspan="6">まだ変更の記録はありません。</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r) :
                $user = get_userdata((int) $r['changed_by']); ?>
                <tr>
                    <td><?php echo esc_html($r['changed_at']); ?></td>
                    <td><?php echo $r['prev_unit_price'] !== null ? esc_html($r['prev_unit_price']) . ' 円' : '―'; ?></td>
                    <td><?php echo esc_html($r['unit_price']); ?> 円</td>
                    <td><?php echo esc_html($r['apply_from']); ?></td>
                    <td><?php echo (int) $r['affected_count']; ?>件</td>
                    <td><?php echo esc_html($user ? $user->display_name : '（不明）'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /** @param array<string, scalar> $args */
    private static function redirect(array $args): never
    {
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=' . self::MENU_SLUG)));
        exit;
    }

    private static function guard(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('この操作を行う権限がありません。', 'ims-portal'), '', ['response' => 403]);
        }
    }
}
