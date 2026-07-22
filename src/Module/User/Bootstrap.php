<?php

declare(strict_types=1);

namespace IMS\Module\User;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 01_user_management モジュールのエントリポイント。
 *
 * core を一切改修せず、フックで自己登録する（08 §6・§10.3 準拠）：
 * ・ims_register_schema … 独自テーブルDDLの寄与
 * ・ims_seed_initial_data … ランチャー初期データ投入（べき等）
 * ・admin_menu 系は各Adminページクラスが自分で登録する
 *
 * ims-portal.php の boot() から Bootstrap::init() を呼ぶ。
 */
final class Bootstrap
{
    public static function init(): void
    {
        // DBスキーマの寄与（Installer が収集して dbDelta する）
        add_filter('ims_register_schema', [Schema::class, 'contribute']);

        // 初期データ投入（有効化時。既に存在すれば何もしない＝べき等）
        add_action('ims_seed_initial_data', [self::class, 'seed_launcher_defaults']);

        // 認証（1c）：フロント・wp-admin 両方で有効化する
        \IMS\Module\User\Auth\PasswordAuth::init();       // auth_type によるパスワードログイン制御
        \IMS\Module\User\Auth\LoginPage::init();          // /portal/login/ 本番ログイン画面

        // フロントエンド・ポータル画面（1d）
        ProfilePage::init();                              // /portal/profile/ マイページ（読み取り専用）
        LauncherBar::init();                              // ダッシュボードの外部ツールランチャー表示

        // 管理画面
        if (is_admin()) {
            AdminUserListPage::init();  // 社員一覧（社員管理メニューの親）(1b)
            AdminMastersPage::init();   // 各種マスタ (1a)
            AdminUserFields::init();    // 標準ユーザー編集画面への業務カード追加 (1b)
            \IMS\Module\User\Auth\AdminAuthSettingsPage::init(); // 認証設定 (1c)
            AdminCsvPage::init();       // アカウント一括管理CSV：エクスポート＋取り込み (1d)
            AdminLauncherPage::init();  // ポータル設定 > 外部ツール設定（ランチャー管理）(1d)
            AdminRetiredPage::init();   // 社員管理 > 退職者一覧（退職処理・復職）(1d)
        }

        // 今後この module に追加していくもの（各スライスで有効化）：
        // 1d: ProfilePage / LauncherBar / AdminCsvPage / AdminLauncherPage / AdminRetiredPage
        //     ← すべて実装済み。これで Phase 1d（モジュール01）完了。
    }

    /**
     * 外部ツールランチャーのデフォルト5件を投入する（00_portal.md §3.2.1 / 01 §2.2）。
     * 既に1件でも登録があればスキップする（再有効化でも重複しない）。
     */
    public static function seed_launcher_defaults(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ims_launcher_apps';

        $existing = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($existing > 0) {
            return;
        }

        $created_by = get_current_user_id();
        $defaults = [
            ['Gmail',    'https://mail.google.com/',      null],
            ['Drive',    'https://drive.google.com/',     null],
            ['Chat',     'https://chat.google.com/',      null],
            ['カレンダー', 'https://calendar.google.com/',  null],
            ['Teams',    'https://teams.microsoft.com/',  'msteams://'],
        ];

        $order = 1;
        foreach ($defaults as [$name, $url, $scheme]) {
            $wpdb->insert($table, [
                'app_name'        => $name,
                'url'             => $url,
                'icon_url'        => '', // アイコンは 1d のランチャー管理画面でメディア指定する
                'protocol_scheme' => $scheme,
                'sort_order'      => $order++,
                'is_active'       => 1,
                'created_by'      => $created_by,
            ]);
        }
    }
}
