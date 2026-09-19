<?php

declare(strict_types=1);

namespace IMS\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 全モジュールの DDL を収集して一括適用する、中央集約型インストーラー。
 *
 * 設計方針（08_implementation_guidelines.md §5.2 準拠）：
 * ・各モジュールは `ims_register_schema` フィルタで自分のテーブルDDLを寄与するだけでよい。
 *   Installer 本体は他モジュールのテーブル定義を一切知らない（core 無改修で拡張できる）。
 * ・有効化・無効化・再有効化は何度実行しても安全（べき等）に保つ。
 *   本番サーバーへ直接デプロイする運用のため、事故時に「無効化 → 再有効化」で
 *   復旧できることを重視する。
 * ・無効化時にデータは一切削除しない（プラグイン削除時も同様。物理削除はしない）。
 */
final class Installer
{
    private const DB_VERSION_OPTION = 'ims_db_version';

    /**
     * 有効化フック。
     * WordPress コアのユーザー・ロール機構やオプションテーブルは使い回し、
     * 独自テーブルのみ dbDelta で作成する。
     */
    public static function activate(): void
    {
        self::run_migrations();
        Roles::register_roles(); // ロール登録も有効化時に確実に行う（べき等）
        self::maybe_seed_initial_data();

        // パーマリンク未反映によるルーティング崩れを防ぐため、rewrite rules を再構築する
        Router::register_rewrite_rules();
        flush_rewrite_rules();
    }

    /**
     * 無効化フック。データは一切削除しない。
     * rewrite rules のみクリアし、再有効化時に Router が再登録する。
     */
    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    /**
     * FTPでファイルだけ上書きデプロイした場合、activation hook は発火しないため、
     * admin_init 時にスキーマバージョン差分を検知して追従する。
     */
    public static function maybe_upgrade(): void
    {
        $installed = (int) get_option(self::DB_VERSION_OPTION, 0);
        if ($installed < IMS_PORTAL_DB_VERSION) {
            self::run_migrations();
            Roles::register_roles();
            self::maybe_seed_initial_data(); // 初期データもべき等なので追従デプロイ時に実行
        }
    }

    /**
     * `ims_register_schema` フィルタで集められた全DDLを dbDelta で適用する。
     *
     * 各モジュールは以下のように寄与する：
     *
     *   add_filter('ims_register_schema', function (array $ddls): array {
     *       global $wpdb;
     *       $charset = $wpdb->get_charset_collate();
     *       $table   = $wpdb->prefix . 'ims_example';
     *       $ddls[]  = "CREATE TABLE {$table} ( ... ) {$charset};";
     *       return $ddls;
     *   });
     *
     * dbDelta は新規テーブル・新規カラムの追加には確実だが、既存カラムの属性変更
     * （NULL許容化等、型そのものは変わらない変更）を確実に反映しない既知の制限がある
     * （実機で確認：original_datetime を NOT NULL → DEFAULT NULL に変更したが反映されず、
     * INSERT が失敗し続けた）。dbDeltaで対応できない変更は `ims_register_raw_migrations`
     * フィルタで生の ALTER 文を寄与させ、ここで直接実行する。ALTER は必ず何度実行しても
     * 安全な内容にすること（毎回のバージョンアップ時に再実行され得るため）。
     */
    private static function run_migrations(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        /**
         * @var string[] $ddls 各モジュールから寄与された CREATE TABLE 文の配列
         */
        $ddls = apply_filters('ims_register_schema', []);

        foreach ($ddls as $ddl) {
            dbDelta($ddl);
        }

        global $wpdb;
        /**
         * @var string[] $raw_migrations dbDeltaでは対応できない変更の生ALTER文の配列
         */
        $raw_migrations = apply_filters('ims_register_raw_migrations', []);
        foreach ($raw_migrations as $sql) {
            $wpdb->query($sql);
        }

        update_option(self::DB_VERSION_OPTION, IMS_PORTAL_DB_VERSION);
    }

    /**
     * 初期データ投入（例：外部ツールランチャーのデフォルト5件）。
     * 各モジュールが `ims_seed_initial_data` アクションで自分の初期データ投入を行う。
     * 既に投入済みかどうかの判定は各モジュール側の責務とする（べき等性の担保）。
     */
    private static function maybe_seed_initial_data(): void
    {
        do_action('ims_seed_initial_data');
    }
}
