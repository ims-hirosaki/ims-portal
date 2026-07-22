<?php

declare(strict_types=1);

namespace IMS\Module\User;

use IMS\Module\User\Auth\AdminAuthSettingsPage;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 外部ツールランチャー管理画面（00_portal.md §3.2.5）。
 *
 * 「ポータル設定 > 外部ツール設定」。権限は ims_manage_masters（hr_admin 以上）。
 * ・左：登録済みツール一覧（ドラッグ並べ替え・表示/非表示・編集・削除）
 * ・右：追加/編集フォーム（アイコンはメディアライブラリ・起動方法・表示順・状態）
 * ・CRUD は admin-post、並べ替えは admin-ajax（管理者操作のため REST でなく ajax で十分）。
 */
final class AdminLauncherPage
{
    private const CAP       = 'ims_manage_masters';
    private const MENU_SLUG = 'ims-launcher-apps';
    private const NONCE     = 'ims_launcher_nonce';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu'], 10);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_action('admin_post_ims_launcher_save', [self::class, 'handle_save']);
        add_action('admin_post_ims_launcher_delete', [self::class, 'handle_delete']);
        add_action('admin_post_ims_launcher_toggle', [self::class, 'handle_toggle']);
        add_action('wp_ajax_ims_launcher_reorder', [self::class, 'handle_reorder']);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            AdminAuthSettingsPage::PARENT_SLUG, // 「ポータル設定」
            '外部ツール設定',
            '外部ツール設定',
            self::CAP,
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

