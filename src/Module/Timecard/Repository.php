<?php

declare(strict_types=1);

namespace IMS\Module\Timecard;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 打刻ログの読み書き（02 §5.1）。2b で参照系、2c で挿入（punch）を実装した。
 * 修正（UPDATE + corrections への追記）は 2e で追加する。
 *
 * すべてのクエリは $wpdb->prepare を通し、user_id で必ず絞る（他人のログを読ませない）。
 * 物理削除するメソッドは意図的に置かない（§3.4・§7.4 の保持要件）。
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
                 WHERE user_id = %d AND work_date = %s
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
                 WHERE user_id = %d AND work_date BETWEEN %s AND %s
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
     * 「開いている勤務」＝ clock_in はあるが clock_out がない work_date を返す。
     * 直近 $lookback_days 日分（当日を含む）だけを見て、最も新しいものを返す。
     *
     * 日またぎ勤務で、深夜の休憩・退勤打刻を出勤日側へ寄せるために使う
     * （PunchService::resolve_work_date を参照）。開いている勤務がなければ null。
     */
    public static function open_shift_date(int $user_id, string $today, int $lookback_days = 1): ?string
    {
        global $wpdb;
        $table = Schema::logs_table();

        $from = gmdate('Y-m-d', strtotime($today . ' -' . max(0, $lookback_days) . ' day'));

        $date = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT work_date
                 FROM {$table}
                 WHERE user_id = %d
                   AND work_date BETWEEN %s AND %s
                 GROUP BY work_date
                 HAVING SUM(punch_type = 'clock_in') > 0
                    AND SUM(punch_type = 'clock_out') = 0
                 ORDER BY work_date DESC
                 LIMIT 1",
                $user_id,
                $from,
                $today
            )
        );

        return $date !== null ? (string) $date : null;
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
