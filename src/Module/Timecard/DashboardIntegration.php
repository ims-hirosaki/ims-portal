<?php

declare(strict_types=1);

namespace IMS\Module\Timecard;

use IMS\Core\Router;
use IMS\Support\UserRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ダッシュボード（`/portal/`）連携（2g）。
 *
 * core を改修せず、以下3つのフックで自己登録する：
 * ・ims_portal_register_tile … 「打刻」タイル（00_portal.md §3.2.4）
 * ・ims_portal_summary_contribute … summary API への寄与（§5.3）
 * ・ims_portal_dashboard_top … 「本日の勤務状況」ステータスカード（§3.2.2）
 *
 * 3箇所とも判定の中身は持たず、PunchService::dashboard_summary() を
 * そのまま表示に変換するだけにする（判定を二重実装しない）。
 */
final class DashboardIntegration
{
    public static function init(): void
    {
        add_action('ims_portal_register_tile', [self::class, 'register_tile']);
        add_filter('ims_portal_summary_contribute', [self::class, 'contribute_summary'], 10, 2);
        add_action('ims_portal_dashboard_top', [self::class, 'render_status_card']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
    }

    /**
     * 「打刻」タイル（§3.2.4）。当日未打刻（16時以降）ならバッジを出す。
     */
    public static function register_tile(string $registry_class): void
    {
        $registry_class::add([
            'id'       => 'timecard',
            'label'    => __('打刻', 'ims-portal'),
            'icon'     => 'clock',
            'url'      => '/portal/timecard/',
            'desc'     => __('打刻・実勤務時間の確認', 'ims-portal'),
            'priority' => 10, // §3.2.4 の一覧で最初に挙がっている
            'caps'     => ['ims_use_portal'],
            'badge'    => static function (int $user_id): ?string {
                if (UserRepository::is_retired($user_id)) {
                    return null;
                }
                return PunchService::dashboard_summary($user_id)['missing_timecard'] ? '!' : null;
            },
        ]);
    }

    /**
     * summary API への寄与（§5.3）。`badge_count` はヘッダー🔔バッジの合算対象
     * （SummaryAggregator::handle_badge_count() が全モジュール分を合計する）。
     */
    public static function contribute_summary(array $summary, int $user_id): array
    {
        if (UserRepository::is_retired($user_id)) {
            return $summary;
        }

        $data = PunchService::dashboard_summary($user_id);

        $summary['attendance_status'] = $data['status'];
        $summary['clock_in']          = $data['clock_in'];
        $summary['clock_out']         = $data['clock_out'];
        $summary['missing_timecard']  = $data['missing_timecard'];

        if (!isset($summary['badge_count']) || !is_array($summary['badge_count'])) {
            $summary['badge_count'] = [];
        }
        // §3.3「打刻漏れ通知：出勤前状態のまま16時以降の場合に1件としてカウント」
        $summary['badge_count']['timecard'] = $data['missing_timecard'] ? 1 : 0;

        return $summary;
    }

    /**
     * 「本日の勤務状況」ステータスカード（§3.2.2）。退職者には表示しない
     * （打刻できない＝表示すべき状態を持たないため）。
     *
     * 03（月次勤務表）・05（稟議）が未実装のため、現時点ではこのカード単体で
     * 描画する。複数モジュールが横並びに寄与するようになった際は、行全体の
     * レイアウトをどこが持つか（各モジュール／core）改めて検討が必要。
     */
    public static function render_status_card(): void
    {
        if (!is_user_logged_in()) {
            return;
        }
        $user_id = get_current_user_id();
        if (UserRepository::is_retired($user_id)) {
            return;
        }

        $data = PunchService::dashboard_summary($user_id);
        ?>
        <div class="tc-status-row">
            <a class="tc-status-card tc-status-card--<?php echo esc_attr($data['status_variant']); ?>"
               href="<?php echo esc_url(home_url('/portal/timecard/')); ?>">
                <span class="tc-status-card-label"><?php esc_html_e('本日の勤務状況', 'ims-portal'); ?></span>
                <span class="tc-status-card-value"><?php echo esc_html($data['status_label']); ?></span>
                <?php if ($data['clock_in'] || $data['clock_out']) : ?>
                    <span class="tc-status-card-times">
                        <?php if ($data['clock_in']) : ?>
                            <?php echo esc_html(sprintf(__('出勤 %s', 'ims-portal'), $data['clock_in'])); ?>
                        <?php endif; ?>
                        <?php if ($data['clock_out']) : ?>
                            <?php echo esc_html(sprintf(__('　退勤 %s', 'ims-portal'), $data['clock_out'])); ?>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
            </a>
        </div>
        <?php
    }

    /** ダッシュボード（`/portal/` トップ）でだけ専用CSSを読み込む。 */
    public static function enqueue(): void
    {
        if (!Router::is_portal_request() || get_query_var('ims_portal_page') !== '') {
            return;
        }
        wp_enqueue_style(
            'ims-timecard-dashboard',
            IMS_PORTAL_URL . 'assets/css/timecard-dashboard.css',
            ['ims-portal-layout'],
            IMS_PORTAL_VERSION
        );
    }
}
