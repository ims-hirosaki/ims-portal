<?php

declare(strict_types=1);

namespace IMS\Module\User;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 「社員管理 > 各種マスタ」wp-admin 画面（01_user_management.md §5.1.2 準拠）。
 *
 * ・タブ：所属情報（所属/部署/役職/職種の4セクション）/ 雇用形態 / 手当設定
 * ・各セクションは左に一覧、右に追加・編集フォームの2カラム。
 * ・保存はサーバーサイド（admin-post.php）でnonce検証 → PRGパターンでリダイレクト。
 * ・停止中の在籍社員がいる項目は、停止時に人数を警告表示する。
 *
 * 権限：ims_manage_masters（hr_admin 以上）。
 */
final class AdminMastersPage
{
    private const MENU_SLUG = 'ims-masters';
    private const CAP       = 'ims_manage_masters';

    /** タブ定義：tab キー => 含まれるマスタ種別 */
    private const TABS = [
        'affiliation_info' => ['label' => '所属情報', 'types' => ['affiliation', 'department', 'position', 'job_type']],
        'employment'       => ['label' => '雇用形態', 'types' => ['employment_type']],
        'allowance'        => ['label' => '手当設定', 'types' => ['allowance']],
    ];

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_post_ims_save_master', [self::class, 'handle_save']);
        add_action('admin_post_ims_master_state', [self::class, 'handle_state']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
    }

