<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

use IMS\Module\Timecard\Repository as TimecardRepository;
use IMS\Support\UserRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 事業別時間割当ての保存を行うサービス（03_attendance_management.md §3.3）。
 *
 * 打刻データ（02）を読む → 実労働時間を算出する（WorkTimeCalculator） →
 * 4条件バリデーション（ProjectHourCalculator） → 保存する（ProjectHourRepository）、
 * という一連の流れをまとめる。3d時点ではこのサービス自体に画面・APIは無く、
 * 3e（スタッフ向け月次勤務表画面の入力モーダル）から呼び出す想定。
 *
 * 判定に使う「実労働時間」は常に打刻由来の値（WorkTimeCalculator）であり、
 * wp_daily_attendance.actual_minutes（勤怠フラグ適用後、時間休等の加算を含み得る値）
 * ではない。事業に割り当てるのは「実際に働いた時間帯」のみのため（§3.3）。
 *
 * 既知のスコープ外（次スライス以降で対応）：
 * ・有給日の自動割当て（§3.3「有給日の自動割当て」） … 所定の始業・終業"時刻"
 *   （time-of-day）を01モジュールがまだ保持しておらず（scheduled_work_hoursは
 *   時間数のみ）、自動割当てに必要な start_time/end_time を算出できないため見送った。
 *   01モジュールに所定始業・終業時刻を追加するタイミングで実装する。
 */
final class ProjectHourService
{
    /**
     * 指定ユーザー・勤務日の事業別時間割当てを丸ごと保存する（差し替え）。
     *
     * @param array<int, array{business_id:int, start_time:string, end_time:string}> $rows
     * @return true|\WP_Error
     */
    public static function save_day(int $user_id, string $work_date, array $rows)
    {
        $daily = DailyAttendanceRepository::find($user_id, $work_date);
        $flag  = $daily !== null ? (string) $daily['attendance_flag'] : AttendanceFlagCalculator::NONE;

        if (AttendanceFlagCalculator::requires_no_allocation($flag)) {
            return new \WP_Error('not_required', 'この勤怠フラグの日は事業別時間の割当てが不要です。');
        }

        $logs = TimecardRepository::logs_for_date($user_id, $work_date);
        $clock_in_at  = self::find_punch_time($logs, 'clock_in');
        $clock_out_at = self::find_punch_time($logs, 'clock_out');
        if ($clock_in_at === null || $clock_out_at === null) {
            return new \WP_Error('incomplete', 'この日はまだ出勤・退勤の打刻が完了していないため、事業別時間の割当てはできません。');
        }

        $scheduled_minutes = (int) round(UserRepository::get_scheduled_work_hours($user_id) * 60);
        $raw = WorkTimeCalculator::calculate_day($logs, $work_date, $scheduled_minutes);
        if ($raw === null) {
            return new \WP_Error('incomplete', 'この日の実労働時間がまだ算出できません。休憩の打刻がすべて完了しているかご確認ください。');
        }

        $midnight = strtotime($work_date . ' 00:00:00');
        $clock_in_minutes  = self::minutes_since_midnight($clock_in_at, $midnight);
        $clock_out_minutes = self::minutes_since_midnight($clock_out_at, $midnight);
        if ($clock_in_minutes === null || $clock_out_minutes === null) {
            return new \WP_Error('incomplete', '出勤・退勤の打刻時刻を取得できませんでした。');
        }

        $parsed_rows = array_map(static fn(array $row): array => [
            'business_id' => (int) ($row['business_id'] ?? 0),
            'start_time'  => (string) ($row['start_time'] ?? ''),
            'end_time'    => (string) ($row['end_time'] ?? ''),
        ], $rows);

        $errors = ProjectHourCalculator::validate($parsed_rows, $clock_in_minutes, $clock_out_minutes, $raw['actual_minutes']);
        if ($errors !== []) {
            return new \WP_Error('validation', self::first_error_message($errors), $errors);
        }

        foreach ($parsed_rows as $row) {
            $business = BusinessRepository::find($row['business_id']);
            if ($business === null || (int) $business['is_active'] !== 1) {
                return new \WP_Error('validation', '無効な事業、または存在しない事業が指定されています。');
            }
        }

        $to_save = array_map(static fn(array $row): array => [
            'business_id'   => $row['business_id'],
            'start_minutes' => ProjectHourCalculator::parse_minutes($row['start_time']),
            'end_minutes'   => ProjectHourCalculator::parse_minutes($row['end_time']),
        ], $parsed_rows);

        if (!ProjectHourRepository::replace_day($user_id, $work_date, $to_save)) {
            return new \WP_Error('db_error', '事業別時間割当ての保存に失敗しました。');
        }

        return true;
    }

    /** @param array<int, array{punch_type:string, punched_at:string}> $logs */
    private static function find_punch_time(array $logs, string $type): ?string
    {
        foreach ($logs as $log) {
            if (($log['punch_type'] ?? '') === $type) {
                return (string) $log['punched_at'];
            }
        }
        return null;
    }

    private static function minutes_since_midnight(string $datetime, int|false $midnight): ?int
    {
        if ($midnight === false) {
            return null;
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return null;
        }
        return (int) round(($ts - $midnight) / 60);
    }

    /** @param array<int, array{code:string}> $errors */
    private static function first_error_message(array $errors): string
    {
        $messages = [
            'invalid_business'    => '事業が選択されていない行があります。',
            'invalid_time_format' => '時刻の形式が正しくありません。',
            'invalid_order'       => '開始時刻は終了時刻より前である必要があります。',
            'out_of_range'        => '入力した時間帯が、その日の出勤〜退勤の範囲内に収まっていません。',
            'overlapping'         => '時間帯が重複している行があります。',
            'total_mismatch'      => '入力した時間の合計が、その日の実労働時間と一致しません。休憩時間は入力しないでください。',
        ];
        $code = $errors[0]['code'] ?? '';
        return $messages[$code] ?? 'この入力内容には誤りがあります。';
    }
}
