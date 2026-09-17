<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

use IMS\Module\User\AdminUserListPage;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 「社員管理 > 事業マスタ」wp-admin画面（03_attendance_management.md §2.3 準拠）。
 *
 * ・左：一覧、右：追加・編集フォームの2カラム（Module\User\AdminMastersPage と同じ画面構成）。
 * ・保存はサーバーサイド（admin-post.php）でnonce検証 → PRGパターンでリダイレクト。
 * ・「有給日のデフォルト割当先」は必ず1件のみになるよう、専用チェックボックスで管理する
 *   （§2.3「必ず1件のみ1を設定できる」）。0件になる操作（デフォルトを外すだけの保存・
 *   デフォルト行の停止・削除）はブロックする。
 *
 * 親メニュー（社員管理）は Module\User\AdminUserListPage が登録済みのため、
 * ここではサブメニューとして追加するだけで User モジュール側は無改修。
 *
 * 権限：ims_manage_masters（hr_admin 以上）。
 */
final class AdminBusinessesPage
{
    private const MENU_SLUG   = 'ims-businesses';
    private const CAP         = 'ims_manage_masters';
    private const NONCE_SAVE  = 'ims_save_business';
    private const NONCE_STATE = 'ims_business_state';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_post_ims_save_business', [self::class, 'handle_save']);
        add_action('admin_post_ims_business_state', [self::class, 'handle_state']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            AdminUserListPage::PARENT_SLUG,
            '事業マスタ',
            '事業マスタ',
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
        // 一覧・フォームは Module\User\AdminMastersPage と同じ共有クラス（ims-master-section等）を
        // そのまま流用するため、本画面専用のCSSファイルは追加しない。
        wp_enqueue_style('ims-portal-tokens', IMS_PORTAL_URL . 'assets/css/ims-tokens.css', [], IMS_PORTAL_VERSION);
        wp_enqueue_style('ims-admin', IMS_PORTAL_URL . 'assets/css/admin.css', ['ims-portal-tokens'], IMS_PORTAL_VERSION);
    }

    // ── フォーム処理（admin-post.php） ─────────────────────────

    public static function handle_save(): void
    {
        self::guard();
        check_admin_referer(self::NONCE_SAVE);

        $id   = (int) ($_POST['business_id'] ?? 0);
        $data = [
            'business_code' => sanitize_text_field(wp_unslash($_POST['business_code'] ?? '')),
            'business_name' => sanitize_text_field(wp_unslash($_POST['business_name'] ?? '')),
            'color_code'    => sanitize_text_field(wp_unslash($_POST['color_code'] ?? '')),
            'client_name'   => sanitize_text_field(wp_unslash($_POST['client_name'] ?? '')),
            'sort_order'    => (int) ($_POST['sort_order'] ?? 0),
        ];
        $make_default = !empty($_POST['is_default_for_paid_leave']);

        // デフォルトを外すだけの保存は禁止する（0件になるのを防ぐ。§2.3）
        if (!$make_default && $id > 0) {
            $current = BusinessRepository::find($id);
            if ($current !== null && (int) $current['is_default_for_paid_leave'] === 1) {
                self::redirect('error', 'default_required');
            }
        }

        $result = $id > 0
            ? BusinessRepository::update($id, $data)
            : BusinessRepository::insert($data);

        if (is_wp_error($result)) {
            self::redirect('error', $result->get_error_message());
        }

        if ($make_default) {
            BusinessRepository::set_default_for_paid_leave($id > 0 ? $id : (int) $result);
        }

        self::redirect('success', $id > 0 ? 'updated' : 'created');
    }

