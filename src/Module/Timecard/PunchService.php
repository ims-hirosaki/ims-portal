<?php

declare(strict_types=1);

namespace IMS\Module\Timecard;

use IMS\Support\UserRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 打刻の記録（02_time_tracking.md §3.1・§7.1）。2c で追加。
 *
 * RestController は HTTP の入出力だけを担当し、業務判断はすべてこのクラスに置く。
 * こうしておくと 2d（退勤補完）・2e（打刻修正）・2f（管理画面）から同じ規則を再利用でき、
 * 「画面ごとに微妙にルールが違う」事故を防げる。
 *
 * このクラスが守る不変条件：
 *  ① 打刻時刻は必ずサーバー時刻（§3.1「サーバーサイド時刻の強制」）。引数で時刻は受け取らない。
 *  ② 退職者は打刻できない（§6.1）。画面の非活性だけに頼らない。
 *  ③ 状態遷移は StatusCalculator で検証する（§3.1）。UI のボタン活性は信用しない。
 *  ④ 同一種別の 5 秒以内の連打は 409 で弾く（§7.1）。
 */
final class PunchService
{
    /** 多重サブミット判定の窓（秒）。§7.1 で 5 秒と定義。 */
    public const DUPLICATE_WINDOW_SEC = 5;

    /**
     * 打刻を1件記録する。
     *
     * @param int         $user_id 打刻するユーザー
     * @param string      $punch_type clock_in / break_in / break_out / clock_out
     * @param float|null  $lat GPS緯度（オプトイン・未取得なら null）
     * @param float|null  $lng GPS経度（同上）
     * @return array{ok:bool, code:string, message:string, log_id?:int, punched_at?:string, work_date?:string}
     *         ok=false のとき code はエラーコード（RestController が HTTP ステータスへ対応づける）。
     *         状態不一致で弾いた場合は work_date も併せて返す。
     */
    public static function punch(int $user_id, string $punch_type, ?float $lat = null, ?float $lng = null): array
    {
        // ② 退職者拒否（§6.1）。他のどの判定よりも先に行う。
        if (UserRepository::is_retired($user_id)) {
            return self::fail('retired', '退職済みのため打刻できません。');
        }

        // ① サーバー時刻の強制（§3.1）。クライアントから時刻は一切受け取らない。
        $now_mysql = current_time('mysql');
        $now_ts    = (int) current_time('timestamp');

        // ④ 多重サブミットのサーバー側ガード（§7.1）
        if (Repository::has_recent_punch($user_id, $punch_type, $now_mysql, self::DUPLICATE_WINDOW_SEC)) {
            return self::fail('duplicate', '直前に同じ打刻が記録されています。しばらくしてからお試しください。');
        }

        // 帰属日付を決めてから、その日のログで状態を判定する
        $work_date = self::resolve_work_date($user_id, $punch_type, $now_ts);
        $logs      = Repository::logs_for_date($user_id, $work_date);

        // ③ 状態遷移の検証（§3.1）
        $verdict = StatusCalculator::validate_transition($logs, $punch_type);
        if ($verdict !== StatusCalculator::OK) {
            // work_date も返す。呼び出し側が現在状態を返すときに引き直さずに済む。
            return self::fail($verdict, StatusCalculator::error_message($verdict), $work_date);
        }

        $log_id = Repository::insert_punch([
            'user_id'        => $user_id,
            'work_date'      => $work_date,
            'punch_type'     => $punch_type,
            'punched_at'     => $now_mysql,
            'is_auto_filled' => 0,
            'ip_address'     => self::client_ip(),
            'gps_latitude'   => self::normalize_lat($lat),
            'gps_longitude'  => self::normalize_lng($lng),
        ]);

        if ($log_id === 0) {
            return self::fail('db_error', '打刻の保存に失敗しました。時間をおいて再度お試しください。');
        }

        self::fire_hooks($user_id, $punch_type, $work_date, $now_mysql, $log_id);

        return [
            'ok'         => true,
            'code'       => 'ok',
            'message'    => self::success_message($punch_type),
            'log_id'     => $log_id,
            'punched_at' => $now_mysql,
            'work_date'  => $work_date,
        ];
    }

    /**
     * 打刻ログの「帰属日付」（work_date）を決める（§2.1①）。
     *
     * ・出勤（clock_in）は常に打刻日。要件で明示されている（§2.1①「適用範囲」）。
     * ・それ以外（休憩・退勤）は日付境界時刻を適用し、境界より前の深夜帯なら前日に寄せる。
     *   要件が名指しするのは退勤だけだが、休憩も同じ規則で寄せないと、深夜0時をまたいだ
     *   休憩打刻だけが翌日の孤立レコードになり状態機械が壊れる。既定値 0 では
     *   どちらの規則でも「打刻日」になるため、既定運用での挙動は要件どおり。
     * ・さらに「開いている勤務」（出勤済み・退勤未）があり、それが当日か境界日付の
     *   いずれかであれば、そちらを優先して打刻を同じ勤務にぶら下げる。
     *   これで「境界時刻より後に始まる深夜シフト」（例：境界4時に対し2時出勤）でも、
     *   出勤と休憩・退勤の work_date が食い違わない。
     */
    public static function resolve_work_date(int $user_id, string $punch_type, int $now_ts): string
    {
        $today = date('Y-m-d', $now_ts);

        if ($punch_type === StatusCalculator::CLOCK_IN) {
            return $today;
        }

        $boundary_hour = Settings::date_boundary_hour();
        $hour          = (int) date('G', $now_ts);
        $boundary_date = $hour < $boundary_hour
            ? date('Y-m-d', strtotime($today . ' -1 day'))
            : $today;

        $open = Repository::open_shift_date($user_id, $today);
        if ($open !== null && ($open === $today || $open === $boundary_date)) {
            return $open;
        }

        return $boundary_date;
    }

