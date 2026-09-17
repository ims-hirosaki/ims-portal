<?php

declare(strict_types=1);

namespace IMS\Module\Timecard;

use IMS\Support\Capabilities;
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
     * 労働基準法の休憩付与義務の目安（§3.2 インフォボックス）。組織の運用方針ではなく
     * 法令由来の固定値のため、OPEN_SHIFT_MAX_HOURS と同じ原則で定数に置く。
     * 6時間超〜8時間以内は45分以上、8時間超は60分以上の休憩が必要とされる。
     * ケースB（休憩未打刻での退勤）の確認ポップアップ表示要否の閾値も
     * LABOR_BREAK_TIER1_HOURS（6時間）を流用する。
     */
    public const LABOR_BREAK_TIER1_HOURS   = 6;
    public const LABOR_BREAK_TIER2_HOURS   = 8;
    public const LABOR_BREAK_TIER1_MINUTES = 45;
    public const LABOR_BREAK_TIER2_MINUTES = 60;

    /** 打刻漏れ通知の判定時刻（00_portal.md §3.3「出勤前状態のまま16時以降」）。 */
    private const MISSING_TIMECARD_HOUR = 16;

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
     * ケースA（休憩中に退勤ボタンが押された）の補完保存（§3.2 ケースA）。
     *
     * 「休憩終了（自動補完・is_auto_filled=1）」と「退勤（実時刻）」の2レコードを
     * 1トランザクションで保存する。休憩終了時刻は「最終 break_in ＋ $minutes 分」。
     * 前提状態（休憩中であること）と時刻の妥当性は、クライアント側の表示を信用せず
     * ここで必ず再検証する。
     *
     * @return array{ok:bool, code:string, message:string, log_id?:int, punched_at?:string, work_date?:string}
     */
    public static function clock_out_with_break_duration(int $user_id, int $minutes): array
    {
        if (UserRepository::is_retired($user_id)) {
            return self::fail('retired', '退職済みのため打刻できません。');
        }

        $now_mysql = current_time('mysql');
        $now_ts    = (int) current_time('timestamp');
        $work_date = self::resolve_work_date($user_id, StatusCalculator::CLOCK_OUT, $now_ts);

        if (!Repository::lock_user_punches($user_id, self::LOCK_TIMEOUT_SEC)) {
            return self::fail('duplicate', '打刻を処理中です。しばらくしてからお試しください。');
        }

        try {
            $logs = Repository::logs_for_date($user_id, $work_date);

            if (StatusCalculator::status($logs) !== StatusCalculator::BREAK) {
                return self::fail('not_on_break', StatusCalculator::error_message('not_on_break'), $work_date);
            }

            $break_in_ts = self::last_punch_ts($logs, StatusCalculator::BREAK_IN);
            if ($break_in_ts === null || $minutes < 1) {
                return self::fail('invalid_break_minutes', StatusCalculator::error_message('invalid_break_minutes'), $work_date);
            }

            $break_out_ts = $break_in_ts + ($minutes * 60);
            if ($break_out_ts > $now_ts) {
                return self::fail('invalid_break_minutes', StatusCalculator::error_message('invalid_break_minutes'), $work_date);
            }

            $rows = [
                [
                    'user_id'        => $user_id,
                    'work_date'      => $work_date,
                    'punch_type'     => StatusCalculator::BREAK_OUT,
                    'punched_at'     => gmdate('Y-m-d H:i:s', $break_out_ts),
                    'is_auto_filled' => 1,
                    'ip_address'     => self::client_ip(),
                ],
                [
                    'user_id'        => $user_id,
                    'work_date'      => $work_date,
                    'punch_type'     => StatusCalculator::CLOCK_OUT,
                    'punched_at'     => $now_mysql,
                    'is_auto_filled' => 0,
                    'ip_address'     => self::client_ip(),
                ],
            ];

            $ids = Repository::insert_punches_atomic($rows);
            if ($ids === false) {
                return self::fail('db_error', '打刻の保存に失敗しました。時間をおいて再度お試しください。');
            }
        } finally {
            Repository::unlock_user_punches($user_id);
        }

        foreach ($ids as $i => $log_id) {
            self::fire_hooks($user_id, $rows[$i]['punch_type'], $work_date, $rows[$i]['punched_at'], $log_id);
        }

        return [
            'ok'         => true,
            'code'       => 'ok',
            'message'    => self::success_message(StatusCalculator::CLOCK_OUT),
            'log_id'     => $ids[1],
            'punched_at' => $now_mysql,
            'work_date'  => $work_date,
            // フロントが履歴テーブルの休憩終了セルもリロードなしで描き替えられるよう、
            // 補完した休憩終了ログの情報も併せて返す。
            'break_out'  => ['log_id' => $ids[0], 'punched_at' => $rows[0]['punched_at']],
        ];
    }

    /**
     * ケースB・ボタンA（休憩を一度も打刻せず退勤しようとしたが、休憩を登録する）の保存（§3.2 ケースB）。
     *
     * ケースAと違い実在する break_in が無いため、開始・終了の時刻を利用者が直接指定する。
     * どちらも「実際にその時刻に打刻した」ものではなく事後入力のため、
     * 休憩開始・休憩終了ともに is_auto_filled=1 で記録する（退勤のみ実時刻・is_auto_filled=0）。
     * 「休憩開始」「休憩終了」「退勤」の3レコードを1トランザクションで保存する。
     *
     * @param string $break_in_hm  休憩開始時刻 'H:i'
     * @param string $break_out_hm 休憩終了時刻 'H:i'
     * @return array{ok:bool, code:string, message:string, log_id?:int, punched_at?:string, work_date?:string}
     */
    public static function clock_out_with_break_range(int $user_id, string $break_in_hm, string $break_out_hm): array
    {
        if (UserRepository::is_retired($user_id)) {
            return self::fail('retired', '退職済みのため打刻できません。');
        }

        $now_mysql = current_time('mysql');
        $now_ts    = (int) current_time('timestamp');
        $work_date = self::resolve_work_date($user_id, StatusCalculator::CLOCK_OUT, $now_ts);

        if (!Repository::lock_user_punches($user_id, self::LOCK_TIMEOUT_SEC)) {
            return self::fail('duplicate', '打刻を処理中です。しばらくしてからお試しください。');
        }

        try {
            $logs   = Repository::logs_for_date($user_id, $work_date);
            $status = StatusCalculator::status($logs);

            if ($status === StatusCalculator::BREAK) {
                return self::fail('currently_on_break', StatusCalculator::error_message('currently_on_break'), $work_date);
            }
            if ($status !== StatusCalculator::WORKING) {
                $code = $status === StatusCalculator::BEFORE ? 'not_clocked_in' : 'already_clocked_out';
                return self::fail($code, StatusCalculator::error_message($code), $work_date);
            }
            if (StatusCalculator::has_break_in($logs)) {
                return self::fail('break_already_recorded', StatusCalculator::error_message('break_already_recorded'), $work_date);
            }

            $clock_in_ts  = self::last_punch_ts($logs, StatusCalculator::CLOCK_IN);
            $break_in_ts  = $clock_in_ts !== null ? self::parse_time_on_or_after($break_in_hm, $work_date, $clock_in_ts) : null;
            $break_out_ts = $break_in_ts !== null ? self::parse_time_on_or_after($break_out_hm, $work_date, $break_in_ts) : null;

            if ($clock_in_ts === null || $break_in_ts === null || $break_out_ts === null
                || $break_in_ts < $clock_in_ts || $break_out_ts <= $break_in_ts || $break_out_ts > $now_ts) {
                return self::fail('invalid_break_range', StatusCalculator::error_message('invalid_break_range'), $work_date);
            }

            $rows = [
                [
                    'user_id'        => $user_id,
                    'work_date'      => $work_date,
                    'punch_type'     => StatusCalculator::BREAK_IN,
                    'punched_at'     => gmdate('Y-m-d H:i:s', $break_in_ts),
                    'is_auto_filled' => 1,
                    'ip_address'     => self::client_ip(),
                ],
                [
                    'user_id'        => $user_id,
                    'work_date'      => $work_date,
                    'punch_type'     => StatusCalculator::BREAK_OUT,
                    'punched_at'     => gmdate('Y-m-d H:i:s', $break_out_ts),
                    'is_auto_filled' => 1,
                    'ip_address'     => self::client_ip(),
                ],
                [
                    'user_id'        => $user_id,
                    'work_date'      => $work_date,
                    'punch_type'     => StatusCalculator::CLOCK_OUT,
                    'punched_at'     => $now_mysql,
                    'is_auto_filled' => 0,
                    'ip_address'     => self::client_ip(),
                ],
            ];

            $ids = Repository::insert_punches_atomic($rows);
            if ($ids === false) {
                return self::fail('db_error', '打刻の保存に失敗しました。時間をおいて再度お試しください。');
            }
        } finally {
            Repository::unlock_user_punches($user_id);
        }

        foreach ($ids as $i => $log_id) {
            self::fire_hooks($user_id, $rows[$i]['punch_type'], $work_date, $rows[$i]['punched_at'], $log_id);
        }

        return [
            'ok'         => true,
            'code'       => 'ok',
            'message'    => self::success_message(StatusCalculator::CLOCK_OUT),
            'log_id'     => $ids[2],
            'punched_at' => $now_mysql,
            'work_date'  => $work_date,
            // フロントが履歴テーブルの休憩開始・終了セルもリロードなしで描き替えられるよう、
            // 登録した休憩ログの情報も併せて返す。
            'break_in'   => ['log_id' => $ids[0], 'punched_at' => $rows[0]['punched_at']],
            'break_out'  => ['log_id' => $ids[1], 'punched_at' => $rows[1]['punched_at']],
        ];
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

    /**
     * ダッシュボード連携用の要約（2g）。ステータスカード（00_portal.md §3.2.2）・
     * タイルバッジ（§3.2.4）・summary API（§5.3）の3箇所が同じ判定を見るよう、
     * ここ1か所に集約する（UI側とAPI側で二重実装しない・原則）。
     *
     * @return array{
     *   status:string, status_label:string, status_variant:string,
     *   clock_in:?string, clock_out:?string, missing_timecard:bool
     * }
     */
    public static function dashboard_summary(int $user_id): array
    {
        $work_date = self::console_work_date($user_id);
        $logs      = Repository::logs_for_date($user_id, $work_date);
        $status    = StatusCalculator::status($logs);

        $clock_in_ts  = self::last_punch_ts($logs, StatusCalculator::CLOCK_IN);
        $clock_out_ts = self::last_punch_ts($logs, StatusCalculator::CLOCK_OUT);

        $now_ts = (int) current_time('timestamp');

        return [
            'status'           => $status,
            'status_label'     => StatusCalculator::status_label($status),
            'status_variant'   => StatusCalculator::status_variant($status),
            'clock_in'         => $clock_in_ts !== null ? gmdate('H:i', $clock_in_ts) : null,
            'clock_out'        => $clock_out_ts !== null ? gmdate('H:i', $clock_out_ts) : null,
            'missing_timecard' => self::is_missing_timecard($status, (int) gmdate('G', $now_ts)),
        ];
    }

    /**
     * 打刻漏れ判定の純粋ロジック（00_portal.md §3.3「出勤前状態のまま16時以降」）。
     * DB/WPに触れないため、ヘッダーバッジ・タイルバッジ・ステータスカードが
     * すべてここを通れば判定が食い違わない。
     */
    private static function is_missing_timecard(string $status, int $hour): bool
    {
        return $status === StatusCalculator::BEFORE && $hour >= self::MISSING_TIMECARD_HOUR;
    }

    // ── 打刻修正（2e） ──────────────────────────────────────

    /**
     * 打刻修正が許可されるかを判定する純粋ロジック（§3.4「修正許可レベルの動作」）。
     * DB/WPに触れない。締め後ロック（MonthlyClosing）はこれより優先して呼び出し側で
     * 判定する（すべての操作者に対して修正不可・§3.4）。
     *
     * @param bool   $is_always_allowed   hr_admin/administrator（常に直接修正可）
     * @param bool   $has_self_service_cap ims_correct_own_punch（approverはfalse）
     * @param string $correction_level    Settings::staff_correction_level() の値
     * @param string $work_date           対象ログの work_date
     * @param string $current_work_date   現在の勤務日（console_work_date() 相当）
     */
    public static function can_correct_punch(
        bool $is_always_allowed,
        bool $has_self_service_cap,
        string $correction_level,
        string $work_date,
        string $current_work_date
    ): bool {
        if ($is_always_allowed) {
            return true;
        }
        if (!$has_self_service_cap) {
            return false; // approver 等
        }

        return match ($correction_level) {
            'disabled'    => false,
            'today_only'  => $work_date === $current_work_date,
            'pre_closing' => true,
            default       => false,
        };
    }

    /**
     * 打刻修正（§3.4／§4.2）。
     *
     * 対象ログは原則ログイン中の本人のものに限るが、`ims_manage_users`
     * （hr_admin/administrator）は 2f の管理者向け打刻ログ照会画面から
     * 他人のログも修正できる（§4.2「スタッフ側の修正許可設定に関わらず直接修正可能」）。
     * 権限を持たないユーザーが他人の log_id を指定した場合は、存在を教えず
     * 一律 not_found として扱う（IDOR対策）。
     *
     * @return array{ok:bool, code:string, message:string, work_date?:string}
     */
    public static function correct_punch(int $user_id, int $log_id, string $corrected_datetime, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return self::fail('reason_required', '修正理由を入力してください。');
        }

        $log = Repository::find_log($log_id);
        if ($log === null) {
            return self::fail('not_found', '対象の打刻が見つかりません。');
        }
        if ($log['user_id'] !== $user_id && !Capabilities::can_manage_users()) {
            return self::fail('not_found', '対象の打刻が見つかりません。');
        }

        $corrected_ts = strtotime($corrected_datetime);
        if ($corrected_ts === false) {
            return self::fail('invalid_datetime', '修正後の日時が不正です。', $log['work_date']);
        }

        // 締め後ロックはすべての操作者に優先して効く（§3.4）。
        if (MonthlyClosing::is_locked($log['work_date'])) {
            return self::fail('month_closed', 'この月度は締め処理が完了しているため修正できません。', $log['work_date']);
        }

        $allowed = self::can_correct_punch(
            Capabilities::can_manage_users(),
            Capabilities::can_correct_own_punch(),
            Settings::staff_correction_level(),
            $log['work_date'],
            self::console_work_date($user_id)
        );
        if (!$allowed) {
            return self::fail('correction_not_allowed', 'この打刻を修正する権限がありません。', $log['work_date']);
        }

        $corrected_mysql = gmdate('Y-m-d H:i:s', $corrected_ts);

        // 保存前に、置き換え後の並びで矛盾チェック（§3.4 手順5）。
        // 対象ログの所有者（$log['user_id']）の当日ログを見る。管理者が他人のログを
        // 修正する場合、操作者（$user_id）と所有者が異なるため取り違えないこと。
        $logs = Repository::logs_for_date($log['user_id'], $log['work_date']);
        $simulated = array_map(static function (array $l) use ($log_id, $corrected_mysql): array {
            if ($l['log_id'] === $log_id) {
                $l['punched_at'] = $corrected_mysql;
            }
            return $l;
        }, $logs);
        usort($simulated, static fn(array $a, array $b): int => strtotime($a['punched_at']) <=> strtotime($b['punched_at']));

        if (!StatusCalculator::is_chronologically_consistent($simulated)) {
            return self::fail(
                'inconsistent',
                '修正後の打刻順序に矛盾があります（出勤より前の退勤等）。内容をご確認ください。',
                $log['work_date']
            );
        }

        $ok = Repository::correct_punch($log_id, $log['punched_at'], $corrected_mysql, $reason, $user_id);
        if (!$ok) {
            return self::fail('db_error', '修正の保存に失敗しました。時間をおいて再度お試しください。', $log['work_date']);
        }

        return [
            'ok'         => true,
            'code'       => 'ok',
            'message'    => '打刻を修正しました。',
            'work_date'  => $log['work_date'],
            'log_id'     => $log_id,
            'punch_type' => $log['punch_type'],
            'punched_at' => $corrected_mysql,
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

    // ── 2d 補完ロジック用ヘルパー（DB/WPに触れない純粋関数） ──────

    /**
     * 当日ログの中で、指定 punch_type の最後の打刻時刻を UNIX 秒で返す。
     * $logs は punched_at 昇順である前提（Repository が保証）。
     *
     * @param array<int, array{punch_type:string, punched_at:string}> $logs
     */
    private static function last_punch_ts(array $logs, string $punch_type): ?int
    {
        $last = null;
        foreach ($logs as $log) {
            if (($log['punch_type'] ?? '') === $punch_type) {
                $ts = strtotime((string) ($log['punched_at'] ?? ''));
                if ($ts !== false) {
                    $last = $ts;
                }
            }
        }
        return $last;
    }

    /**
     * 'H:i' 形式の時刻を $base_date の日付として解釈し、$not_before_ts 以降になるよう
     * 必要なら1日繰り上げる（§3.2 ケースB：休憩の開始・終了は日をまたぎ得る）。
     *
     * 例：出勤が前日23:50、休憩開始の入力が「00:10」→ $base_date のままでは
     * 出勤より前になってしまうため、翌日として解釈し直す。
     *
     * @return int|null 形式が不正、または1日繰り上げても $not_before_ts に届かない場合は null
     */
    private static function parse_time_on_or_after(string $hm, string $base_date, int $not_before_ts): ?int
    {
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $hm, $m)) {
            return null;
        }

        $ts = strtotime(sprintf('%s %02d:%02d:00', $base_date, (int) $m[1], (int) $m[2]));
        if ($ts === false) {
            return null;
        }

        if ($ts < $not_before_ts) {
            $ts = strtotime('+1 day', $ts);
            if ($ts === false || $ts < $not_before_ts) {
                return null;
            }
        }

        return $ts;
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
