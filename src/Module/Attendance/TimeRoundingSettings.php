<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 打刻の丸め単位設定の読み書き（要件定義書に無い追加仕様。ユーザー確認済み）。
 *
 * wp_options に単一の配列オプションとして保存する（Module\Timecard\Settings と同じ方針）。
 * 出勤・退勤・休憩の丸め方向自体は固定（WorkTimeCalculator 冒頭コメント参照）で、
 * ここで変更できるのは「何分単位で丸めるか」のみ。
 */
final class TimeRoundingSettings
{
    public const OPTION = 'ims_time_rounding_settings';

    /** 選択できる丸め単位（分）。1は「丸めない」。 */
    public const UNITS = [1, 5, 10, 15, 20, 30, 60];

    private const DEFAULTS = [
        'rounding_minutes' => 15,
    ];

    public static function minutes(): int
    {
        $saved = get_option(self::OPTION, []);
        if (!is_array($saved)) {
            $saved = [];
        }
        return self::normalize($saved['rounding_minutes'] ?? self::DEFAULTS['rounding_minutes']);
    }

    public static function update(int $minutes): void
    {
        update_option(self::OPTION, ['rounding_minutes' => self::normalize($minutes)], false);
    }

    /** 選択肢に無い値は既定値（15分）にフォールバックする（DB/WPに触れない純粋関数）。 */
    public static function normalize(mixed $minutes): int
    {
        $minutes = (int) $minutes;
        return in_array($minutes, self::UNITS, true) ? $minutes : self::DEFAULTS['rounding_minutes'];
    }
}
