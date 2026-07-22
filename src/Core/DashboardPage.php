<?php

declare(strict_types=1);

namespace IMS\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `/portal/`（メインダッシュボード）の描画（00_portal.md §3.2 準拠）。
 *
 * Phase 0 時点では、TileRegistry から寄与されたタイルをそのまま並べるだけの
 * 最小実装。ランチャー・ステータスカード・権限別ウィジェットは
 * 01/02/03/05 モジュール実装時に追加していく（このファイルへの改修は
 * タイル自体は不要——各モジュールが ims_portal_register_tile で追加するのみ）。
 */
final class DashboardPage
{
    public static function init(): void
    {
        add_action('ims_portal_register_page', static function (string $router_class): void {
            $router_class::register('', self::class);
        });
    }

    public static function render(): void
    {
        Layout::render_header('ホーム');

        // モジュールが寄与するダッシュボード最上部エリア
        // （00_portal.md §3.2：ランチャー → ステータスカード → タイルグリッド の順）
        do_action('ims_portal_dashboard_top');

        $tiles = TileRegistry::get_tiles_for_user(get_current_user_id());
        ?>
        <div class="tile-grid">
            <?php if (empty($tiles)) : ?>
                <p class="hint"><?php esc_html_e('表示できる機能がまだ登録されていません。', 'ims-portal'); ?></p>
            <?php endif; ?>
            <?php foreach ($tiles as $tile) : ?>
                <a class="tile" href="<?php echo esc_url(home_url($tile['url'])); ?>">
                    <?php if (!empty($tile['resolved_badge'])) : ?>
                        <span class="tile-badge"><?php echo esc_html((string) $tile['resolved_badge']); ?></span>
                    <?php endif; ?>
                    <span class="tile-label"><?php echo esc_html($tile['label']); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php

        Layout::render_footer();
    }
}
