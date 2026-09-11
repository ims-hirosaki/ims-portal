<?php
/**
 * Plugin Name:       IMS Hirosaki Portal
 * Plugin URI:        https://portal-site.labs-ims.com/
 * Description:       IMS Hirosaki 社内業務システム（グループウェア）。ユーザー管理・打刻・勤怠・交通費・稟議・Google Workspace 連携を統合するポータル基盤プラグイン。
 * Version:           0.5.4-phase2e
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            IMS Hirosaki
 * Text Domain:       ims-portal
 * Domain Path:       /languages
 *
 * ── アーキテクチャメモ（08_implementation_guidelines.md 準拠） ──
 * ・単一プラグイン・モジュラモノリス構成。業務ロジックはすべてこのプラグインに置き、テーマには依存しない。
 * ・FTP配布・ローカルDockerなしの運用のため、Composer install を前提にしない軽量オートローダーを使用する
 *   （PSR-4 相当：IMS\Foo\Bar → src/Foo/Bar.php）。
 * ・新モジュールは src/Module/ にディレクトリを1つ追加し、フックで自己登録するだけで core 無改修のまま載る。
 */

declare(strict_types=1);

// 直接アクセスを禁止
if (!defined('ABSPATH')) {
    exit;
}

// ── 定数 ──────────────────────────────────────────────
define('IMS_PORTAL_VERSION', '0.5.4-phase2e'); // 2e-1：打刻修正機能のバックエンド。UI未接続
define('IMS_PORTAL_DB_VERSION', 3); // スキーマ変更時にインクリメントする（08 §5.2）。v2: 01モジュールのテーブル追加
define('IMS_PORTAL_FILE', __FILE__);
define('IMS_PORTAL_DIR', plugin_dir_path(__FILE__));
define('IMS_PORTAL_URL', plugin_dir_url(__FILE__));
define('IMS_PORTAL_TEXT_DOMAIN', 'ims-portal');

// ── 軽量オートローダー（IMS\ 名前空間 → src/ ディレクトリ） ──────
spl_autoload_register(static function (string $class): void {
    $prefix = 'IMS\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = IMS_PORTAL_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($path)) {
        require $path;
    }
});

use IMS\Core\Installer;
use IMS\Core\Roles;
use IMS\Core\Router;
use IMS\Core\AuthGuard;
use IMS\Core\Assets;
use IMS\Core\TileRegistry;
use IMS\Core\SummaryAggregator;
use IMS\Core\EnvironmentCheck;
use IMS\Core\DashboardPage;

/**
 * プラグイン全体のブートストラップ。
 * 各 Core クラスと各モジュールの Bootstrap を初期化する。
 */
final class IMS_Portal_Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->register_activation_hooks();
        add_action('plugins_loaded', [$this, 'boot']);
    }

    private function register_activation_hooks(): void
    {
        register_activation_hook(IMS_PORTAL_FILE, [Installer::class, 'activate']);
        register_deactivation_hook(IMS_PORTAL_FILE, [Installer::class, 'deactivate']);
    }

    /**
     * 本体の初期化。plugins_loaded で全モジュールがロード済みの状態にする。
     */
    public function boot(): void
    {
        load_plugin_textdomain(IMS_PORTAL_TEXT_DOMAIN, false, dirname(plugin_basename(IMS_PORTAL_FILE)) . '/languages');

        // ── Core（土台）の初期化 ──
        Roles::init();
        Router::init();
        AuthGuard::init();
        Assets::init();
        TileRegistry::init();
        SummaryAggregator::init();
        EnvironmentCheck::init();

        // 有効化後・スキーマバージョン差分チェック（本番へFTPで上書きデプロイした際の追従用）
        add_action('admin_init', [Installer::class, 'maybe_upgrade']);

        // ── Phase 0 確認用プレースホルダー ──
        // DashboardPage は 00 基盤の一部として残る（タイル自体は各モジュールが登録する）。
        // ログイン画面は 1c で Module\User\Auth\LoginPage に置き換わった。
        DashboardPage::init();

        // ── 各モジュールの自己登録 ──
        // 今後 src/Module/Xxx/Bootstrap.php をここに追加していく。
        \IMS\Module\User\Bootstrap::init();   // 01_user_management
        \IMS\Module\Timecard\Bootstrap::init();
        do_action('ims_portal_modules_loaded');
    }
}

IMS_Portal_Plugin::instance();
