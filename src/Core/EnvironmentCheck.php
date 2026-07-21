<?php

declare(strict_types=1);

namespace IMS\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 実行環境の前提条件をチェックし、満たさない場合は管理画面に警告を表示する。
 *
 * 本番サーバーへ直接デプロイする運用（08 §13）であるため、
 * 「気づかないまま壊れている」状態を避ける目的で用意する。
 */
final class EnvironmentCheck
{
    public static function init(): void
    {
        add_action('admin_notices', [self::class, 'check_permalink_structure']);
    }

    /**
     * パーマリンクが「基本」のままだと /portal/xxx/ のルーティングが機能しないため、
     * hr_admin / administrator にのみ警告を表示する。
     */
    public static function check_permalink_structure(): void
    {
        if (!current_user_can('ims_manage_system') && !current_user_can('manage_options')) {
            return;
        }

        if (get_option('permalink_structure')) {
            return; // 「基本」以外が設定済み
        }

        echo '<div class="notice notice-error"><p>'
            . esc_html__(
                'IMS Hirosaki Portal: パーマリンク設定が「基本」のままです。設定 > パーマリンク で「投稿名」等に変更してください。/portal/ 配下のURLが正しく機能しません。',
                'ims-portal'
            )
            . '</p></div>';
    }
}
