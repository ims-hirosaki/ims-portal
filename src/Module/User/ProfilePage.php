<?php

declare(strict_types=1);

namespace IMS\Module\User;

use IMS\Core\Layout;
use IMS\Support\UserRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * マイページ `/portal/profile/`（01_user_management.md §5.2.2 準拠）。
 *
 * ・自分の基本情報を「読み取り専用」で表示するだけの画面。変更フォームは持たない。
 * ・表示項目：氏名 / 社員番号 / 所属 / 部署 / 役職 / 職種 / 雇用形態 /
 *   在籍状況 / 担当上長（確認者） / 最終承認者。
 * ・給与・手当情報は一切表示しない（要件で明示）。→ 3.3③ / 5.2.2
 * ・変更が必要な場合は人事部へ連絡するよう案内テキストを出す。
 *
 * core を改修せず、以下のフックで自己登録する：
 * ・ims_portal_register_page … /portal/profile/ の登録
 * ・ims_portal_register_tile … ダッシュボードの「マイページ」タイル（→ 5.2.3）
 */
final class ProfilePage
{
    private const SLUG = 'profile';

    public static function init(): void
    {
        // ページ登録（Router 経由）
        add_action('ims_portal_register_page', static function (string $router_class): void {
            $router_class::register(self::SLUG, self::class);
        });

        // ダッシュボードのメニュータイル登録（マイページはこのモジュールの責務）
        add_action('ims_portal_register_tile', static function (string $registry_class): void {
            $registry_class::add([
                'id'       => 'profile',
                'label'    => __('マイページ', 'ims-portal'),
                'icon'     => 'user',
                'url'      => '/portal/' . self::SLUG . '/',
                'desc'     => __('プロフィール確認', 'ims-portal'),
                'priority' => 90, // 各機能タイルの後ろ側に置く
                'caps'     => ['ims_use_portal'],
            ]);
        });
    }

    public static function render(): void
    {
        $user_id = get_current_user_id();
        $user    = wp_get_current_user();

        $display_name = $user->display_name !== '' ? $user->display_name : $user->user_login;

        // 基本情報
        $employee_code     = UserRepository::get_employee_code($user_id);
        $auth_type         = UserRepository::get_auth_type($user_id);
        $employment_status = UserRepository::get_employment_status($user_id);

        // 所属系（マスタ ID → 名称）
        $affiliation     = self::master_name($user_id, 'affiliation');
        $department      = self::master_name($user_id, 'department');
        $position        = self::master_name($user_id, 'position');
        $job_type        = self::master_name($user_id, 'job_type');
        $employment_type = self::master_name($user_id, 'employment_type');

        // 承認者（ユーザー ID → 表示名）
        $first_approver = self::user_display_name(UserRepository::get_first_approver_id($user_id));
        $final_approver = self::user_display_name(UserRepository::get_final_approver_id($user_id));

        // タグ
        [$auth_label, $auth_class]     = self::auth_tag($auth_type);
        [$status_label, $status_class] = self::status_tag($employment_status);

        $avatar_initial = mb_substr($display_name, 0, 1);

        Layout::render_header(__('マイページ', 'ims-portal'));
        ?>
        <div class="mypage-wrap">
            <div class="profile-header">
                <div class="profile-avatar"><?php echo esc_html($avatar_initial); ?></div>
                <div>
                    <div class="profile-name"><?php echo esc_html($display_name); ?></div>
                    <div class="profile-meta">
                        <?php
                        /* translators: %s: 社員番号 */
                        printf(esc_html__('社員番号：%s', 'ims-portal'), esc_html($employee_code !== '' ? $employee_code : '—'));
                        ?>
                    </div>
                    <div class="profile-tags">
                        <span class="tag <?php echo esc_attr($auth_class); ?>"><?php echo esc_html($auth_label); ?></span>
                        <span class="tag <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></span>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2><?php esc_html_e('基本情報', 'ims-portal'); ?></h2></div>
                <div class="card-body">
                    <div class="info-grid">
                        <?php
                        $items = [
                            __('氏名', 'ims-portal')             => esc_html($display_name),
                            __('社員番号', 'ims-portal')         => esc_html($employee_code !== '' ? $employee_code : '—'),
                            __('所属', 'ims-portal')             => esc_html($affiliation),
                            __('部署', 'ims-portal')             => esc_html($department),
                            __('役職', 'ims-portal')             => esc_html($position),
                            __('職種', 'ims-portal')             => esc_html($job_type),
                            __('雇用形態', 'ims-portal')         => esc_html($employment_type),
                            __('在籍状況', 'ims-portal')         => '<span class="tag ' . esc_attr($status_class) . '">' . esc_html($status_label) . '</span>',
                            __('担当上長（確認者）', 'ims-portal') => esc_html($first_approver),
                            __('最終承認者', 'ims-portal')       => esc_html($final_approver),
                        ];
                        foreach ($items as $label => $value_html) :
                            ?>
                            <div class="info-item">
                                <div class="info-label"><?php echo esc_html($label); ?></div>
                                <div class="info-value"><?php echo $value_html; // 各値は上で esc 済み ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="notice-box">
                        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="8.5"/><path d="M12 11v5M12 8.2v.2"/>
                        </svg>
                        <span><?php esc_html_e('内容の変更が必要な場合は、担当の管理者（人事部）にご連絡ください。', 'ims-portal'); ?></span>
                    </div>
                </div>
            </div>
        </div>
        <?php
        Layout::render_footer();
    }

    /**
     * マスタ ID（usermeta）から名称を引く。未設定・未解決は「—」。
     */
    private static function master_name(int $user_id, string $type): string
    {
        $config   = MasterRepository::config($type);
        $meta_key = $config['meta_key'] ?? null;
        if ($meta_key === null) {
            return '—';
        }
        $id = (int) get_user_meta($user_id, $meta_key, true);
        if ($id <= 0) {
            return '—';
        }
        $row = MasterRepository::find($type, $id);
        return ($row && isset($row['name']) && $row['name'] !== '') ? (string) $row['name'] : '—';
    }

    /**
     * 承認者ユーザー ID → 表示名。未設定・不明は「—」。
     */
    private static function user_display_name(?int $user_id): string
    {
        if (!$user_id) {
            return '—';
        }
        $u = get_userdata($user_id);
        if (!$u) {
            return '—';
        }
        return $u->display_name !== '' ? $u->display_name : $u->user_login;
    }

    /**
     * ログイン方法タグ（表示ラベル, CSSクラス）。
     * ※ 用語方針：一般社員には「SSO」ではなく「Googleアカウント」と表示する。
     *
     * @return array{0:string,1:string}
     */
    private static function auth_tag(string $auth_type): array
    {
        if ($auth_type === 'google_sso') {
            return [__('Googleアカウント', 'ims-portal'), 'tag-google'];
        }
        return [__('パスワード', 'ims-portal'), 'tag-pw'];
    }

    /**
     * 在籍状況タグ（表示ラベル, CSSクラス）。
     *
     * @return array{0:string,1:string}
     */
    private static function status_tag(string $status): array
    {
        $label = $status !== '' ? $status : __('在籍', 'ims-portal');
        switch ($status) {
            case '退職':
                return [$label, 'tag-retire'];
            case '休職':
            case '育児休業中':
            case '出向':
                return [$label, 'tag-leave'];
            case '在籍':
            default:
                return [$label, 'tag-active'];
        }
    }
}
