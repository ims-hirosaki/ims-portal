<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

use IMS\Support\Capabilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 月次勤務表グリッドの書き込みAPI（03_attendance_management.md §3.2・§3.3）。3e-2で追加。
 *
 * ルート：
 *   POST /wp-json/ims/v1/attendance/day/{date}/flag           … 勤怠フラグの設定
 *   POST /wp-json/ims/v1/attendance/day/{date}/project-hours  … 事業別時間割当ての保存
 *   POST /wp-json/ims/v1/attendance/month/{year_month}/submit … 月次勤務表の提出（3f-4b）
 *
 * このクラスの責務は HTTP の入出力だけに限る。業務判断は AttendanceFlagService /
 * ProjectHourService / MonthlySummaryService に置き、ここでは混ぜない
 * （Module\Timecard\RestController と同方針）。
 *
 * 認証：permission_callback で `ims_use_portal` を要求する。対象ユーザーは
 * リクエストからではなく必ずログインセッションから取る（なりすまし防止）。
 * nonce（wp_rest）は REST 基盤が検証する。
 *
 * 対象日は他人のものを操作できない（常に自分の打刻・自分の勤務日のみ）。
 * 管理者による他人の勤怠編集は本APIの対象外（必要になれば別エンドポイントで用意する）。
 */
final class RestController
{
    private const NAMESPACE = 'ims/v1';
    private const ROUTE_FLAG          = '/attendance/day/(?P<date>\d{4}-\d{2}-\d{2})/flag';
    private const ROUTE_PROJECT_HOURS = '/attendance/day/(?P<date>\d{4}-\d{2}-\d{2})/project-hours';
    private const ROUTE_SUBMIT        = '/attendance/month/(?P<year_month>\d{4}-\d{2})/submit';

    /** WP_Error のコード → HTTPステータス。 */
    private const STATUS_MAP = [
        'validation'      => 400,
        'not_required'    => 400,
        'incomplete'      => 409,
        'month_locked'    => 403,
        'forbidden'       => 403,
        'forbidden_self'  => 403,
        'invalid_status'  => 409,
        'unallocated_days' => 409,
        'db_error'        => 500,
    ];

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE_FLAG, [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle_set_flag'],
            'permission_callback' => [self::class, 'can_use'],
            'args'                => [
                'date' => [
                    'required' => true,
                    'type'     => 'string',
                ],
                'flag' => [
                    'required' => true,
                    'type'     => 'string',
                    'enum'     => AttendanceFlagCalculator::FLAGS,
                ],
                'hourly_leave_minutes' => [
                    'required' => false,
                    'type'     => 'integer',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, self::ROUTE_PROJECT_HOURS, [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle_save_project_hours'],
            'permission_callback' => [self::class, 'can_use'],
            'args'                => [
                'date' => [
                    'required' => true,
                    'type'     => 'string',
                ],
                'rows' => [
                    'required' => true,
                    'type'     => 'array',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, self::ROUTE_SUBMIT, [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle_submit'],
            'permission_callback' => [self::class, 'can_use'],
            'args'                => [
                'year_month' => [
                    'required' => true,
                    'type'     => 'string',
                ],
            ],
        ]);
    }

    public static function can_use(): bool
    {
        return Capabilities::can_use_portal();
    }

    /**
     * 勤怠フラグの設定（§3.2）。
     */
    public static function handle_set_flag(\WP_REST_Request $request): \WP_REST_Response
    {
        $user_id = get_current_user_id();
        $date    = (string) $request->get_param('date');
        $flag    = (string) $request->get_param('flag');

        $minutes_param = $request->get_param('hourly_leave_minutes');
        $hourly_leave_minutes = ($minutes_param !== null && $minutes_param !== '') ? (int) $minutes_param : null;

        $result = AttendanceFlagService::set_flag($user_id, $date, $flag, $hourly_leave_minutes);

        return self::respond($result, $user_id, $date);
    }

    /**
     * 事業別時間割当ての保存（§3.3）。1日分を丸ごと差し替える。
     */
    public static function handle_save_project_hours(\WP_REST_Request $request): \WP_REST_Response
    {
        $user_id = get_current_user_id();
        $date    = (string) $request->get_param('date');
        $rows    = $request->get_param('rows');

        if (!is_array($rows)) {
            $rows = [];
        }
        $rows = array_map(static function ($row): array {
            $row = (array) $row;
            return [
                'business_id' => (int) ($row['business_id'] ?? 0),
                'start_time'  => (string) ($row['start_time'] ?? ''),
                'end_time'    => (string) ($row['end_time'] ?? ''),
            ];
        }, $rows);

        $result = ProjectHourService::save_day($user_id, $date, $rows);

        return self::respond($result, $user_id, $date);
    }

    /**
     * 月次勤務表の提出（本人分のみ。§3.4）。
     */
    public static function handle_submit(\WP_REST_Request $request): \WP_REST_Response
    {
        $user_id    = get_current_user_id();
        $year_month = (string) $request->get_param('year_month');

        $result = MonthlySummaryService::submit($user_id, $user_id, $year_month);

        if (is_wp_error($result)) {
            $code   = (string) $result->get_error_code();
            $status = self::STATUS_MAP[$code] ?? 400;

            return new \WP_REST_Response([
                'ok'      => false,
                'code'    => $code,
                'message' => $result->get_error_message(),
            ], $status);
        }

        $status = MonthlySummaryService::current_status($user_id, $year_month);

        return new \WP_REST_Response([
            'ok'          => true,
            'status'      => $status,
            'statusLabel' => MonthlySummaryCalculator::label($status),
        ], 200);
    }

    /**
     * @param true|\WP_Error $result
     */
    private static function respond($result, int $user_id, string $date): \WP_REST_Response
    {
        if (is_wp_error($result)) {
            $code   = (string) $result->get_error_code();
            $status = self::STATUS_MAP[$code] ?? 400;

            return new \WP_REST_Response([
                'ok'      => false,
                'code'    => $code,
                'message' => $result->get_error_message(),
            ], $status);
        }

        return new \WP_REST_Response([
            'ok'  => true,
            'day' => AttendanceGridService::day_data($user_id, $date),
        ], 200);
    }
}
