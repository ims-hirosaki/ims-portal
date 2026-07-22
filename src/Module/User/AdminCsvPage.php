<?php

declare(strict_types=1);

namespace IMS\Module\User;

use IMS\Module\User\Csv\CsvExporter;
use IMS\Module\User\Csv\CsvImporter;
use IMS\Module\User\Csv\CsvSchema;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * アカウント一括管理（CSV）— 01_user_management.md §3.1。
 *
 * ・エクスポート：admin-post.php 経由でストリーム出力（全ユーザー・退職者含む）。
 * ・取り込み：社員管理配下のサブ画面。ファイルアップロード → 行単位検証 →
 *   新規作成/上書き更新 → 結果サマリー表示（PRG パターン。結果は transient 経由）。
 */
final class AdminCsvPage
{
    private const CAP           = 'ims_manage_users';
    public  const EXPORT_ACTION = 'ims_users_csv_export';
    public  const IMPORT_ACTION = 'ims_users_csv_import';
    public  const IMPORT_SLUG   = 'ims-employees-csv-import';

    public static function init(): void
    {
        add_action('admin_post_' . self::EXPORT_ACTION, [self::class, 'handle_export']);
        add_action('admin_post_' . self::IMPORT_ACTION, [self::class, 'handle_import']);
        add_action('admin_menu', [self::class, 'register_menu'], 11); // 親（社員一覧）の後
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            AdminUserListPage::PARENT_SLUG,
            'CSV取り込み',
            'CSV取り込み',
            self::CAP,
            self::IMPORT_SLUG,
            [self::class, 'render_import_page']
        );
    }

    public static function enqueue(string $hook): void
    {
        if (!str_contains($hook, self::IMPORT_SLUG)) {
            return;
        }
        wp_enqueue_style('ims-portal-tokens', IMS_PORTAL_URL . 'assets/css/ims-tokens.css', [], IMS_PORTAL_VERSION);
        wp_enqueue_style('ims-admin', IMS_PORTAL_URL . 'assets/css/admin.css', ['ims-portal-tokens'], IMS_PORTAL_VERSION);
    }

    // ── URL ヘルパー（社員一覧ヘッダのボタン用） ──

    public static function export_url(): string
    {
        return wp_nonce_url(
            admin_url('admin-post.php?action=' . self::EXPORT_ACTION),
            self::EXPORT_ACTION
        );
    }

    public static function import_page_url(): string
    {
        return admin_url('admin.php?page=' . self::IMPORT_SLUG);
    }

    // ── エクスポート ──

    public static function handle_export(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('この操作を行う権限がありません。', 'ims-portal'), '', ['response' => 403]);
        }
        check_admin_referer(self::EXPORT_ACTION);

        $csv      = CsvExporter::build();
        $filename = CsvExporter::filename();

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($csv));

        echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput — CSV バイナリ本文
        exit;
    }

    // ── 取り込み ──

    public static function handle_import(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('この操作を行う権限がありません。', 'ims-portal'), '', ['response' => 403]);
        }
        check_admin_referer(self::IMPORT_ACTION);

        $redirect = self::import_page_url();

        if (empty($_FILES['ims_csv']['tmp_name']) || !is_uploaded_file($_FILES['ims_csv']['tmp_name'])) {
            set_transient(self::result_key(), ['fatal' => 'CSVファイルが選択されていません。'], 300);
            wp_safe_redirect($redirect);
            exit;
        }

        $tmp = $_FILES['ims_csv']['tmp_name'];
        $result = CsvImporter::import($tmp, get_current_user_id());

        set_transient(self::result_key(), $result, 300);
        wp_safe_redirect($redirect);
        exit;
    }

    private static function result_key(): string
    {
        return 'ims_csv_import_result_' . get_current_user_id();
    }

    public static function render_import_page(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('この操作を行う権限がありません。', 'ims-portal'));
        }

        $result = get_transient(self::result_key());
        if ($result !== false) {
            delete_transient(self::result_key());
        }
        ?>
        <div class="wrap ims-admin">
            <h1 class="wp-heading-inline">アカウント一括取り込み（CSV）</h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=' . AdminUserListPage::PARENT_SLUG)); ?>" class="page-title-action">社員一覧へ戻る</a>
            <hr class="wp-header-end">

            <?php if (is_array($result)) : ?>
                <?php self::render_result($result); ?>
            <?php endif; ?>

            <div class="ims-card" style="max-width:820px;">
                <h2>CSVファイルを選択</h2>
                <p class="ims-sub">
                    文字コードは UTF-8（BOM付き可）。1行目はヘッダ行として扱います。<br>
                    社員番号を突合キーに、既存社員は上書き更新、未登録は新規作成します。
                    任意項目が空欄の行は、その項目を「変更しない」で扱います。
                </p>
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::IMPORT_ACTION); ?>">
                    <?php wp_nonce_field(self::IMPORT_ACTION); ?>
                    <p><input type="file" name="ims_csv" accept=".csv,text/csv" required></p>
                    <p>
                        <button type="submit" class="button button-primary">取り込みを実行</button>
                        <a href="<?php echo esc_url(self::export_url()); ?>" class="button">現在の一覧をCSVでダウンロード（雛形として利用可）</a>
                    </p>
                </form>
            </div>

            <div class="ims-card" style="max-width:820px;">
                <h2>列の形式（A〜Q の17列固定）</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th style="width:60px;">列</th><th>項目</th><th style="width:80px;">必須</th></tr></thead>
                    <tbody>
                        <?php
                        $letter = 'A';
                        foreach (CsvSchema::columns() as $c) :
                            ?>
                            <tr>
                                <td><?php echo esc_html($letter); ?></td>
                                <td><?php echo esc_html($c['label']); ?></td>
                                <td><?php echo $c['required'] ? '✓' : ''; ?></td>
                            </tr>
                            <?php
                            $letter++;
                        endforeach;
                        ?>
                    </tbody>
                </table>
                <p class="ims-sub">
                    認証方式は <code>google_sso</code> または <code>password</code>。<code>google_sso</code> のメールは
                    <code><?php echo esc_html(CsvSchema::GOOGLE_DOMAIN); ?></code> ドメイン必須です。
                    <code>password</code> の新規作成時は仮パスワードを自動発行しメール送信します。
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * @param array<string,mixed> $result
     */
    private static function render_result(array $result): void
    {
        if (!empty($result['fatal'])) {
            echo '<div class="notice notice-error"><p>' . esc_html((string) $result['fatal']) . '</p></div>';
            return;
        }

        $total    = (int) ($result['total'] ?? 0);
        $created  = (int) ($result['created'] ?? 0);
        $updated  = (int) ($result['updated'] ?? 0);
        $emails   = (int) ($result['emails_sent'] ?? 0);
        $errors   = (array) ($result['errors'] ?? []);
        $warnings = (array) ($result['warnings'] ?? []);

        $class = $errors === [] ? 'notice-success' : 'notice-warning';
        echo '<div class="notice ' . esc_attr($class) . '"><p><strong>取り込み結果</strong>：'
            . sprintf(
                '対象 %d 行（新規作成 %d／更新 %d／エラー %d）。仮パスワードメール送信 %d 件。',
                $total,
                $created,
                $updated,
                count($errors),
                $emails
            )
            . '</p></div>';

        if ($errors !== []) {
            echo '<div class="ims-card" style="max-width:820px;"><h2 style="color:#a00;">エラー行（取り込まれませんでした）</h2>';
            echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th style="width:70px;">行</th><th style="width:140px;">社員番号</th><th>内容</th></tr></thead><tbody>';
            foreach ($errors as $e) {
                echo '<tr><td>' . esc_html((string) $e['row']) . '</td><td>' . esc_html((string) $e['code']) . '</td><td>'
                    . esc_html(implode(' / ', (array) $e['messages'])) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        if ($warnings !== []) {
            echo '<div class="ims-card" style="max-width:820px;"><h2>警告（取り込みは完了しています）</h2>';
            echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th style="width:70px;">行</th><th style="width:140px;">社員番号</th><th>内容</th></tr></thead><tbody>';
            foreach ($warnings as $w) {
                echo '<tr><td>' . esc_html((string) $w['row']) . '</td><td>' . esc_html((string) $w['code']) . '</td><td>'
                    . esc_html(implode(' / ', (array) $w['messages'])) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
    }
}
