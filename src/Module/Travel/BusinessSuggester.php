<?php

declare(strict_types=1);

namespace IMS\Module\Travel;

/**
 * 通勤費の事業のサジェスト（04 §3.1「自動サジェストのロジック」）。DB/WPに触れない純粋関数。
 *
 * その日の事業別時間（wp_project_hours）から決める：
 * ・1事業だけ → single（自動で設定してよい）
 * ・複数で最多の事業が1つ → top（候補として示すだけ。スタッフが選ぶ）
 * ・複数で最多が並ぶ／登録なし → tie / none（要選択）
 */
final class BusinessSuggester
{
    public const SINGLE = 'single';
    public const TOP    = 'top';
    public const TIE    = 'tie';
    public const NONE   = 'none';

    /**
     * @param array<int, int> $minutes_by_business 事業ID → 分
     * @return array{business_id: ?int, basis: string}
     */
    public static function suggest(array $minutes_by_business): array
    {
        $minutes = array_filter($minutes_by_business, static fn($m): bool => (int) $m > 0);
        if ($minutes === []) {
            return ['business_id' => null, 'basis' => self::NONE];
        }
        if (count($minutes) === 1) {
            return ['business_id' => (int) array_key_first($minutes), 'basis' => self::SINGLE];
        }

        $max  = max($minutes);
        $tops = array_keys(array_filter($minutes, static fn($m): bool => $m === $max));
        if (count($tops) > 1) {
            return ['business_id' => null, 'basis' => self::TIE];
        }
        return ['business_id' => (int) $tops[0], 'basis' => self::TOP];
    }
}
