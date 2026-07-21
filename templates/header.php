<?php
/**
 * 全ポータルページ共通レイアウト（00_portal.md §3.1・§4.3 準拠）。
 *
 * 使い方（各モジュールの Page クラスから）：
 *
 *   IMS\Core\Layout::render_header('打刻');
 *   // ...ページ固有のコンテンツ...
 *   IMS\Core\Layout::render_footer();
 *
 * Phase 0 時点ではヘッダー・フッターの骨格のみ。バッジ件数・ユーザー名表示は
 * 01モジュール実装後、REST（ims/v1/portal/badge-count）から非同期取得する。
 */

if (!defined('ABSPATH')) {
    exit;
}

/** @var string $page_title 呼び出し元から渡されるページタイトル */
$page_title = $page_title ?? '';
$user       = wp_get_current_user();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html($page_title !== '' ? $page_title . ' | IMS Hirosaki' : 'IMS Hirosaki'); ?></title>
    <?php wp_head(); ?>
</head>
<body class="ims-portal-body">
    <div class="brand-strip"></div>
    <header class="ims-header">
        <div class="ims-header__inner">
            <a class="ims-header__logo" href="<?php echo esc_url(home_url('/portal/')); ?>">IMS Hirosaki</a>
            <div class="ims-header__title page-chip"><?php echo esc_html($page_title); ?></div>
            <div class="ims-header__user">
                <span class="ims-header__username"><?php echo esc_html($user->display_name); ?></span>
                <button class="icon-btn" type="button" aria-label="<?php esc_attr_e('通知', 'ims-portal'); ?>">🔔</button>
                <a class="btn-logout" href="<?php echo esc_url(wp_logout_url(home_url('/portal/login/'))); ?>">
                    <?php esc_html_e('ログアウト', 'ims-portal'); ?>
                </a>
            </div>
        </div>
    </header>
    <main class="ims-content">