    /**
     * 打刻コンソールが「本日分」として表示すべき work_date。
     *
     * 打刻APIが継続打刻（休憩・退勤）に使う日付と同じ規則で解決する。
     * 画面とAPIで別々に日付を求めると、深夜帯に「画面は出勤前・APIは勤務中」の
     * ような食い違いが起きるため、必ずこの1か所を通す。
     */
    public static function console_work_date(int $user_id): string
    {
        return self::resolve_work_date($user_id, StatusCalculator::BREAK_IN, (int) current_time('timestamp'));
    }

    /**
     * 打刻直後の画面状態を組み立てる（フロントがリロードせず描き替えるための材料・§4.1）。
     *
     * @return array<string, mixed>
     */
    public static function current_state(int $user_id, string $work_date): array
    {
        $logs   = Repository::logs_for_date($user_id, $work_date);
        $now_ts = (int) current_time('timestamp');
        $status = StatusCalculator::status($logs);
        $worked = StatusCalculator::worked_seconds($logs, $now_ts);

        return [
            'work_date'      => $work_date,
            'status'         => $status,
            'status_label'   => StatusCalculator::status_label($status),
            'status_variant' => StatusCalculator::status_variant($status),
            'active'         => StatusCalculator::active_buttons($logs),
            'worked_seconds' => $worked,
            'worked_label'   => StatusCalculator::format_duration($worked),
            'server_now'     => $now_ts,
        ];
    }

    // ── フック ──────────────────────────────────────────────

    /**
     * 他モジュールの受け口。ここを撃っておけば 05（残業乖離アラート）や
     * 04（通勤交通費の発生日判定）が本モジュール無改修で処理を挿せる。
     */
    private static function fire_hooks(int $user_id, string $punch_type, string $work_date, string $punched_at, int $log_id): void
    {
        do_action('ims_timecard_punched', $user_id, $punch_type, $work_date, $punched_at, $log_id);

        if ($punch_type === StatusCalculator::CLOCK_OUT) {
            // 05_稟議モジュールの残業乖離検知が拾う（要件 §6・引き継ぎ書の設計方針）。
            do_action('ims_timecard_clocked_out', $user_id, $work_date, $punched_at, $log_id);
        }
    }

    // ── 付帯情報 ────────────────────────────────────────────

    /**
     * 打刻元IPアドレス（§5.1 ip_address）。
     *
     * X-Forwarded-For 等のプロキシヘッダーは**意図的に見ない**。監査目的の記録であり、
     * クライアントが自由に詐称できる値を証跡として残すと意味が薄れるため、
     * TCP 接続元の REMOTE_ADDR のみを採用する。
     */
    private static function client_ip(): ?string
    {
        $raw = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '';
        if ($raw === '' || filter_var($raw, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        // varchar(45) は IPv6 の最大長。念のため丸める。
        return substr($raw, 0, 45);
    }

    /** 緯度の正規化。範囲外・未取得は null（打刻自体はブロックしない・§4.1）。 */
    private static function normalize_lat(?float $lat): ?float
    {
        if ($lat === null || !is_finite($lat) || $lat < -90.0 || $lat > 90.0) {
            return null;
        }
        return round($lat, 7); // DDL は decimal(10,7)
    }

    /** 経度の正規化。 */
    private static function normalize_lng(?float $lng): ?float
    {
        if ($lng === null || !is_finite($lng) || $lng < -180.0 || $lng > 180.0) {
            return null;
        }
        return round($lng, 7);
    }

    // ── メッセージ ──────────────────────────────────────────

    private static function success_message(string $punch_type): string
    {
        return match ($punch_type) {
            StatusCalculator::CLOCK_IN  => '出勤を記録しました。',
            StatusCalculator::BREAK_IN  => '休憩開始を記録しました。',
            StatusCalculator::BREAK_OUT => '休憩終了を記録しました。',
            StatusCalculator::CLOCK_OUT => '退勤を記録しました。お疲れさまでした。',
            default                     => '打刻を記録しました。',
        };
    }

    /**
     * @return array{ok:bool, code:string, message:string, work_date?:string}
     */
    private static function fail(string $code, string $message, ?string $work_date = null): array
    {
        $out = ['ok' => false, 'code' => $code, 'message' => $message];
        if ($work_date !== null) {
            $out['work_date'] = $work_date;
        }
        return $out;
    }
}
