<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 全社共通の「所定の始業時刻」の読み書き（03 §3.3「有給日の自動割り当て」。ユーザー確認済み）。
 *
 * 社員ごとの始業時刻は持たない。有給日は「この時刻〜この時刻＋所定労働時間」で事業別時間を自動割り当てする。
 * wp_options に単一の配列で保存する（TimeRoundingSettings と同じ方針）。値は 0時起点の分（8:30 → 510）。
 */
final class WorkStartSettings
{
    public const OPTION = 'ims_work_start_settings';

    /** 初期値 8:30（ユーザー指定）。 */
    public const DEFAULT_MINUTES = 8 * 60 + 30;

    public static function minutes(): int
    {
        $saved = get_option(self::OPTION, []);
        if (!is_array($saved)) {
            return self::DEFAULT_MINUTES;
        }
        return self::normalize($saved['start_minutes'] ?? self::DEFAULT_MINUTES);
    }

    public static function update(int $minutes): void
    {
        update_option(self::OPTION, ['start_minutes' => self::normalize($minutes)], false);
    }

    /**
     * 0:00〜23:59 の範囲外・不正値は初期値（8:30）にする（DB/WPに触れない純粋関数）。
     */
    public static function normalize(mixed $minutes): int
    {
        if (!is_numeric($minutes)) {
            return self::DEFAULT_MINUTES;
        }
        $minutes = (int) $minutes;
        return ($minutes >= 0 && $minutes < 24 * 60) ? $minutes : self::DEFAULT_MINUTES;
    }

    /** 'HH:MM' を分にする。形式が違えば null（純粋関数）。 */
    public static function parse(string $hhmm): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $m)) {
            return null;
        }
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($h > 23 || $i > 59) {
            return null;
        }
        return $h * 60 + $i;
    }

    /** 分を 'HH:MM' にする（純粋関数）。 */
    public static function format(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
