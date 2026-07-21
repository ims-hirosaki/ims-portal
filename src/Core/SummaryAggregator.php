<?php

declare(strict_types=1);

namespace IMS\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `/wp-json/ims/v1/portal/summary` と `badge-count` の集計を、
 * 各モジュールからの寄与でマージするアグリゲーター（08 §6.2 準拠・新規定義）。
 *
 * 設計方針：
 * ・00 本体は集計ロジックを持たない。各モジュールが `ims_portal_summary_contribute`
 *   フィルタで自分の集計値を差し込む。
 * ・各寄与は必ず自分のユーザーのデータのみを返す（本クラスが渡す $user_id で絞り込む）。
 * ・approver_widgets は approver 以上の権限を持つユーザーのみレスポンスに含める
 *   （00_portal.md §5.3 準拠）。
 *
 * モジュール側の寄与例：
 *
 *   add_filter('ims_portal_summary_contribute', function (array $summary, int $user_id): array {
 *       $summary['attendance_status'] = ims_timecard_today_status($user_id);
 *       $summary['clock_in']          = ims_timecard_today_clock_in($user_id);
 *       $summary['missing_timecard']  = ims_timecard_is_missing($user_id);
 *       return $summary;
 *   }, 10, 2);
 */
final class SummaryAggregator
{
    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void
    {
        register_rest_route('ims/v1', '/portal/summary', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handle_summary'],
            'permission_callback' => static fn(): bool => is_user_logged_in(),
        ]);

        register_rest_route('ims/v1', '/portal/badge-count', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handle_badge_count'],
            'permission_callback' => static fn(): bool => is_user_logged_in(),
        ]);
    }

    public static function handle_summary(\WP_REST_Request $request): \WP_REST_Response
    {
        $user_id = get_current_user_id();

        $summary = apply_filters('ims_portal_summary_contribute', [], $user_id);

        // approver 権限未満のユーザーには approver_widgets を含めない
        $user = wp_get_current_user();
        if (!$user->has_cap('ims_approve')) {
            unset($summary['approver_widgets']);
        }

        return new \WP_REST_Response($summary, 200);
    }

    /**
     * ヘッダーバッジ用の軽量エンドポイント。summary の寄与のうち
     * `badge_count` キーの値のみを合算して返す（00_portal.md §3.3・§5.3 準拠）。
     * 各モジュールは summary への寄与の中で `badge_count`（int）を積み上げる形で協調する。
     */
    public static function handle_badge_count(\WP_REST_Request $request): \WP_REST_Response
    {
        $user_id = get_current_user_id();
        $summary = apply_filters('ims_portal_summary_contribute', [], $user_id);

        $total = is_array($summary['badge_count'] ?? null) ? array_sum($summary['badge_count']) : (int) ($summary['badge_count'] ?? 0);

        return new \WP_REST_Response(['count' => $total], 200);
    }
}
