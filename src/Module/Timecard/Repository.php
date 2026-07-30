<?php

declare(strict_types=1);

namespace IMS\Module\Timecard;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 打刻ログの読み取り（02 §5.1）。2b では参照系のみを実装する。
 * 書き込み（punch）は 2c、修正は 2e で追加する。
 *
 * すべてのクエリは $wpdb->prepare を通し、user_id で必ず絞る（他人のログを読ませない）。
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

    /**
     * 今日（サーバー時刻の暦日）の勤務日に属する打刻ログを取得する。
     * 深夜帯の日またぎ勤務では、暦日と work_date がずれる場合があるが、
     * 「打刻コンソールの当日表示」は暦日基準で十分（§5.2 は当日の状態を出すUI）。
     */
    public static function logs_for_today(int $user_id): array
    {
        return self::logs_for_date($user_id, current_time('Y-m-d'));
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