    public static function handle_state(): void
    {
        self::guard();
        check_admin_referer(self::NONCE_STATE);

        $id     = (int) ($_POST['business_id'] ?? 0);
        $action = sanitize_key($_POST['state_action'] ?? '');
        if ($id <= 0) {
            self::redirect('error', 'invalid_request');
        }

        $business = BusinessRepository::find($id);
        $is_default = $business !== null && (int) $business['is_default_for_paid_leave'] === 1;

        switch ($action) {
            case 'stop':
                if ($is_default) {
                    self::redirect('error', 'default_required');
                }
                BusinessRepository::set_active($id, false);
                self::redirect('success', 'stopped');
                break;
            case 'resume':
                BusinessRepository::set_active($id, true);
                self::redirect('success', 'resumed');
                break;
            case 'delete':
                if ($is_default) {
                    self::redirect('error', 'default_required');
                }
                if (BusinessRepository::pending_reference_count($id) > 0) {
                    self::redirect('error', 'has_references');
                }
                BusinessRepository::delete($id);
                self::redirect('success', 'deleted');
                break;
            default:
                self::redirect('error', 'invalid_request');
        }
    }

    // ── 画面描画 ───────────────────────────────────────────

    public static function render(): void
    {
        self::guard();

        $rows = BusinessRepository::all(true);

        $edit_row = null;
        $edit_id  = (int) ($_GET['edit'] ?? 0);
        if ($edit_id > 0) {
            $edit_row = BusinessRepository::find($edit_id);
        }
        ?>
        <div class="wrap ims-admin">
            <h1>事業マスタ</h1>
            <p class="ims-note">
                事業マスタは、勤怠管理（本モジュール）だけでなく交通費・車両借上げ・稟議の各モジュールも参照する共通マスターです。
                「有給日のデフォルト割当先」は必ず1件だけ設定してください。
            </p>
            <?php self::render_notice(); ?>

            <div class="ims-master-section">
                <div class="ims-2col">
                    <div class="ims-list-col">
                        <table class="wp-list-table widefat striped">
                            <thead>
                                <tr>
                                    <th>コード</th>
                                    <th>事業名</th>
                                    <th>カラー</th>
                                    <th>委託元</th>
                                    <th>有給デフォルト</th>
                                    <th class="col-sort">表示順</th>
                                    <th class="col-state">状態</th>
                                    <th class="col-ops">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($rows)) : ?>
                                    <tr><td colspan="8" class="ims-empty">未登録です。</td></tr>
                                <?php endif; ?>
                                <?php foreach ($rows as $row) :
                                    $rid       = (int) $row['business_id'];
                                    $active    = (int) $row['is_active'] === 1;
                                    $default   = (int) $row['is_default_for_paid_leave'] === 1;
                                    ?>
                                    <tr class="<?php echo $active ? '' : 'ims-row-inactive'; ?>">
                                        <td><?php echo esc_html($row['business_code']); ?></td>
                                        <td><?php echo esc_html($row['business_name']); ?></td>
                                        <td>
                                            <span class="ims-chip" style="background:<?php echo esc_attr($row['color_code']); ?>;color:#fff;">&nbsp;&nbsp;&nbsp;</span>
                                            <?php echo esc_html($row['color_code']); ?>
                                        </td>
                                        <td><?php echo esc_html($row['client_name'] ?? '—'); ?></td>
                                        <td>
                                            <?php if ($default) : ?>
                                                <span class="ims-chip ims-chip-on">デフォルト</span>
                                            <?php endif; ?>
                                        </td>
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
                                               href="<?php echo esc_url(add_query_arg(['page' => self::MENU_SLUG, 'edit' => $rid], admin_url('admin.php'))); ?>#business-form">編集</a>
                                            <?php self::render_state_button($rid, $active, $default); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="ims-form-col" id="business-form">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ims-master-form">
                            <?php wp_nonce_field(self::NONCE_SAVE); ?>
                            <input type="hidden" name="action" value="ims_save_business">
                            <input type="hidden" name="business_id" value="<?php echo esc_attr((string) ($edit_row['business_id'] ?? '')); ?>">

                            <h4><?php echo $edit_row ? '事業を編集' : '事業を追加'; ?></h4>

                            <p>
                                <label class="ims-label">事業コード <span class="ims-req">*</span></label>
                                <input type="text" name="business_code" class="regular-text"
                                       value="<?php echo esc_attr($edit_row['business_code'] ?? ''); ?>" required>
                            </p>

