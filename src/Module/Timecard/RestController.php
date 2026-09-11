<?php

declare(strict_types=1);

namespace IMS\Module\Timecard;

use IMS\Support\Capabilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 打刻APIのRESTエンドポイント（02_time_tracking.md §4.1・§7.1・§7.2）。2c で追加。
 *
 * ルート：POST /wp-json/ims/v1/timecard/punch
 *
 * このクラスの責務は HTTP の入出力だけに限る。
 * 権限判定は permission_callback、業務判断は PunchService に置き、ここでは混ぜない。
 *
 * 認証（§7.2）：
 * ・permission_callback で `ims_use_portal` を要求する（未認証・権限なしは 401/403）。
 * ・nonce（`wp_rest`）は WordPress の REST 基盤が X-WP-Nonce ヘッダーで検証する。
 *   Cookie 認証のリクエストで nonce が無い／不正な場合、コア側が 403 を返すため
 *   本クラスで check_ajax_referer を重ねる必要はない。
 */
final class RestController
{
    private const NAMESPACE = 'ims/v1';
    private const ROUTE     = '/timecard/punch';

    /** ケースA（休憩補完のみ）。§3.2 ケースA。 */
    private const ROUTE_COMPLETE_BREAK = '/timecard/punch/complete-break';
    /** ケースB・ボタンA（休憩の開始・終了を指定して退勤）。§3.2 ケースB。 */
    private const ROUTE_CLOCK_OUT_WITH_BREAK = '/timecard/punch/clock-out-with-break';