    public static function register_menu(): void
    {
        // 親メニュー「社員管理」は AdminUserListPage が登録する。ここでは
        // 「各種マスタ」サブメニューのみを親（ims-employees）に追加する。
        add_submenu_page(
            AdminUserListPage::PARENT_SLUG,
            '各種マスタ',
            '各種マスタ',
            self::CAP,
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

    public static function enqueue_admin_assets(string $hook): void
    {
        // 本プラグインの管理画面でのみ読み込む
        if (!str_contains($hook, 'ims-employees') && !str_contains($hook, self::MENU_SLUG)) {
            return;
        }
        wp_enqueue_style('ims-portal-tokens', IMS_PORTAL_URL . 'assets/css/ims-tokens.css', [], IMS_PORTAL_VERSION);
        wp_enqueue_style('ims-admin', IMS_PORTAL_URL . 'assets/css/admin.css', ['ims-portal-tokens'], IMS_PORTAL_VERSION);
    }

    // ── フォーム処理（admin-post.php） ─────────────────────────

    public static function handle_save(): void
    {
        self::guard();
        check_admin_referer('ims_save_master');

        $type = sanitize_key($_POST['master_type'] ?? '');
        $tab  = sanitize_key($_POST['tab'] ?? 'affiliation_info');
        if (!MasterRepository::is_valid_type($type)) {
            self::redirect($tab, 'error', 'invalid_type');
        }

        $id   = (int) ($_POST['id'] ?? 0);
        $data = [
            'name'           => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'code'           => sanitize_text_field(wp_unslash($_POST['code'] ?? '')),
            'sort_order'     => (int) ($_POST['sort_order'] ?? 0),
            'affiliation_id' => (int) ($_POST['affiliation_id'] ?? 0),
        ];

        $result = $id > 0
            ? MasterRepository::update($type, $id, $data)
            : MasterRepository::insert($type, $data);

        if (is_wp_error($result)) {
            self::redirect($tab, 'error', $result->get_error_message());
        }
        self::redirect($tab, 'success', $id > 0 ? 'updated' : 'created');
    }

    public static function handle_state(): void
    {
        self::guard();
        check_admin_referer('ims_master_state');

        $type   = sanitize_key($_POST['master_type'] ?? '');
        $tab    = sanitize_key($_POST['tab'] ?? 'affiliation_info');
        $id     = (int) ($_POST['id'] ?? 0);
        $action = sanitize_key($_POST['state_action'] ?? '');

        if (!MasterRepository::is_valid_type($type) || $id <= 0) {
            self::redirect($tab, 'error', 'invalid_request');
        }

        switch ($action) {
            case 'stop':
                MasterRepository::set_active($type, $id, false);
                self::redirect($tab, 'success', 'stopped');
                break;
            case 'resume':
                MasterRepository::set_active($type, $id, true);
                self::redirect($tab, 'success', 'resumed');
                break;
            case 'delete':
                if (MasterRepository::count_members($type, $id) > 0) {
                    self::redirect($tab, 'error', 'has_members');
                }
                MasterRepository::delete($type, $id);
                self::redirect($tab, 'success', 'deleted');
                break;
            default:
                self::redirect($tab, 'error', 'invalid_request');
        }
    }

    // ── 画面描画 ───────────────────────────────────────────

    public static function render(): void
    {
        self::guard();

        $current_tab = sanitize_key($_GET['tab'] ?? 'affiliation_info');
        if (!isset(self::TABS[$current_tab])) {
            $current_tab = 'affiliation_info';
        }
        ?>
        <div class="wrap ims-admin">
            <h1>各種マスタ</h1>
            <?php self::render_notice(); ?>

            <h2 class="nav-tab-wrapper">
                <?php foreach (self::TABS as $tab_key => $tab) : ?>
                    <a href="<?php echo esc_url(add_query_arg(['page' => self::MENU_SLUG, 'tab' => $tab_key], admin_url('admin.php'))); ?>"
                       class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($tab['label']); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <div class="ims-tab-body">
                <?php foreach (self::TABS[$current_tab]['types'] as $type) : ?>
                    <?php self::render_section($type, $current_tab); ?>
                <?php endforeach; ?>

                <?php if ($current_tab === 'allowance') : ?>
                    <p class="ims-note">ここでは手当の種類を登録します。各社員への金額設定は社員編集画面から行ってください。</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * 1マスタ種別のセクション（左：一覧 / 右：追加・編集フォーム）を描画する。
     */
    private static function render_section(string $type, string $tab): void
    {
        $config    = MasterRepository::config($type);
        $label     = MasterRepository::label($type);
        $rows      = MasterRepository::all($type, true);
        $has_code  = $config['code_col'] !== null;
        $has_aff   = in_array('affiliation_id', $config['extra'], true);

        // 編集対象の判定（?edit=type:id）
        $edit_row = null;
        $edit_param = sanitize_text_field($_GET['edit'] ?? '');
        if ($edit_param !== '' && str_starts_with($edit_param, $type . ':')) {
            $edit_id  = (int) substr($edit_param, strlen($type) + 1);
            $edit_row = MasterRepository::find($type, $edit_id);
        }

        // 部署の所属ドロップダウン用
        $affiliations = $has_aff ? MasterRepository::all('affiliation', false) : [];
        $aff_names    = [];
        if ($has_aff) {
            foreach (MasterRepository::all('affiliation', true) as $a) {
                $aff_names[(int) $a['id']] = $a['name'];
            }
        }
        ?>
        <div class="ims-master-section">
            <h3><?php echo esc_html($label); ?></h3>
            <div class="ims-2col">
                <div class="ims-list-col">
                    <table class="wp-list-table widefat striped">
                        <thead>
                            <tr>
                                <?php if ($has_code) : ?><th>コード</th><?php endif; ?>
                                <th>名称</th>
                                <?php if ($has_aff) : ?><th>所属</th><?php endif; ?>
                                <th class="col-sort">表示順</th>
                                <th class="col-state">状態</th>
                                <th class="col-ops">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)) : ?>
                                <tr><td colspan="6" class="ims-empty">未登録です。</td></tr>
                            <?php endif; ?>
                            <?php foreach ($rows as $row) :
                                $rid    = (int) $row['id'];
                                $active = (int) $row['is_active'] === 1;
                                $name   = $row[$config['name_col']] ?? '';
                                ?>
                                <tr class="<?php echo $active ? '' : 'ims-row-inactive'; ?>">
                                    <?php if ($has_code) : ?>
                                        <td><?php echo esc_html($row[$config['code_col']] ?? ''); ?></td>
                                    <?php endif; ?>
                                    <td><?php echo esc_html($name); ?></td>
                                    <?php if ($has_aff) : ?>
                                        <td><?php echo esc_html($aff_names[(int) ($row['affiliation_id'] ?? 0)] ?? '—'); ?></td>
                                    <?php endif; ?>
                                    <td class="col-sort"><?php echo (int) $row['sort_order']; ?></td>
                                    <td class="col-state">
                                        <?php if ($active) : ?>
                                            <span class="ims-chip ims-chip-on">有効</span>
                                        <?php else : ?>
                                            <span class="ims-chip ims-chip-off">停止中</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-ops">
                                        <a class="button button-small"
                                           href="<?php echo esc_url(add_query_arg(['page' => self::MENU_SLUG, 'tab' => $tab, 'edit' => $type . ':' . $rid], admin_url('admin.php'))); ?>#section-<?php echo esc_attr($type); ?>">編集</a>
                                        <?php self::render_state_button($type, $rid, $active, $tab); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="ims-form-col" id="section-<?php echo esc_attr($type); ?>">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ims-master-form">
                        <?php wp_nonce_field('ims_save_master'); ?>
                        <input type="hidden" name="action" value="ims_save_master">
                        <input type="hidden" name="master_type" value="<?php echo esc_attr($type); ?>">
                        <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">
                        <input type="hidden" name="id" value="<?php echo esc_attr($edit_row['id'] ?? ''); ?>">

                        <h4><?php echo $edit_row ? esc_html($label . 'を編集') : esc_html($label . 'を追加'); ?></h4>

                        <?php if ($has_code) : ?>
                            <p>
                                <label class="ims-label">コード <span class="ims-req">*</span></label>
                                <input type="text" name="code" class="regular-text"
                                       value="<?php echo esc_attr($edit_row[$config['code_col']] ?? ''); ?>" required>
                            </p>
                        <?php endif; ?>

                        <p>
                            <label class="ims-label">名称 <span class="ims-req">*</span></label>
                            <input type="text" name="name" class="regular-text"
                                   value="<?php echo esc_attr($edit_row[$config['name_col']] ?? ''); ?>" required>
                        </p>

                        <?php if ($has_aff) : ?>
                            <p>
                                <label class="ims-label">所属</label>
                                <select name="affiliation_id" class="regular-text">
                                    <option value="0">（未指定）</option>
                                    <?php foreach ($affiliations as $a) : ?>
                                        <option value="<?php echo (int) $a['id']; ?>"
                                            <?php selected((int) ($edit_row['affiliation_id'] ?? 0), (int) $a['id']); ?>>
                                            <?php echo esc_html($a['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </p>
                        <?php endif; ?>

                        <p>
                            <label class="ims-label">表示順</label>
                            <input type="number" name="sort_order" class="small-text"
                                   value="<?php echo esc_attr($edit_row['sort_order'] ?? 0); ?>">
                        </p>

                        <p class="ims-form-actions">
                            <button type="submit" class="button button-primary">
                                <?php echo $edit_row ? '更新する' : '追加する'; ?>
                            </button>
                            <?php if ($edit_row) : ?>
                                <a class="button" href="<?php echo esc_url(add_query_arg(['page' => self::MENU_SLUG, 'tab' => $tab], admin_url('admin.php'))); ?>">キャンセル</a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    private static function render_state_button(string $type, int $id, bool $active, string $tab): void
    {
        $members = MasterRepository::count_members($type, $id);
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ims-inline-form"
              <?php if ($active && $members > 0) : ?>
              onsubmit="return confirm('この項目は在籍社員 <?php echo (int) $members; ?> 名が参照しています。停止してよろしいですか？（既存社員のデータは残ります）');"
              <?php elseif (!$active) : ?>
              onsubmit="return true;"
              <?php endif; ?>>
            <?php wp_nonce_field('ims_master_state'); ?>
            <input type="hidden" name="action" value="ims_master_state">
            <input type="hidden" name="master_type" value="<?php echo esc_attr($type); ?>">
            <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">
            <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
            <?php if ($active) : ?>
                <input type="hidden" name="state_action" value="stop">
                <button type="submit" class="button button-small ims-btn-stop">停止</button>
            <?php else : ?>
                <input type="hidden" name="state_action" value="resume">
                <button type="submit" class="button button-small">再開</button>
                <?php if ($members === 0) : ?>
                    <button type="submit" class="button button-small ims-btn-del"
                            formaction="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                            onclick="this.form.state_action.value='delete';return confirm('完全に削除します。よろしいですか？');">削除</button>
                <?php endif; ?>
            <?php endif; ?>
        </form>
        <?php
    }

    private static function render_notice(): void
    {
        $status = sanitize_key($_GET['ims_notice'] ?? '');
        if ($status === '') {
            return;
        }
        $msg = sanitize_text_field(wp_unslash($_GET['ims_msg'] ?? ''));

        $labels = [
            'created' => 'マスタを追加しました。',
            'updated' => 'マスタを更新しました。',
            'stopped' => '項目を停止しました。',
            'resumed' => '項目を再開しました。',
            'deleted' => '項目を削除しました。',
            'has_members' => '在籍社員が参照しているため削除できません。先に停止してください。',
            'invalid_type' => '不正なマスタ種別です。',
            'invalid_request' => '不正なリクエストです。',
        ];
        $text  = $labels[$msg] ?? $msg;
        $class = $status === 'success' ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($text) . '</p></div>';
    }

    private static function guard(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('この操作を行う権限がありません。', 'ims-portal'));
        }
    }

    private static function redirect(string $tab, string $status, string $msg): void
    {
        wp_safe_redirect(add_query_arg([
            'page'       => self::MENU_SLUG,
            'tab'        => $tab,
            'ims_notice' => $status,
            'ims_msg'    => rawurlencode($msg),
        ], admin_url('admin.php')));
        exit;
    }
}
