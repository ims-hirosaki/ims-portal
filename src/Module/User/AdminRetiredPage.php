<?php

declare(strict_types=1);

namespace IMS\Module\User;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 退職者一覧・退職/復職処理（01_user_management.md §3.5 / §5.1.4）。
 *
 * ・メニュー：社員管理 > 退職者一覧
 * ・退職処理は「社員編集画面のアカウント停止カード」から実行（EmployeeRepository::retire）。
 *   物理削除は行わず、employment_status=退職・セッション破棄・(password)パスワード無効化。
 * ・退職者一覧では、社員番号/氏名/所属/部署/退職処理日/ログイン方法 を表示し、復職できる。
 * ・過去データ（勤怠・交通費・申請）へのリンクは各モジュール（02/03/04/05）実装後に追加。
 */
final class AdminRetiredPage
{
    private const CAP       = 'ims_manage_users';
    private const MENU_SLUG = 'ims-retired-employees';
    private const NONCE     = 'ims_retired_nonce';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu'], 12);
        add_action('admin_post_ims_retire_user', [self::class, 'handle_retire']);
        add_action('admin_post_ims_reinstate_user', [self::class, 'handle_reinstate']);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            AdminUserListPage::PARENT_SLUG, // 社員管理
            '退職者一覧',
            '退職者一覧',
            self::CAP,
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

    public static function page_url(): string
    {
        return admin_url('admin.php?page=' . self::MENU_SLUG);
    }

    // ── ハンドラ ───────────────────────────────────────────

    public static function handle_retire(): void
    {
        self::guard();
        $user_id = (int) ($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
        check_admin_referer('ims_retire_' . $user_id);

        if ($user_id === get_current_user_id()) {
            wp_die(esc_html__('自分自身を退職処理することはできません。', 'ims-portal'), '', ['response' => 400]);
        }
        if ($user_id > 0) {
            EmployeeRepository::retire($user_id, get_current_user_id());
        }
        wp_safe_redirect(add_query_arg('retired', '1', get_edit_user_link($user_id)));
        exit;
    }

    public static function handle_reinstate(): void
    {
        self::guard();
        $user_id = (int) ($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
        check_admin_referer('ims_reinstate_' . $user_id);

        if ($user_id > 0) {
            EmployeeRepository::reinstate($user_id);
        }
        wp_safe_redirect(add_query_arg('reinstated', '1', self::page_url()));
        exit;
    }

    private static function guard(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('この操作を行う権限がありません。', 'ims-portal'), '', ['response' => 403]);
        }
    }

    // ── 社員編集画面のアカウント停止カード（AdminUserFields から呼ばれる） ──

