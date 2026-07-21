<?php

declare(strict_types=1);

namespace IMS\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * /portal/ ページ判定時のみアセットを条件付きで読み込む（08 §9 準拠）。
 * wp-admin への混入を防ぐため、wp-admin 側は個別モジュールが必要に応じて
 * 独自に enqueue する（このクラスでは扱わない）。
 */
final class Assets
{
    public static function init(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_portal_assets']);
    }

    public static function enqueue_portal_assets(): void
    {
        if (!Router::is_portal_request()) {
            return;
        }

        // 07_design_system.md §3.2 準拠のWebフォント
        wp_enqueue_style(
            'ims-portal-fonts',
            'https://fonts.googleapis.com/css2?family=Shippori+Mincho:wght@400;500;600;700;800&family=Zen+Kaku+Gothic+New:wght@400;500;700;900&display=swap',
            [],
            null
        );

        // 07 デザイントークン（正本）。他の全CSSより先に読み込む。
        wp_enqueue_style(
            'ims-portal-tokens',
            IMS_PORTAL_URL . 'assets/css/ims-tokens.css',
            [],
            IMS_PORTAL_VERSION
        );

        // ポータル共通レイアウトCSS（トークンに依存するため後で読み込む）
        wp_enqueue_style(
            'ims-portal-layout',
            IMS_PORTAL_URL . 'assets/css/portal.css',
            ['ims-portal-tokens'],
            IMS_PORTAL_VERSION
        );

        wp_enqueue_script(
            'ims-portal-js',
            IMS_PORTAL_URL . 'assets/js/portal.js',
            [],
            IMS_PORTAL_VERSION,
            true
        );

        // REST root と nonce をフロントに渡す（08 §8 準拠）
        wp_localize_script('ims-portal-js', 'imsPortal', [
            'restUrl' => esc_url_raw(rest_url('ims/v1/')),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);
    }
}
