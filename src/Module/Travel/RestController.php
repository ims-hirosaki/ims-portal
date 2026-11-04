<?php

declare(strict_types=1);

namespace IMS\Module\Travel;

use IMS\Support\Capabilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 04 の書き込みAPI（REST）。このクラスの責務は HTTP の入出力だけ。業務判断は CommuteEditService に置く。
 *
 * 認証：`ims_use_portal` を要求する。対象ユーザーはリクエストではなくログインセッションから取る
 * （他人の交通費は編集できない）。nonce（wp_rest）は REST 基盤が検証する。
 */
final class RestController
{
    private const NAMESPACE = 'ims/v1';
    private const ROUTE_COMMUTE = '/travel/commute/(?P<id>\d+)';
    private const ROUTE_VEHICLE = '/travel/vehicle';
    private const ROUTE_VEHICLE_ONE = '/travel/vehicle/(?P<id>\d+)';

    private const STATUS_MAP = [
        'validation'   => 400,
        'not_found'    => 404,
        'month_locked' => 403,
        'forbidden'    => 403,
        'db_error'     => 500,
    ];

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE_COMMUTE, [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle_update_commute'],
            'permission_callback' => [self::class, 'can_use'],
            'args'                => [
                'id'            => ['required' => true, 'type' => 'integer'],
                'business_id'   => ['required' => false, 'type' => 'integer'],
                'commute_type'  => ['required' => false, 'type' => 'string', 'enum' => CommuteEditCalculator::TYPES],
            ],
        ]);

        register_rest_route(self::NAMESPACE, self::ROUTE_VEHICLE, [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle_create_vehicle'],
            'permission_callback' => [self::class, 'can_use'],
        ]);
        register_rest_route(self::NAMESPACE, self::ROUTE_VEHICLE_ONE, [
            'methods'             => 'DELETE',
            'callback'            => [self::class, 'handle_delete_vehicle'],
            'permission_callback' => [self::class, 'can_use'],
        ]);
    }

    public static function can_use(): bool
    {
        return Capabilities::can_use_portal();
    }

    /** 通勤レコードの事業・往復/片道の変更。 */
    public static function handle_update_commute(\WP_REST_Request $request): \WP_REST_Response
    {
        $business = $request->get_param('business_id');
        $type     = $request->get_param('commute_type');

        $result = CommuteEditService::update(
            get_current_user_id(),
            (int) $request->get_param('id'),
            ($business !== null && $business !== '') ? (int) $business : null,
            ($type !== null && $type !== '') ? (string) $type : null
        );

        if (is_wp_error($result)) {
            $code = (string) $result->get_error_code();
            return new \WP_REST_Response([
                'ok'      => false,
                'code'    => $code,
                'message' => $result->get_error_message(),
            ], self::STATUS_MAP[$code] ?? 400);
        }

        return new \WP_REST_Response([
            'ok'          => true,
            'amount'      => (int) $result['amount'],
            'business_id' => $result['business_id'] !== null ? (int) $result['business_id'] : null,
            'commute_type' => (string) $result['commute_type'],
        ], 200);
    }

    /** 車両借り上げの追加（本人分のみ）。 */
    public static function handle_create_vehicle(\WP_REST_Request $request): \WP_REST_Response
    {
        $p = $request->get_json_params();
        $result = VehicleRentalService::create(get_current_user_id(), is_array($p) ? $p : []);
        return is_wp_error($result) ? self::error($result) : new \WP_REST_Response(['ok' => true, 'amount' => (int) $result['amount']], 200);
    }

    /** 車両借り上げの削除（本人分のみ・提出前の月度だけ）。 */
    public static function handle_delete_vehicle(\WP_REST_Request $request): \WP_REST_Response
    {
        $result = VehicleRentalService::delete(get_current_user_id(), (int) $request->get_param('id'));
        return is_wp_error($result) ? self::error($result) : new \WP_REST_Response(['ok' => true], 200);
    }

    private static function error(\WP_Error $e): \WP_REST_Response
    {
        $code = (string) $e->get_error_code();
        return new \WP_REST_Response(['ok' => false, 'code' => $code, 'message' => $e->get_error_message()], self::STATUS_MAP[$code] ?? 400);
    }
}