    public static function account_control_card(\WP_User $user): void
    {
        if (!current_user_can(self::CAP)) {
            return;
        }
        $uid       = $user->ID;
        $retired   = EmployeeRepository::is_retired($uid);
        $is_self   = ($uid === get_current_user_id());
        $auth_type = get_user_meta($uid, 'auth_type', true);
        ?>
        <div class="ims-user-card">
            <h2>アカウント停止（退職処理）</h2>
            <?php if ($retired) : ?>
                <p class="ims-sub">
                    このアカウントは<strong>退職済み</strong>です（退職処理日：<?php echo esc_html((string) (get_user_meta($uid, 'retired_at', true) ?: '—')); ?>）。<br>
                    ログイン・ポータルアクセスは遮断されています。過去データは読み取り専用で保持されています。
                </p>
                <a href="<?php echo esc_url(self::reinstate_url($uid)); ?>"
                   class="button"
                   onclick="return confirm('このアカウントを復職（在籍に戻す）します。よろしいですか？');">復職させる（在籍に戻す）</a>
                <?php if ($auth_type === 'password') : ?>
                    <p class="ims-sub" style="margin-top:8px;">※ 復職後、この社員はパスワード認証です。退職時にパスワードが無効化されているため、別途「新しいパスワードを設定」してください。</p>
                <?php endif; ?>
            <?php elseif ($is_self) : ?>
                <p class="ims-sub">自分自身のアカウントは退職処理できません。</p>
            <?php else : ?>
                <p class="ims-sub">
                    退職処理を実行すると、物理削除はせずにアカウントを停止します：<br>
                    ・在籍状況を「退職」に変更し、退職処理日を記録<br>
                    ・全端末のログインセッションを即時破棄<br>
                    <?php if ($auth_type === 'password') : ?>・パスワードを無効化（再ログイン不可）<?php else : ?>・Google側アカウントの停止も別途推奨（二重ロック）<?php endif; ?>
                </p>
                <a href="<?php echo esc_url(self::retire_url($uid)); ?>"
                   class="button button-link-delete"
                   style="color:#a00;"
                   onclick="return confirm('この社員を退職処理します。ログインが即時に無効化されます。よろしいですか？');">退職にする</a>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function retire_url(int $user_id): string
    {
        return wp_nonce_url(
            admin_url('admin-post.php?action=ims_retire_user&user_id=' . $user_id),
            'ims_retire_' . $user_id
        );
    }

    private static function reinstate_url(int $user_id): string
    {
        // 復職は admin-post に POST 相当のパラメータを nonce 付き GET で渡す
        return wp_nonce_url(
            admin_url('admin-post.php?action=ims_reinstate_user&user_id=' . $user_id),
            'ims_reinstate_' . $user_id
        );
    }

    // ── 退職者一覧画面 ─────────────────────────────────────

    public static function render(): void
    {
        self::guard();
        $retired = EmployeeRepository::list_retired();
        ?>
        <div class="wrap ims-admin">
            <h1 class="wp-heading-inline">退職者一覧</h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=' . AdminUserListPage::PARENT_SLUG)); ?>" class="page-title-action">在籍者一覧へ</a>
            <hr class="wp-header-end">

            <?php if (!empty($_GET['reinstated'])) : ?>
                <div class="notice notice-success is-dismissible"><p>復職処理を行いました（在籍に戻しました）。</p></div>
            <?php endif; ?>

            <p class="ims-sub">退職者は物理削除されず、ここに保管されます。ログイン・ポータルアクセスは遮断されています。</p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:110px;">社員番号</th>
                        <th>氏名</th>
                        <th>所属・部署</th>
                        <th style="width:120px;">退職処理日</th>
                        <th style="width:120px;">ログイン方法</th>
                        <th style="width:200px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($retired)) : ?>
                        <tr><td colspan="6" class="ims-empty">退職者はいません。</td></tr>
                    <?php endif; ?>
                    <?php foreach ($retired as $u) :
                        $uid  = $u->ID;
                        $auth = get_user_meta($uid, 'auth_type', true);
                        ?>
                        <tr>
                            <td><?php echo esc_html((string) (get_user_meta($uid, 'employee_code', true) ?: '—')); ?></td>
                            <td>
                                <strong><?php echo esc_html($u->display_name); ?></strong><br>
                                <span class="ims-sub"><?php echo esc_html($u->user_email); ?></span>
                            </td>
                            <td><?php echo esc_html(EmployeeRepository::org_label($uid)); ?></td>
                            <td><?php echo esc_html((string) (get_user_meta($uid, 'retired_at', true) ?: '—')); ?></td>
                            <td><?php echo $auth === 'google_sso' ? 'Googleアカウント' : 'パスワード'; ?></td>
                            <td>
                                <a class="button button-small" href="<?php echo esc_url(get_edit_user_link($uid)); ?>">詳細</a>
                                <a class="button button-small button-primary"
                                   href="<?php echo esc_url(self::reinstate_url($uid)); ?>"
                                   onclick="return confirm('このアカウントを復職（在籍に戻す）します。よろしいですか？');">復職</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="ims-sub" style="margin-top:12px;">※ 退職者の過去の勤怠・交通費・申請データへの読み取り専用リンクは、各モジュール（勤怠／交通費／申請）実装後にこの一覧へ追加します。</p>
        </div>
        <?php
    }
}
