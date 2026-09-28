<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ダッシュボード「月次勤怠表」タイルの未提出バッジの判定（00_portal.md §3.2.4
 * 「月末が近く未提出の場合に警告バッジ」。3m-2）。DB/WordPressに触れない純粋ロジック。
 *
 * 段階（ユーザー指定。「締め日」は給与計算サイクル設定の提出期限＝ PayPeriodCalculator の deadline）：
 * ・提出期限の3日前 〜 提出期限当日   … neutral（グレー）
 * ・提出期限の翌日 〜 提出期限の3日後 … warning（金＝黄）
 * ・提出期限の4日後以降               … danger（朱＝赤）
 * 未提出（draft）・差し戻し中の月だけが対象。提出済み以降（submitted / checked / confirmed）は出さない。
 */
final class SubmissionReminderCalculator
{
    public const NEUTRAL = 'neutral';
    public const WARNING = 'warning';
    public const DANGER  = 'danger';

    /** 何日前からグレーを出すか。 */
    public const NOTICE_DAYS_BEFORE = 3;

    /** 提出期限後、何日目まで黄色にするか（これを過ぎると赤）。 */
    public const GRACE_DAYS_AFTER = 3;

    private const SEVERITY = [self::NEUTRAL => 1, self::WARNING => 2, self::DANGER => 3];

    /**
     * 1か月分の段階。バッジを出さない場合は null。
     *
     * @param string $today    'Y-m-d'
     * @param string $deadline 'Y-m-d'（提出期限）
     * @param string $status   wp_monthly_summary.status（行が無ければ draft）
     */
    public static function level(string $today, string $deadline, string $status): ?string
    {
        if (!in_array($status, MonthlySummaryCalculator::SUBMITTABLE_FROM, true)) {
            return null;
        }

        $days = self::days_between($deadline, $today); // 正：期限を過ぎた日数／負：期限まであと何日

        if ($days < -self::NOTICE_DAYS_BEFORE) {
            return null;
        }
        if ($days <= 0) {
            return self::NEUTRAL;
        }
        if ($days <= self::GRACE_DAYS_AFTER) {
            return self::WARNING;
        }
        return self::DANGER;
    }

    /**
     * 複数月の段階のうち、いちばん重いもの（danger > warning > neutral）。すべて null なら null。
     *
     * @param array<int, ?string> $levels
     */
    public static function worst(array $levels): ?string
    {
        $worst = null;
        foreach ($levels as $level) {
            if ($level === null) {
                continue;
            }
            if ($worst === null || self::SEVERITY[$level] > self::SEVERITY[$worst]) {
                $worst = $level;
            }
        }
        return $worst;
    }

    /** $from から $to まで何日か（'Y-m-d' 同士。$to が後なら正）。 */
    private static function days_between(string $from, string $to): int
    {
        $a = new \DateTimeImmutable($from . ' 00:00:00', new \DateTimeZone('UTC'));
        $b = new \DateTimeImmutable($to . ' 00:00:00', new \DateTimeZone('UTC'));
        return (int) $a->diff($b)->format('%r%a');
    }
}
