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

    /** 打刻ロックの取得待ち上限（秒）。先行リクエストの処理はこれより十分短い。 */
    private const LOCK_TIMEOUT_SEC = 3;

    /**
     * 「開いている勤務」を継続中とみなす上限（時間）。
     *
     * **定数にした理由：** これは組織が運用で決める値ではなく、
     * 「閉じ忘れた古い勤務に新しい打刻を誤って紐づけない」ためのデータ整合性の内部ガード。
     * 管理者向け設定画面に出しても意味が伝わらない（設定画面の文言は非技術者向けにする方針）。
     * 同じ切り分けで、2d のケースB で使う「6時間」も労基法由来の固定値なので定数に置く。
     * Settings に置くのは組織の運用方針（date_boundary_hour / staff_correction_level /
     * alert_threshold_min）だけ、という区別を保つ。
     *
     * **20 にした理由：** 16 時間程度の長時間シフト＋残業を確実に含みつつ、
     * 24 に近づけると「翌日の同時刻の打刻」を前日シフトの継続と誤認する余地が出るため。
     */
    private const OPEN_SHIFT_MAX_HOURS = 20;

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

        // ④ 判定〜INSERT の全体をユーザー単位で直列化する（§7.1）。
        //    ここを囲まないと、ほぼ同時の2リクエストが両方とも重複チェックと状態検証を
        //    通過して INSERT に到達し、clock_in が同一 work_date に2件入り得る。
        if (!Repository::lock_user_punches($user_id, self::LOCK_TIMEOUT_SEC)) {
            // 先行リクエストが処理中。連打とみなして重複と同じ扱いで返す（409）。
            return self::fail('duplicate', '打刻を処理中です。しばらくしてからお試しください。');
        }

        try {
            // 5秒重複ガード（§7.1）
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
        } finally {
            // 途中で return しても例外が飛んでも必ず解放する。
            Repository::unlock_user_punches($user_id);
        }

        // フックはロックを解放してから撃つ。ims_timecard_clocked_out には将来 05 が
        // Google Chat 通知を繋ぐ想定で、外部通信をロック保持中に走らせたくないため。
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
     * Settings と Repository から材料を集め、判断そのものは decide_work_date() に委ねる。
     */
    public static function resolve_work_date(int $user_id, string $punch_type, int $now_ts): string
    {
        $today = gmdate('Y-m-d', $now_ts);

        // 出勤は常に打刻日なので、開いている勤務を引く必要がない
        if ($punch_type === StatusCalculator::CLOCK_IN) {
            return $today;
        }

        return self::decide_work_date(
            $punch_type,
            $now_ts,
            Settings::date_boundary_hour(),
            Repository::open_shift($user_id, $today),
            self::OPEN_SHIFT_MAX_HOURS
        );
    }

    /**
     * work_date を決める純粋ロジック（§2.1①）。DB にも WordPress にも触れない。
     *
     * ・**出勤（clock_in）は常に打刻日。** 要件 §2.1①「適用範囲」で明示されている。
     * ・**継続打刻（休憩・退勤）は、まだ閉じていない勤務があればそこにぶら下げる。**
     *   採否は「出勤からの経過時間が $max_age_hours 以内か」で判定する。
     *   以前は「開いている勤務の日付が当日または境界日付と一致するか」で見ていたが、
     *   これだと**シフトが境界時刻をまたいで終わると必ず破綻した**。
     *   例（境界0時）：22:00出勤 → 翌01:00退勤。境界日付は翌日、開いている勤務は前日で
     *   どちらとも一致せず、翌日（ログが空）に振られて「まだ出勤打刻がありません」となり
     *   退勤できなかった。境界4時でも 22:00出勤 → 翌05:00退勤で同じ穴が残る。
     *   経過時間で見れば日付をまたいでも拾え、かつ古い閉じ忘れは拾わない。
     * ・開いている勤務が無い（または古すぎる）場合のみ、境界時刻の規定に従う。
     *
     * > **要件からの意図的な逸脱：** この結果、開いている勤務がある通常運用では
     * > `date_boundary_hour` は work_date を変えない。要件 §2.1① は「退勤時刻が境界より前なら前日」
     * > という時刻だけの判定でシフトの開始時刻を見ないため、文言どおりに実装すると
     * > 日をまたぐシフトはどの設定値でも必ず分断される。データ整合性を優先してこちらを採った。
     * > 設定項目自体は §2.1① のとおり残し、上記フォールバックとして機能する。
     *
     * 日付演算は `gmdate` で統一する。WordPress は PHP の既定タイムゾーンを UTC に固定し、
     * `current_time('timestamp')` はローカル時刻を表す値を返すため、`date` と `gmdate` は
     * 同じ結果になる。`gmdate` に揃えておくと実行環境のタイムゾーンに依存せずテストできる。
     *
     * @param string $punch_type
     * @param int    $now_ts        サーバー時刻（current_time('timestamp')）
     * @param int    $boundary_hour Settings::date_boundary_hour() の値（0〜23）
     * @param array{work_date:string, clock_in_at:string}|null $open_shift Repository::open_shift() の結果
     * @param int    $max_age_hours 開いている勤務を継続中とみなす上限（時間）
     */
    public static function decide_work_date(
        string $punch_type,
        int $now_ts,
        int $boundary_hour,
        ?array $open_shift,
        int $max_age_hours
    ): string {
        $today = gmdate('Y-m-d', $now_ts);

        if ($punch_type === StatusCalculator::CLOCK_IN) {
            return $today;
        }

        if ($open_shift !== null) {
            $started = strtotime((string) ($open_shift['clock_in_at'] ?? ''));
            if ($started !== false) {
                $elapsed_hours = ($now_ts - $started) / 3600;
                // 負（出勤が未来）は壊れたデータなので採用しない。
                if ($elapsed_hours >= 0 && $elapsed_hours <= $max_age_hours) {
                    return (string) $open_shift['work_date'];
                }
            }
        }

        // フォールバック：境界時刻の規定（§2.1①）
        if ((int) gmdate('G', $now_ts) < $boundary_hour) {
            return gmdate('Y-m-d', strtotime($today . ' -1 day'));
        }

        return $today;
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