                            <p>
                                <label class="ims-label">事業名 <span class="ims-req">*</span></label>
                                <input type="text" name="business_name" class="regular-text"
                                       value="<?php echo esc_attr($edit_row['business_name'] ?? ''); ?>" required>
                            </p>

                            <p>
                                <label class="ims-label">表示カラー <span class="ims-req">*</span></label>
                                <input type="color" name="color_code"
                                       value="<?php echo esc_attr($edit_row['color_code'] ?? '#1E3A5F'); ?>">
                            </p>

                            <p>
                                <label class="ims-label">委託元名</label>
                                <input type="text" name="client_name" class="regular-text"
                                       value="<?php echo esc_attr($edit_row['client_name'] ?? ''); ?>">
                            </p>

                            <p>
                                <label class="ims-label">表示順</label>
                                <input type="number" name="sort_order" class="small-text"
                                       value="<?php echo esc_attr((string) ($edit_row['sort_order'] ?? 0)); ?>">
                            </p>

                            <p>
                                <label>
                                    <input type="checkbox" name="is_default_for_paid_leave" value="1"
                                        <?php checked((int) ($edit_row['is_default_for_paid_leave'] ?? 0), 1); ?>>
                                    有給日のデフォルト割当先にする
                                </label>
                                <br><span class="ims-sub">チェックすると他の事業のデフォルト設定は自動的に解除されます。必ずどれか1件は設定してください。</span>
                            </p>

                            <p class="ims-form-actions">
                                <button type="submit" class="button button-primary">
                                    <?php echo $edit_row ? '更新する' : '追加する'; ?>
                                </button>
                                <?php if ($edit_row) : ?>
                                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>">キャンセル</a>
                                <?php endif; ?>
                            </p>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private static function render_state_button(int $id, bool $active, bool $is_default): void
    {
        $refs = BusinessRepository::pending_reference_count($id);
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ims-inline-form"
              <?php if ($active && !$is_default) : ?>
              onsubmit="return confirm('<?php echo $refs > 0 ? esc_js("この事業には当月以降の未確定な事業別時間実績が {$refs} 件あります。停止してよろしいですか？（既存データは残ります）") : esc_js('この事業を停止してよろしいですか？'); ?>');"
              <?php endif; ?>>
            <?php wp_nonce_field(self::NONCE_STATE); ?>
            <input type="hidden" name="action" value="ims_business_state">
            <input type="hidden" name="business_id" value="<?php echo (int) $id; ?>">
            <?php if ($is_default) : ?>
                <span class="ims-sub">デフォルト設定中は操作できません</span>
            <?php elseif ($active) : ?>
                <input type="hidden" name="state_action" value="stop">
                <button type="submit" class="button button-small ims-btn-stop">停止</button>
            <?php else : ?>
                <input type="hidden" name="state_action" value="resume">
                <button type="submit" class="button button-small">再開</button>
                <?php if ($refs === 0) : ?>
                    <button type="submit" class="button button-small ims-btn-del"
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
            'created'          => '事業を追加しました。',
            'updated'          => '事業を更新しました。',
            'stopped'          => '事業を停止しました。',
            'resumed'          => '事業を再開しました。',
            'deleted'          => '事業を削除しました。',
            'has_references'   => 'この事業を参照する事業別時間実績が残っているため削除できません。先に停止してください。',
            'default_required' => '有給日のデフォルト割当先は必ず1件必要です。先に別の事業をデフォルトに設定してから行ってください。',
            'invalid_request'  => '不正なリクエストです。',
        ];
        $text  = $labels[$msg] ?? $msg;
        $class = $status === 'success' ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($text) . '</p></div>';
    }

    private static function guard(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('この操作を行う権限がありません。', 'ims-portal'), '', ['response' => 403]);
        }
    }

    private static function redirect(string $status, string $msg): void
    {
        wp_safe_redirect(add_query_arg([
            'page'       => self::MENU_SLUG,
            'ims_notice' => $status,
            'ims_msg'    => rawurlencode($msg),
        ], admin_url('admin.php')));
        exit;
    }
}
