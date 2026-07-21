<?php

declare(strict_types=1);

namespace IMS\Module\User;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 「社員管理」トップ = 社員一覧画面（01_user_management.md §5.1.1）。
 *
 * ・列：社員番号 / 氏名 / 所属・部署 / 在籍状況 / ログイン方法 / 操作
 * ・退職者はデフォルト除外。「退職者も含む」チェックで表示切替。
 * ・編集は WordPress 標準のユーザー編集画面（user-edit.php）へ遷移し、そこに
 *   AdminUserFields が業務カードを差し込む（コアのメール・氏名・パスワード管理を活用）。
 *
 * 親メニュー（社員管理）はこのクラスが登録し、各種マスタ・退職者一覧は
 * それぞれのクラスがサブメニューとして追加する。
 */
final class AdminUserListPage
{
    public const PARENT_SLUG = 'ims-employees';
    private const CAP        = 'ims_manage_users';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu'], 9); // 親を先に登録
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function register_menu(): void
    {
        add_menu_page(
            '社員管理',
            '社員管理',
            self::CAP,
            self::PARENT_SLUG,
            [self::class, 'render'],
            'dashicons-groups',
            26
        );
        // 親スラッグと同一スラッグのサブメニューを追加し、ラベルを「社員一覧」にする
        add_submenu_page(
            self::PARENT_SLUG,
            '社員一覧',
            '社員一覧',
            self::CAP,
            self::PARENT_SLUG,
            [self::class, 'render']
        );
    }

    public static function enqueue(string $hook): void
    {
        if (!str_contains($hook, self::PARENT_SLUG)) {
            return;
        }
        wp_enqueue_style('ims-portal-tokens', IMS_PORTAL_URL . 'assets/css/ims-tokens.css', [], IMS_PORTAL_VERSION);
        wp_enqueue_style('ims-admin', IMS_PORTAL_URL . 'assets/css/admin.css', ['ims-portal-tokens'], IMS_PORTAL_VERSION);
    }

    public static function render(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('この操作を行う権限がありません。', 'ims-portal'));
        }

        $include_retired = !empty($_GET['include_retired']);
        $employees = EmployeeRepository::list_employees($include_retired);
        ?>
        <div class="wrap ims-admin">
            <h1 class="wp-heading-inline">社員一覧</h1>
            <a href="<?php echo esc_url(admin_url('user-new.php')); ?>" class="page-title-action">新規社員を追加</a>
            <hr class="wp-header-end">

            <form method="get" class="ims-list-filter">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PARENT_SLUG); ?>">
                <label>
                    <input type="checkbox" name="include_retired" value="1" <?php checked($include_retired); ?>
                        onchange="this.form.submit()">
                    退職者も含む
                </label>
                <span class="ims-count"><?php echo count($employees); ?> 名</span>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>社員番号</th>
                        <th>氏名</th>
                        <th>所属・部署</th>
                        <th>在籍状況</th>
                        <th>ログイン方法</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($employees)) : ?>
                        <tr><td colspan="6" class="ims-empty">該当する社員がいません。</td></tr>
                    <?php endif; ?>
                    <?php foreach ($employees as $u) :
                        $code   = get_user_meta($u->ID, 'employee_code', true);
                        $status = get_user_meta($u->ID, 'employment_status', true) ?: '在籍';
                        $auth   = get_user_meta($u->ID, 'auth_type', true) === 'google_sso' ? 'google_sso' : 'password';
                        $retired = $status === '退職';
                        ?>
                        <tr class="<?php echo $retired ? 'ims-row-inactive' : ''; ?>">
                            <td><?php echo esc_html($code !== '' ? $code : '—'); ?></td>
                            <td><strong><?php echo esc_html($u->display_name); ?></strong><br>
                                <span class="ims-sub"><?php echo esc_html($u->user_email); ?></span></td>
                            <td><?php echo esc_html(EmployeeRepository::org_label($u->ID)); ?></td>
                            <td>
                                <?php if ($retired) : ?>
                                    <span class="ims-chip ims-chip-off"><?php echo esc_html($status); ?></span>
                                <?php else : ?>
                                    <span class="ims-chip ims-chip-on"><?php echo esc_html($status); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($auth === 'google_sso') : ?>
                                    <span class="ims-auth-badge ims-auth-google">Google</span>
                                <?php else : ?>
                                    <span class="ims-auth-badge ims-auth-pw">ID・パスワード</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="button button-small" href="<?php echo esc_url(get_edit_user_link($u->ID)); ?>">編集</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
