<?php

declare(strict_types=1);

namespace IMS\Module\Timecard;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 打刻ログの読み書き（02 §5.1）。2b で参照系、2c で挿入（punch）を実装した。
 * 修正（UPDATE + corrections への追記）は 2e で追加する。
 * 2iで誤打刻の取り消し（void_punch()）を追加した。
 *
 * すべてのクエリは $wpdb->prepare を通す。読み取り系は基本的に user_id で絞り、
 * 他人のログを読ませない（例外：2f の管理者向け照会 search_logs() は
 * 監査目的で全社員を横断検索する。呼び出し側で ims_manage_users を必ず要求する）。
 * 物理削除するメソッドは意図的に置かない（§3.5・§7.5 の保持要件）。取り消し済みの
 * 打刻も voided_* 列を立てるだけで行は残す。logs_for_date()/logs_for_month()
 * （本人の打刻コンソール用）は取り消し済みを除外するが、search_logs()
 * （管理者向け監査画面）は除外しない。
 */
final class Repository
{
    /**
     * 指定ユーザーの、ある「勤務日」の打刻ログを昇順で取得する。
     * work_date は帰属日付（退勤の日またぎは登録時に解決済み）。
     *
     * @return array<int, array{log_id:int, punch_type:string, punched_at:string, is_auto_filled:int, note:?string}>
     */
    public static function logs_for_date(int $user_id, string $work_date): array
    {
        global $wpdb;
        $table = Schema::logs_table();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT log_id, punch_type, punched_at, is_auto_filled, note
                 FROM {$table}
                 WHERE user_id = %d AND work_date = %s AND voided_at IS NULL
                 ORDER BY punched_at ASC, log_id ASC",
                $user_id,
                $work_date
            ),
            ARRAY_A
        );

        return self::normalize_rows($rows);
    }

    /**
     * 指定ユーザーの、ある年月（勤務日ベース）の全打刻ログを取得し、日付ごとにまとめる。
     *
     * @return array<string, array<int, array{log_id:int, punch_type:string, punched_at:string, is_auto_filled:int, note:?string}>>
     *         キーは 'Y-m-d'、値はその日の昇順ログ配列。打刻のある日だけが含まれる。
     */
    public static function logs_for_month(int $user_id, int $year, int $month): array
    {
        global $wpdb;
        $table = Schema::logs_table();

        $first = sprintf('%04d-%02d-01', $year, $month);
        // 月末日は date() に任せる（うるう年・月の大小を自動処理）
        $last_day = (int) date('t', strtotime($first));
        $last     = sprintf('%04d-%02d-%02d', $year, $month, $last_day);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT log_id, work_date, punch_type, punched_at, is_auto_filled, note
                 FROM {$table}
                 WHERE user_id = %d AND work_date BETWEEN %s AND %s AND voided_at IS NULL
                 ORDER BY work_date ASC, punched_at ASC, log_id ASC",
                $user_id,
                $first,
                $last
            ),
            ARRAY_A
        );

        $by_date = [];
        foreach ($rows as $row) {
            $date = (string) $row['work_date'];
            unset($row['work_date']);
            $by_date[$date][] = self::normalize_row($row);
        }

        return $by_date;
    }

    // ── 書き込み（2c） ──────────────────────────────────────

    /**
     * 打刻ログを1件挿入する（§5.1）。
     *
     * 呼び出し側（PunchService）が work_date・punched_at の決定と状態遷移の検証を
     * 済ませている前提。ここは「渡された値をそのまま安全に INSERT する」だけに徹する。
     *
     * @param array{
     *     user_id:int, work_date:string, punch_type:string, punched_at:string,
     *     is_auto_filled?:int, ip_address?:?string,
     *     gps_latitude?:?float, gps_longitude?:?float, note?:?string
     * } $data
     * @return int 挿入された log_id。失敗時は 0。
     */
    public static function insert_punch(array $data): int
    {
        global $wpdb;

        $row = [
            'user_id'        => (int) $data['user_id'],
            'work_date'      => (string) $data['work_date'],
            'punch_type'     => (string) $data['punch_type'],
            'punched_at'     => (string) $data['punched_at'],
            'is_auto_filled' => (int) ($data['is_auto_filled'] ?? 0),
            'ip_address'     => $data['ip_address']   ?? null,
            'gps_latitude'   => $data['gps_latitude'] ?? null,
            'gps_longitude'  => $data['gps_longitude'] ?? null,
            'note'           => $data['note'] ?? null,
        ];
        // created_at は DDL の DEFAULT CURRENT_TIMESTAMP に任せる。

        // NULL を渡す列はフォーマット指定から外せないため、%s/%f を型に合わせて並べる。
        $formats = ['%d', '%s', '%s', '%s', '%d', '%s', '%f', '%f', '%s'];

        $ok = $wpdb->insert(Schema::logs_table(), $row, $formats);

        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * 複数件の打刻ログを1つのDBトランザクションで挿入する（§3.2 ケースA/B）。
     * 途中の1件でも失敗したら即ロールバックし、false を返す（部分保存を残さない）。
     *
     * `attendance_logs` が InnoDB であることが前提（2d 実装前に確認済み）。
     *
     * @param array<int, array<string, mixed>> $rows insert_punch() と同じキー形式の配列
     * @return array<int, int>|false 成功時は $rows と同じ順序の log_id 配列。失敗時は false
     */
    public static function insert_punches_atomic(array $rows): array|false
    {
        global $wpdb;

        $wpdb->query('START TRANSACTION');

        $ids = [];
        foreach ($rows as $row) {
            $id = self::insert_punch($row);
            if ($id === 0) {
                $wpdb->query('ROLLBACK');
                return false;
            }
            $ids[] = $id;
        }

        $wpdb->query('COMMIT');
        return $ids;
    }

    /**
     * 多重サブミットのサーバー側ガード（§7.1）。
     * 同一ユーザー・同一 punch_type のログが直近 $window_sec 秒以内に存在するかを見る。
     *
     * work_date では絞らない。日付境界をまたぐ連打も等しく弾きたいため、
     * 実時刻（punched_at）だけで判定する。
     */
    public static function has_recent_punch(int $user_id, string $punch_type, string $now_mysql, int $window_sec = 5): bool
    {
        global $wpdb;
        $table = Schema::logs_table();

        $since = gmdate('Y-m-d H:i:s', strtotime($now_mysql) - $window_sec);

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$table}
                 WHERE user_id = %d AND punch_type = %s AND punched_at >= %s",
                $user_id,
                $punch_type,
                $since
            )
        );

        return (int) $count > 0;
    }

    /**
     * 「開いている勤務」＝ clock_in はあるが clock_out がない勤務のうち、最も新しいものを返す。
     * 出勤時刻（clock_in_at）も併せて返す。
     *
     * 日またぎ勤務で、深夜の休憩・退勤打刻を出勤日側へ寄せるために使う。
     *
     * **経過時間による足切り（N時間以内か）はここでは行わない。**
     * SQL に埋め込むと WordPress 無しでテストできなくなるため、この関数は候補を返すことに徹し、
     * 採用の可否は純粋関数 PunchService::decide_work_date() が判断する。
     * $lookback_days はインデックス（idx_user_date）を効かせるための粗い絞り込みでしかない。
     *
     * @return array{work_date:string, clock_in_at:string}|null 開いている勤務がなければ null
     */
    public static function open_shift(int $user_id, string $today, int $lookback_days = 2): ?array
    {
        global $wpdb;
        $table = Schema::logs_table();

        $from = gmdate('Y-m-d', strtotime($today . ' -' . max(0, $lookback_days) . ' day'));

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT work_date,
                        MIN(CASE WHEN punch_type = 'clock_in' THEN punched_at END) AS clock_in_at
                 FROM {$table}
                 WHERE user_id = %d
                   AND work_date BETWEEN %s AND %s
                 GROUP BY work_date
                 HAVING clock_in_at IS NOT NULL
                    AND SUM(punch_type = 'clock_out') = 0
                 ORDER BY clock_in_at DESC
                 LIMIT 1",
                $user_id,
                $from,
                $today
            ),
            ARRAY_A
        );

        if (!is_array($row) || empty($row['clock_in_at'])) {
            return null;
        }

        return [
            'work_date'   => (string) $row['work_date'],
            'clock_in_at' => (string) $row['clock_in_at'],
        ];
    }

    // ── 打刻修正（2e） ──────────────────────────────────────

    /**
     * log_id 指定で1件取得する（§3.4：対象ログの本人確認・現在値表示に使う）。
     *
     * @return array{log_id:int, user_id:int, work_date:string, punch_type:string, punched_at:string, is_auto_filled:int}|null
     */
    public static function find_log(int $log_id): ?array
    {
        global $wpdb;
        $table = Schema::logs_table();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT log_id, user_id, work_date, punch_type, punched_at, is_auto_filled, voided_at
                 FROM {$table} WHERE log_id = %d",
                $log_id
            ),
            ARRAY_A
        );

        if (!is_array($row)) {
            return null;
        }

        return [
            'log_id'         => (int) $row['log_id'],
            'user_id'        => (int) $row['user_id'],
            'work_date'      => (string) $row['work_date'],
            'punch_type'     => (string) $row['punch_type'],
            'punched_at'     => (string) $row['punched_at'],
            'is_auto_filled' => (int) $row['is_auto_filled'],
            'is_voided'      => $row['voided_at'] !== null,
        ];
    }

    /**
     * 打刻の取り消し（2i：誤打刻の取り消し。要件定義書には無い追加仕様）。
     *
     * §3.5・§7.5の保持要件のため物理削除はせず、voided_* 列を立てるだけにする
     * （物理削除するメソッドを意図的に置かないクラス全体の方針はそのまま維持する）。
     * 取り消し済みの打刻は logs_for_date()/logs_for_month() から除外され、
     * 本人の打刻履歴画面では最初から無かったかのように表示される
     * （ユーザー確認済み。監査目的の search_logs() では除外しない）。
     */
    public static function void_punch(int $log_id, int $voided_by, string $reason): bool
    {
        global $wpdb;
        return $wpdb->update(
            Schema::logs_table(),
            [
                'voided_at'   => current_time('mysql'),
                'voided_by'   => $voided_by,
                'void_reason' => $reason,
            ],
            ['log_id' => $log_id],
            ['%s', '%d', '%s'],
            ['%d']
        ) !== false;
    }

    /**
     * 打刻修正を1トランザクションで保存する（§3.4 手順4）。
     * 先に修正履歴（attendance_corrections）へ記録してから、対象ログを新しい日時で
     * 上書きする。どちらかが失敗すればロールバックする（監査証跡だけ残ることを防ぐ）。
     */
    public static function correct_punch(
        int $log_id,
        string $original_datetime,
        string $corrected_datetime,
        string $reason,
        int $corrected_by
    ): bool {
        global $wpdb;
        $logs_table = Schema::logs_table();
        $corr_table = Schema::corrections_table();

        $wpdb->query('START TRANSACTION');

        $inserted = $wpdb->insert(
            $corr_table,
            [
                'log_id'             => $log_id,
                'original_datetime'  => $original_datetime,
                'corrected_datetime' => $corrected_datetime,
                'reason'             => $reason,
                'corrected_by'       => $corrected_by,
            ],
            ['%d', '%s', '%s', '%s', '%d']
        );
        if (!$inserted) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        $updated = $wpdb->update(
            $logs_table,
            ['punched_at' => $corrected_datetime],
            ['log_id' => $log_id],
            ['%s'],
            ['%d']
        );
        if ($updated === false) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        $wpdb->query('COMMIT');
        return true;
    }

    /**
     * 打刻の追加を1トランザクションで保存する（2h：存在しない打刻の新規作成。
     * 要件定義書には無い追加仕様。correct_punch() と同じ「先に監査証跡→本体」の順序）。
     *
     * 新規ログを先に挿入し、その log_id で追加履歴（attendance_corrections。
     * original_datetime は NULL＝追加を意味する）を記録する。どちらかが失敗すれば
     * ロールバックする。
     *
     * @return int 成功時は新規ログの log_id。失敗時は 0。
     */
    public static function add_punch(int $user_id, string $work_date, string $punch_type, string $punched_at, string $reason, int $added_by): int
    {
        global $wpdb;
        $logs_table = Schema::logs_table();
        $corr_table = Schema::corrections_table();

        $wpdb->query('START TRANSACTION');

        $inserted_log = $wpdb->insert(
            $logs_table,
            [
                'user_id'        => $user_id,
                'work_date'      => $work_date,
                'punch_type'     => $punch_type,
                'punched_at'     => $punched_at,
                'is_auto_filled' => 0,
            ],
            ['%d', '%s', '%s', '%s', '%d']
        );
        if (!$inserted_log) {
            $wpdb->query('ROLLBACK');
            return 0;
        }
        $log_id = (int) $wpdb->insert_id;

        // NULL を渡す列もフォーマット指定から外せないため、型に合わせた %s を置く
        // （$wpdb->insert() は値が null なら実際のフォーマットに関わらず SQL の NULL を発行する。
        // insert_punch() の ip_address 等と同じ作法）。
        $inserted_corr = $wpdb->insert(
            $corr_table,
            [
                'log_id'             => $log_id,
                'original_datetime'  => null,
                'corrected_datetime' => $punched_at,
                'reason'             => $reason,
                'corrected_by'       => $added_by,
            ],
            ['%d', '%s', '%s', '%s', '%d']
        );
        if (!$inserted_corr) {
            $wpdb->query('ROLLBACK');
            return 0;
        }

        $wpdb->query('COMMIT');
        return $log_id;
    }

    /**
     * 指定ログの修正・追加履歴を古い順で取得する（§3.4「修正履歴の記録」・§4.2 証跡確認用）。
     * original_datetime が null の行は「打刻の追加」（2h）を表す。
     *
     * @return array<int, array{id:int, original_datetime:?string, corrected_datetime:string, reason:string, corrected_by:int, corrected_at:string}>
     */
    public static function corrections_for_log(int $log_id): array
    {
        global $wpdb;
        $table = Schema::corrections_table();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, original_datetime, corrected_datetime, reason, corrected_by, corrected_at
                 FROM {$table} WHERE log_id = %d ORDER BY corrected_at ASC, id ASC",
                $log_id
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return [];
        }
        return array_map(static function (array $row): array {
            return [
                'id'                 => (int) $row['id'],
                'original_datetime'  => $row['original_datetime'] !== null ? (string) $row['original_datetime'] : null,
                'corrected_datetime' => (string) $row['corrected_datetime'],
                'reason'             => (string) $row['reason'],
                'corrected_by'       => (int) $row['corrected_by'],
                'corrected_at'       => (string) $row['corrected_at'],
            ];
        }, $rows);
    }

    // ── 管理者向け打刻ログ照会（2f） ─────────────────────────

    /** search_logs() の既定・上限件数（§4.2 に明記なし。監査画面の暴走防止のため設ける）。 */
    private const SEARCH_DEFAULT_LIMIT = 500;

    /**
     * 打刻ログの横断検索（社員管理＞打刻ログ照会・§4.2）。
     *
     * 社員名・社員番号による絞り込みは呼び出し側（AdminLogSearchPage）で
     * user_id の配列に解決してから渡すこと（Repository は自身のテーブルの
     * SQL だけを担当し、wp_users/wp_usermeta への参照は持ち込まない）。
     *
     * @param array{
     *   user_ids?: array<int,int>, date_from?: string, date_to?: string,
     *   punch_type?: string, is_auto_filled?: bool, has_correction?: bool, limit?: int
     * } $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     *         total は LIMIT 適用前の一致件数（rows が LIMIT で切られたかを画面側が判断できるように）。
     */
    public static function search_logs(array $filters): array
    {
        global $wpdb;
        $logs_table = Schema::logs_table();
        $corr_table = Schema::corrections_table();
        $has_correction_expr = "EXISTS (SELECT 1 FROM {$corr_table} c WHERE c.log_id = l.log_id)";

        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['user_ids'])) {
            $ids = array_values(array_map('intval', (array) $filters['user_ids']));
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $where[] = "l.user_id IN ({$placeholders})";
            array_push($params, ...$ids);
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'l.work_date >= %s';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'l.work_date <= %s';
            $params[] = (string) $filters['date_to'];
        }
        if (!empty($filters['punch_type'])) {
            $where[] = 'l.punch_type = %s';
            $params[] = (string) $filters['punch_type'];
        }
        if (array_key_exists('is_auto_filled', $filters) && $filters['is_auto_filled'] !== null) {
            $where[] = 'l.is_auto_filled = %d';
            $params[] = $filters['is_auto_filled'] ? 1 : 0;
        }
        $filter_has_correction = array_key_exists('has_correction', $filters) && $filters['has_correction'] !== null;
        if ($filter_has_correction) {
            $where[] = "{$has_correction_expr} = %d";
            $params[] = $filters['has_correction'] ? 1 : 0;
        }

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM {$logs_table} l WHERE {$where_sql}";
        $total = (int) $wpdb->get_var($params ? $wpdb->prepare($count_sql, $params) : $count_sql);

        $limit = max(1, (int) ($filters['limit'] ?? self::SEARCH_DEFAULT_LIMIT));

        $select_sql = "SELECT l.log_id, l.user_id, l.work_date, l.punch_type, l.punched_at,
                              l.is_auto_filled, l.ip_address, l.gps_latitude, l.gps_longitude,
                              l.voided_at, l.voided_by, l.void_reason,
                              {$has_correction_expr} AS has_correction
                       FROM {$logs_table} l
                       WHERE {$where_sql}
                       ORDER BY l.work_date DESC, l.punched_at DESC, l.log_id DESC
                       LIMIT %d";
        $select_params = $params;
        $select_params[] = $limit;

        $rows = $wpdb->get_results($wpdb->prepare($select_sql, $select_params), ARRAY_A);

        return [
            'rows'  => is_array($rows) ? array_map([self::class, 'normalize_search_row'], $rows) : [],
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{log_id:int, user_id:int, work_date:string, punch_type:string, punched_at:string, is_auto_filled:int, ip_address:?string, has_gps:bool, has_correction:bool, is_voided:bool, voided_by:?int, voided_at:?string, void_reason:?string}
     */
    private static function normalize_search_row(array $row): array
    {
        return [
            'log_id'         => (int) $row['log_id'],
            'user_id'        => (int) $row['user_id'],
            'work_date'      => (string) $row['work_date'],
            'punch_type'     => (string) $row['punch_type'],
            'punched_at'     => (string) $row['punched_at'],
            'is_auto_filled' => (int) $row['is_auto_filled'],
            'ip_address'     => isset($row['ip_address']) ? (string) $row['ip_address'] : null,
            'has_gps'        => $row['gps_latitude'] !== null && $row['gps_longitude'] !== null,
            'has_correction' => (bool) $row['has_correction'],
            'is_voided'      => $row['voided_at'] !== null,
            'voided_by'      => $row['voided_by'] !== null ? (int) $row['voided_by'] : null,
            'voided_at'      => $row['voided_at'] !== null ? (string) $row['voided_at'] : null,
            'void_reason'    => $row['void_reason'] !== null ? (string) $row['void_reason'] : null,
        ];
    }

    // ── 排他制御（2c） ──────────────────────────────────────

    /**
     * 打刻処理をユーザー単位で直列化するロックを取得する（§7.1 をサーバー側で確実にする）。
     *
     * 「5秒重複チェック → 状態検証 → INSERT」の間に別リクエストが割り込むと、
     * 両方が検証を通過して clock_in が同一 work_date に 2 件入り得る。
     * 1日1件の制約は DDL の UNIQUE ではなく**アプリ層で担保する設計**（§5.1）のため、
     * ここが唯一の防波堤になる。
     *
     * 行ロック（SELECT ... FOR UPDATE）ではなく MySQL の名前付きロックを使う理由：
     * 排他したい対象の行がまだ存在せず、行ロックが効かないため。
     * 2d でトランザクションを本格導入するまでは、こちらのほうが単純で読みやすい。
     *
     * ⚠ 名前付きロックの名前空間は **MySQL サーバー全体で共有**される。
     *   共有ホスティング（エックスサーバー）では他サイトと衝突し得るため、
     *   DB名とテーブル接頭辞のハッシュを名前に混ぜて分離する。
     *   （MySQL 5.7+ のロック名上限は 64 文字。下記の組み立てで約 30 文字に収まる。）
     *
     * @return bool 取得できたら true。タイムアウト・エラーは false。
     */
    public static function lock_user_punches(int $user_id, int $timeout_sec = 3): bool
    {
        global $wpdb;

        // GET_LOCK は 1=取得 / 0=タイムアウト / NULL=エラー を返す。3値すべてを扱う。
        $got = $wpdb->get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::lock_name($user_id), max(1, $timeout_sec))
        );

        return $got !== null && (int) $got === 1;
    }

    /**
     * 打刻ロックを解放する。呼び出し側は必ず finally で呼ぶこと。
     * （PHP プロセスが落ちた場合は接続断で MySQL 側が自動解放するため、ロックは残らない。）
     */
    public static function unlock_user_punches(int $user_id): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name($user_id)));
    }

    /** ロック名。取得と解放で同一文字列になるよう1か所に閉じ込める。 */
    private static function lock_name(int $user_id): string
    {
        global $wpdb;
        $scope = substr(md5((string) $wpdb->dbname . '|' . $wpdb->prefix), 0, 12);
        return 'ims_punch_' . $scope . '_' . $user_id;
    }

    /**
     * @param array<int, array<string, mixed>>|null $rows
     * @return array<int, array{log_id:int, punch_type:string, punched_at:string, is_auto_filled:int, note:?string}>
     */
    private static function normalize_rows(?array $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }
        return array_map([self::class, 'normalize_row'], $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{log_id:int, punch_type:string, punched_at:string, is_auto_filled:int, note:?string}
     */
    private static function normalize_row(array $row): array
    {
        return [
            'log_id'         => (int) ($row['log_id'] ?? 0),
            'punch_type'     => (string) ($row['punch_type'] ?? ''),
            'punched_at'     => (string) ($row['punched_at'] ?? ''),
            'is_auto_filled' => (int) ($row['is_auto_filled'] ?? 0),
            'note'           => isset($row['note']) ? (string) $row['note'] : null,
        ];
    }
}