    /**
     * エラーコード → HTTPステータス。§7.1 は重複を 409 Conflict と定めている。
     * `currently_on_break`・`break_already_recorded`・`invalid_break_minutes`・
     * `invalid_break_range` は 2d（休憩補完付き退勤）のエラーコード。
     */
    private const STATUS_MAP = [
        'retired'                   => 403,
        'duplicate'                 => 409,
        'already_clocked_in'        => 409,
        'already_clocked_out'       => 409,
        'already_on_break'          => 409,
        'not_clocked_in'            => 409,
        'not_on_break'              => 409,
        'break_completion_required' => 409,
        'currently_on_break'        => 409,
        'break_already_recorded'    => 409,
        'invalid_break_minutes'     => 400,
        'invalid_break_range'       => 400,
        'unknown_punch_type'        => 400,
        'db_error'                  => 500,
    ];

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle_punch'],
            'permission_callback' => [self::class, 'can_punch'],
            'args'                => [
                'punch_type' => [
                    'required' => true,
                    'type'     => 'string',
                    // 未知の値は WordPress のスキーマ検証が 400 で弾く
                    'enum'     => [
                        StatusCalculator::CLOCK_IN,
                        StatusCalculator::BREAK_IN,
                        StatusCalculator::BREAK_OUT,
                        StatusCalculator::CLOCK_OUT,
                    ],
                ],
                'gps_latitude' => [
                    'required' => false,
                    'type'     => 'number',
                ],
                'gps_longitude' => [
                    'required' => false,
                    'type'     => 'number',
                ],
            ],
        ]);

        // ケースA：休憩中に退勤しようとした場合の補完（§3.2 ケースA）
        register_rest_route(self::NAMESPACE, self::ROUTE_COMPLETE_BREAK, [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle_complete_break'],
            'permission_callback' => [self::class, 'can_punch'],
            'args'                => [
                'minutes' => [
                    'required' => true,
                    'type'    => 'integer',
                ],
            ],
        ]);

        // ケースB・ボタンA：休憩を打刻していないが、開始・終了を指定して退勤する（§3.2 ケースB）
        register_rest_route(self::NAMESPACE, self::ROUTE_CLOCK_OUT_WITH_BREAK, [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle_clock_out_with_break'],
            'permission_callback' => [self::class, 'can_punch'],
            'args'                => [
                'break_in' => [
                    'required' => true,
                    'type'     => 'string',
                ],
                'break_out' => [
                    'required' => true,
                    'type'     => 'string',
                ],
            ],
        ]);
    }

    /**
     * §7.2：未認証リクエストを拒否する。
     * 打刻は「本人が自分の打刻をする」操作のみなので、ポータル利用権限だけを要求し、
     * 対象ユーザーはリクエストからではなく必ずログインセッションから取る（なりすまし防止）。
     */
    public static function can_punch(): bool
    {
        return Capabilities::can_use_portal();
    }

    public static function handle_punch(\WP_REST_Request $request): \WP_REST_Response
    {
        $user_id = get_current_user_id();

        $punch_type = (string) $request->get_param('punch_type');
        $lat        = self::float_param($request, 'gps_latitude');
        $lng        = self::float_param($request, 'gps_longitude');

        $result = PunchService::punch($user_id, $punch_type, $lat, $lng);

        if (!$result['ok']) {
            $code   = (string) $result['code'];
            $status = self::STATUS_MAP[$code] ?? 400;

            // 状態不一致で弾いた場合は、クライアントが画面を正しい状態へ戻せるよう
            // 現在の状態も一緒に返す（リロードなしで復帰させる・§4.1）。
            $body = [
                'ok'      => false,
                'code'    => $code,
                'message' => (string) $result['message'],
            ];
            if ($status === 409) {
                $work_date = (string) ($result['work_date']
                    ?? PunchService::resolve_work_date($user_id, $punch_type, (int) current_time('timestamp')));
                $body['state'] = PunchService::current_state($user_id, $work_date);
            }

            return new \WP_REST_Response($body, $status);
        }

        return new \WP_REST_Response([
            'ok'      => true,
            'message' => (string) $result['message'],
            'punch'   => [
                'log_id'     => (int) $result['log_id'],
                'punch_type' => $punch_type,
                'punched_at' => (string) $result['punched_at'],
                'time'       => date('H:i', strtotime((string) $result['punched_at'])),
                'work_date'  => (string) $result['work_date'],
            ],
            'state'   => PunchService::current_state($user_id, (string) $result['work_date']),
        ], 201);
    }

    /**
     * ケースA：休憩中の退勤補完（§3.2 ケースA）。
     */
    public static function handle_complete_break(\WP_REST_Request $request): \WP_REST_Response
    {
        $user_id = get_current_user_id();
        $minutes = (int) $request->get_param('minutes');

        $result = PunchService::clock_out_with_break_duration($user_id, $minutes);
        return self::respond_clock_out_result($user_id, $result);
    }

    /**
     * ケースB・ボタンA：休憩の開始・終了を指定しての退勤（§3.2 ケースB）。
     */
    public static function handle_clock_out_with_break(\WP_REST_Request $request): \WP_REST_Response
    {
        $user_id   = get_current_user_id();
        $break_in  = (string) $request->get_param('break_in');
        $break_out = (string) $request->get_param('break_out');

        $result = PunchService::clock_out_with_break_range($user_id, $break_in, $break_out);
        return self::respond_clock_out_result($user_id, $result);
    }

    /**
     * handle_complete_break() / handle_clock_out_with_break() 共通のレスポンス整形。
     * どちらも最終的な打刻種別は必ず clock_out なので handle_punch() の汎用整形は流用せず、
     * 既存の handle_punch()（2c で検証済み・本番稼働中）には手を加えない。
     *
     * 成功時は `punches`（複数形）に、今回の操作で保存された全レコード
     * （休憩の補完・登録があればそれも含む）を保存順に並べて返す。
     * フロントはこれをループして履歴テーブルの複数セルをリロードなしで描き替える。
     */
    private static function respond_clock_out_result(int $user_id, array $result): \WP_REST_Response
    {
        if (!$result['ok']) {
            $code   = (string) $result['code'];
            $status = self::STATUS_MAP[$code] ?? 400;

            $body = [
                'ok'      => false,
                'code'    => $code,
                'message' => (string) $result['message'],
            ];
            if ($status === 409) {
                $work_date = (string) ($result['work_date'] ?? PunchService::console_work_date($user_id));
                $body['state'] = PunchService::current_state($user_id, $work_date);
            }

            return new \WP_REST_Response($body, $status);
        }

        $work_date = (string) $result['work_date'];
        $punches   = [];
        foreach ([StatusCalculator::BREAK_IN, StatusCalculator::BREAK_OUT] as $type) {
            if (isset($result[$type])) {
                $punches[] = self::shape_punch($type, (string) $result[$type]['punched_at'], $work_date, true);
            }
        }
        $punches[] = self::shape_punch(StatusCalculator::CLOCK_OUT, (string) $result['punched_at'], $work_date, false);

        return new \WP_REST_Response([
            'ok'      => true,
            'message' => (string) $result['message'],
            'punches' => $punches,
            'state'   => PunchService::current_state($user_id, $work_date),
        ], 201);
    }

    /**
     * @return array{log_id?:int, punch_type:string, punched_at:string, time:string, work_date:string, is_auto_filled:bool}
     */
    private static function shape_punch(string $punch_type, string $punched_at, string $work_date, bool $is_auto_filled): array
    {
        return [
            'punch_type'     => $punch_type,
            'punched_at'     => $punched_at,
            'time'           => date('H:i', strtotime($punched_at)),
            'work_date'      => $work_date,
            'is_auto_filled' => $is_auto_filled,
        ];
    }

    /**
     * 任意の数値パラメータを float|null で取り出す。
     * 未送信・空文字・数値でない値はすべて「取得できなかった」＝ null として扱う
     * （GPS はオプトインで、拒否されても打刻はブロックしない・§4.1）。
     */
    private static function float_param(\WP_REST_Request $request, string $key): ?float
    {
        $value = $request->get_param($key);
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }
}