    public static function enqueue(string $hook): void
    {
        if (!str_contains($hook, self::MENU_SLUG)) {
            return;
        }
        wp_enqueue_media(); // アイコンのメディアライブラリ選択
        wp_enqueue_style('ims-portal-tokens', IMS_PORTAL_URL . 'assets/css/ims-tokens.css', [], IMS_PORTAL_VERSION);
        wp_enqueue_style('ims-admin', IMS_PORTAL_URL . 'assets/css/admin.css', ['ims-portal-tokens'], IMS_PORTAL_VERSION);
        wp_enqueue_script('ims-launcher-admin', IMS_PORTAL_URL . 'assets/js/launcher-admin.js', ['jquery'], IMS_PORTAL_VERSION, true);
        wp_localize_script('ims-launcher-admin', 'imsLauncher', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'reorderNonce' => wp_create_nonce('ims_launcher_reorder'),
        ]);
    }

    private static function page_url(): string
    {
        return admin_url('admin.php?page=' . self::MENU_SLUG);
    }

    // ── ハンドラ ───────────────────────────────────────────

    public static function handle_save(): void
    {
        self::guard();
        check_admin_referer(self::NONCE);

        $id    = (int) ($_POST['id'] ?? 0);
        $input = [
            'app_name'        => wp_unslash($_POST['app_name'] ?? ''),
            'url'             => wp_unslash($_POST['url'] ?? ''),
            'icon_url'        => wp_unslash($_POST['icon_url'] ?? ''),
            'launch_method'   => $_POST['launch_method'] ?? 'tab',
            'protocol_scheme' => wp_unslash($_POST['protocol_scheme'] ?? ''),
            'sort_order'      => $_POST['sort_order'] ?? '',
            'is_active'       => ($_POST['is_active'] ?? '1') === '1',
        ];

        $result = $id > 0
            ? LauncherRepository::update($id, $input)
            : LauncherRepository::create($input, get_current_user_id());

        if (is_wp_error($result)) {
            self::redirect_with('error', $result->get_error_message());
        }
        self::redirect_with('saved', $id > 0 ? 'ツールを更新しました。' : 'ツールを追加しました。');
    }

    public static function handle_delete(): void
    {
        self::guard();
        check_admin_referer(self::NONCE);
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            LauncherRepository::delete($id);
        }
        self::redirect_with('saved', 'ツールを削除しました。');
    }

    public static function handle_toggle(): void
    {
        self::guard();
        check_admin_referer(self::NONCE);
        $id     = (int) ($_POST['id'] ?? 0);
        $active = ($_POST['to'] ?? '') === '1';
        if ($id > 0) {
            LauncherRepository::set_active($id, $active);
        }
        self::redirect_with('saved', $active ? '表示に変更しました。' : '非表示に変更しました。');
    }

    public static function handle_reorder(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_send_json_error(['message' => '権限がありません。'], 403);
        }
        check_ajax_referer('ims_launcher_reorder', 'nonce');
        $ids = isset($_POST['order']) && is_array($_POST['order'])
            ? array_map('intval', (array) $_POST['order'])
            : [];
        LauncherRepository::reorder($ids);
        wp_send_json_success(['count' => count($ids)]);
    }

    private static function guard(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('この操作を行う権限がありません。', 'ims-portal'), '', ['response' => 403]);
        }
    }

    private static function redirect_with(string $key, string $msg): void
    {
        wp_safe_redirect(add_query_arg([$key => rawurlencode($msg)], self::page_url()));
        exit;
    }

    // ── 画面描画 ───────────────────────────────────────────

    public static function render(): void
    {
        self::guard();

        $apps = LauncherRepository::all();

        // 編集対象
        $edit = null;
        if (!empty($_GET['edit'])) {
            $edit = LauncherRepository::find((int) $_GET['edit']);
        }
        ?>
        <div class="wrap ims-admin">
            <h1 class="wp-heading-inline">外部ツール設定</h1>
            <hr class="wp-header-end">

            <?php if (!empty($_GET['saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html(rawurldecode((string) $_GET['saved'])); ?></p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['error'])) : ?>
                <div class="notice notice-error"><p><?php echo esc_html(rawurldecode((string) $_GET['error'])); ?></p></div>
            <?php endif; ?>

            <div class="ims-2col">
                <!-- 左：一覧 -->
                <div class="ims-form-col">
                    <div class="ims-card">
                        <h2>登録済みツール</h2>
                        <p class="ims-sub">⠿ をドラッグして並べ替えできます（順序は自動保存されます）。</p>
                        <table class="wp-list-table widefat fixed striped" id="ims-launcher-table">
                            <thead>
                                <tr>
                                    <th style="width:28px;"></th>
                                    <th style="width:52px;">アイコン</th>
                                    <th>ツール名</th>
                                    <th>起動方法</th>
                                    <th style="width:70px;">状態</th>
                                    <th style="width:150px;">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($apps)) : ?>
                                    <tr><td colspan="6" class="ims-empty">まだツールが登録されていません。</td></tr>
                                <?php endif; ?>
                                <?php foreach ($apps as $a) :
                                    $is_app = !empty($a['protocol_scheme']);
                                    ?>
                                    <tr data-id="<?php echo (int) $a['id']; ?>">
                                        <td class="ims-drag-handle" title="ドラッグで並べ替え" style="cursor:grab;">⠿</td>
                                        <td>
                                            <?php if (!empty($a['icon_url'])) : ?>
                                                <img src="<?php echo esc_url((string) $a['icon_url']); ?>" alt="" style="width:28px;height:28px;object-fit:contain;">
                                            <?php else : ?>
                                                <span class="ims-chip ims-chip-off">未設定</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?php echo esc_html((string) $a['app_name']); ?></strong><br>
                                            <span class="ims-sub"><?php echo esc_html((string) $a['url']); ?></span>
                                        </td>
                                        <td><?php echo $is_app ? 'デスクトップアプリ' : '別タブ'; ?></td>
                                        <td>
                                            <?php if ((int) $a['is_active'] === 1) : ?>
                                                <span class="ims-chip ims-chip-on">表示中</span>
                                            <?php else : ?>
                                                <span class="ims-chip ims-chip-off">非表示</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a class="button button-small" href="<?php echo esc_url(add_query_arg('edit', (int) $a['id'], self::page_url())); ?>">編集</a>
                                            <?php echo self::inline_form('ims_launcher_toggle', (int) $a['id'], (int) $a['is_active'] === 1 ? '非表示' : '表示', ['to' => (int) $a['is_active'] === 1 ? '0' : '1']); ?>
                                            <?php echo self::inline_form('ims_launcher_delete', (int) $a['id'], '削除', [], true); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 右：追加/編集フォーム -->
                <div class="ims-form-col">
                    <div class="ims-card">
                        <h2><?php echo $edit ? 'ツールを編集' : 'ツールを追加'; ?></h2>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ims-launcher-form">
                            <input type="hidden" name="action" value="ims_launcher_save">
                            <input type="hidden" name="id" value="<?php echo $edit ? (int) $edit['id'] : 0; ?>">
                            <?php wp_nonce_field(self::NONCE); ?>

                            <p>
                                <label><strong>アイコン画像 <span style="color:#a00;">*</span></strong></label><br>
                                <span class="ims-sub">PNG / SVG（推奨64px以上・2MB以内）。メディアライブラリから選択します。</span><br>
                                <img id="ims-icon-preview" src="<?php echo esc_url((string) ($edit['icon_url'] ?? '')); ?>"
                                     alt="" style="width:48px;height:48px;object-fit:contain;border:1px solid var(--line);border-radius:6px;background:#fff;<?php echo empty($edit['icon_url']) ? 'display:none;' : ''; ?>margin:6px 0;">
                                <br>
                                <button type="button" class="button" id="ims-icon-select">画像を選択</button>
                                <button type="button" class="button" id="ims-icon-clear">クリア</button>
                                <input type="hidden" name="icon_url" id="ims-icon-url" value="<?php echo esc_attr((string) ($edit['icon_url'] ?? '')); ?>">
                            </p>

                            <p>
                                <label><strong>ツール名 <span style="color:#a00;">*</span></strong></label><br>
                                <input type="text" name="app_name" maxlength="50" class="regular-text" required
                                       value="<?php echo esc_attr((string) ($edit['app_name'] ?? '')); ?>">
                            </p>

                            <p>
                                <label><strong>リンク先URL <span style="color:#a00;">*</span></strong></label><br>
                                <input type="url" name="url" class="regular-text" placeholder="https://..." required
                                       value="<?php echo esc_attr((string) ($edit['url'] ?? '')); ?>">
                            </p>

                            <p>
                                <label><strong>起動方法 <span style="color:#a00;">*</span></strong></label><br>
                                <?php $is_app = !empty($edit['protocol_scheme']); ?>
                                <label><input type="radio" name="launch_method" value="tab" class="ims-launch-method" <?php checked(!$is_app); ?>> 別タブで開く</label>
                                &nbsp;&nbsp;
                                <label><input type="radio" name="launch_method" value="app" class="ims-launch-method" <?php checked($is_app); ?>> デスクトップアプリを起動</label>
                            </p>

                            <p id="ims-scheme-row" style="<?php echo $is_app ? '' : 'display:none;'; ?>">
                                <label><strong>デスクトップアプリ起動URL</strong></label><br>
                                <span class="ims-sub">例：<code>msteams://</code>。起動失敗時は上のリンク先URLに切り替わります。</span><br>
                                <input type="text" name="protocol_scheme" class="regular-text" placeholder="msteams://"
                                       value="<?php echo esc_attr((string) ($edit['protocol_scheme'] ?? '')); ?>">
                            </p>

                            <p>
                                <label><strong>表示順</strong></label><br>
                                <input type="number" name="sort_order" min="0" class="small-text"
                                       value="<?php echo esc_attr((string) ($edit['sort_order'] ?? '')); ?>">
                                <span class="ims-sub">空欄なら末尾に追加。ドラッグでも変更できます。</span>
                            </p>

                            <p>
                                <label><strong>バーへの表示 <span style="color:#a00;">*</span></strong></label><br>
                                <?php $active = !isset($edit['is_active']) || (int) $edit['is_active'] === 1; ?>
                                <label><input type="radio" name="is_active" value="1" <?php checked($active); ?>> 表示する</label>
                                &nbsp;&nbsp;
                                <label><input type="radio" name="is_active" value="0" <?php checked(!$active); ?>> 非表示にする</label>
                            </p>

                            <div class="ims-form-actions">
                                <button type="submit" class="button button-primary"><?php echo $edit ? '更新する' : '追加する'; ?></button>
                                <?php if ($edit) : ?>
                                    <a class="button" href="<?php echo esc_url(self::page_url()); ?>">キャンセル</a>
                                <?php endif; ?>
                            </div>
                        </form>

                        <!-- バー上プレビュー -->
                        <div style="margin-top:16px;padding-top:12px;border-top:1px solid var(--line);">
                            <label class="ims-sub"><strong>バー上のプレビュー</strong></label>
                            <div id="ims-launcher-preview" style="display:inline-flex;flex-direction:column;align-items:center;gap:4px;padding:10px 14px;margin-top:6px;border:1px solid var(--line);border-radius:8px;background:#fff;min-width:64px;">
                                <img id="ims-preview-icon" src="<?php echo esc_url((string) ($edit['icon_url'] ?? '')); ?>" alt="" style="width:32px;height:32px;object-fit:contain;<?php echo empty($edit['icon_url']) ? 'display:none;' : ''; ?>">
                                <span id="ims-preview-label" style="font-size:11px;"><?php echo esc_html((string) ($edit['app_name'] ?? 'ツール名')); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * 一覧の操作ボタン用インラインPOSTフォーム。
     * @param array<string,string> $extra
     */
    private static function inline_form(string $action, int $id, string $label, array $extra = [], bool $confirm = false): string
    {
        ob_start();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;"
              <?php echo $confirm ? 'onsubmit="return confirm(\'このツールを削除します。よろしいですか？\');"' : ''; ?>>
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <?php foreach ($extra as $k => $v) : ?>
                <input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>">
            <?php endforeach; ?>
            <?php wp_nonce_field(self::NONCE); ?>
            <button type="submit" class="button button-small button-link-delete" style="<?php echo $confirm ? 'color:#a00;' : ''; ?>"><?php echo esc_html($label); ?></button>
        </form>
        <?php
        return (string) ob_get_clean();
    }
}
