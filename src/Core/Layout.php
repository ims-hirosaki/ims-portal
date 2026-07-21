<?php

declare(strict_types=1);

namespace IMS\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 各モジュールの Page クラスから呼び出す共通レイアウトヘルパー。
 *
 * 使い方：
 *
 *   final class Page
 *   {
 *       public static function render(): void
 *       {
 *           Layout::render_header('打刻');
 *           // ...ページ固有のHTML...
 *           Layout::render_footer();
 *       }
 *   }
 */
final class Layout
{
    public static function render_header(string $page_title = ''): void
    {
        require IMS_PORTAL_DIR . 'templates/header.php';
    }

    public static function render_footer(): void
    {
        require IMS_PORTAL_DIR . 'templates/footer.php';
    }
}
