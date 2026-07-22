<?php

declare(strict_types=1);

namespace IMS\Module\User;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ダッシュボード（/portal/）の外部ツールランチャーバー（00_portal.md §3.2.1）。
 *
 * ・ims_portal_dashboard_top フックで最上部に描画する（ダッシュボード限定）。
 * ・有効なツールを表示順に横並び表示。0件ならエリアごと非表示。
 * ・Google系ツールと非Google系（Teams等）の間に区切り線を1本入れる。
 * ・別タブ（target=_blank rel=noopener）で開く。protocol_scheme があるものは
 *   クリック時にデスクトップアプリ起動を優先し、失敗時は URL にフォールバック（portal.js）。
 * ・640px 以下は CSS で非表示。
 */
final class LauncherBar
{
    public static function init(): void
    {
        add_action('ims_portal_dashboard_top', [self::class, 'render']);
    }

    public static function render(): void
    {
        $apps = LauncherRepository::active();
        if (empty($apps)) {
            return; // 0件はエリアごと非表示
        }
        ?>
        <div class="ims-launcher" role="navigation" aria-label="外部ツール">
            <?php
            $seen_google = false;
            foreach ($apps as $a) :
                $url    = (string) $a['url'];
                $scheme = (string) ($a['protocol_scheme'] ?? '');
                $name   = (string) $a['app_name'];
                $icon   = (string) ($a['icon_url'] ?? '');
                $is_google = self::is_google($url);

                // Google 系の後、最初の非Google 系の直前で区切り線を1本
                if ($seen_google && !$is_google) :
                    echo '<span class="ims-launcher-divider" aria-hidden="true"></span>';
                    $seen_google = false; // 区切りは1回だけ
                endif;
                if ($is_google) {
                    $seen_google = true;
                }
                ?>
                <a class="ims-launcher-item"
                   href="<?php echo esc_url($url); ?>"
                   target="_blank" rel="noopener noreferrer"
                   <?php echo $scheme !== '' ? 'data-scheme="' . esc_attr($scheme) . '"' : ''; ?>>
                    <?php if ($icon !== '') : ?>
                        <img class="ims-launcher-icon" src="<?php echo esc_url($icon); ?>" alt="" width="32" height="32">
                    <?php else : ?>
                        <span class="ims-launcher-icon ims-launcher-fallback"><?php echo esc_html(mb_substr($name, 0, 1)); ?></span>
                    <?php endif; ?>
                    <span class="ims-launcher-label"><?php echo esc_html($name); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private static function is_google(string $url): bool
    {
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        return $host !== '' && (str_ends_with($host, 'google.com') || str_ends_with($host, '.google'));
    }
}
