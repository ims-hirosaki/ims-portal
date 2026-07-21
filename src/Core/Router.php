<?php

declare(strict_types=1);

namespace IMS\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `/portal/` 配下のカスタムルーティング。
 *
 * 設計方針（00_portal.md §5.2、08 §6.1 準拠）：
 * ・固定ページに依存せず、add_rewrite_rule + template_redirect で完結させる。
 * ・各モジュールは `ims_portal_register_page` アクションフックで自分のページを
 *   登録するだけでよく、Router 本体は個別モジュールのことを一切知らない。
 *
 * 例（各モジュールの Bootstrap 内）：
 *
 *   add_action('ims_portal_register_page', function (\IMS\Core\Router $router) {
 *       $router->register('timecard', \IMS\Module\Timecard\Page::class);
 *   });
 */
final class Router
{
    private const QUERY_VAR = 'ims_portal_page';

    /**
     * 登録済みページ。slug => ページクラス名（IMS\Core\Portal_Page インターフェース想定）。
     * @var array<string, class-string>
     */
    private static array $pages = [];

    public static function init(): void
    {
        add_action('init', [self::class, 'register_rewrite_rules']);
        add_filter('query_vars', [self::class, 'register_query_vars']);
        add_action('template_redirect', [self::class, 'dispatch']);

        // 各モジュールに登録の機会を与える（init より後、rewrite rule 構築の前に集める）
        add_action('init', [self::class, 'collect_pages'], 5);
    }

    /**
     * 各モジュールへ「自分のページを登録してよい」タイミングを通知する。
     * Router 自身のインスタンスではなく静的クラスで受け付ける（フックの型を単純化するため）。
     */
    public static function collect_pages(): void
    {
        do_action('ims_portal_register_page', self::class);
    }

    /**
     * モジュール側から呼ぶ登録メソッド。
     *
     * @param string       $slug       '/portal/{slug}/' の {slug} 部分（例：'timecard'）。
     *                                  トップページ自身は '' を渡す。
     * @param class-string $page_class 表示処理を持つページクラス。`render(): void` を実装すること。
     */
    public static function register(string $slug, string $page_class): void
    {
        self::$pages[$slug] = $page_class;
    }

    public static function register_query_vars(array $vars): array
    {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    /**
     * `/portal/` および `/portal/{slug}/` の rewrite rule を登録する。
     * 有効化時（Installer::activate）にも明示的に呼ばれる。
     */
    public static function register_rewrite_rules(): void
    {
        add_rewrite_rule('^portal/?$', 'index.php?' . self::QUERY_VAR . '=__dashboard__', 'top');
        add_rewrite_rule('^portal/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top');
    }

    /**
     * リクエストされた slug に対応するページを描画する。
     * 認証ガードは AuthGuard が template_redirect でこれより先に処理する
     * （フック優先度は AuthGuard::init 側で制御）。
     */
    public static function dispatch(): void
    {
        $slug = get_query_var(self::QUERY_VAR);
        if ($slug === '' || $slug === false) {
            return; // /portal/ 配下のリクエストではない
        }

        if ($slug === '__dashboard__') {
            $slug = '';
        }

        if (!isset(self::$pages[$slug])) {
            self::render_not_found();
            return;
        }

        $page_class = self::$pages[$slug];
        if (!class_exists($page_class) || !method_exists($page_class, 'render')) {
            self::render_not_found();
            return;
        }

        $page_class::render();
        exit;
    }

    private static function render_not_found(): void
    {
        status_header(404);
        // Phase 0 時点では簡易テンプレート。モジュール実装後は 404 テンプレートに差し替える。
        require IMS_PORTAL_DIR . 'templates/404.php';
        exit;
    }

    /**
     * 現在のリクエストが /portal/ 配下かどうか（Assets の条件付き enqueue 判定等に使用）。
     */
    public static function is_portal_request(): bool
    {
        $slug = get_query_var(self::QUERY_VAR);
        return $slug !== '' && $slug !== false;
    }
}
