<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

use IMS\Support\UserRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ダッシュボード（`/portal/`）連携（3m）。
 *
 * core を改修せず、ims_portal_register_tile フックで「月次勤怠表」タイルを自己登録する
 * （00_portal.md §3.2.4 の「月次勤務表」タイル。名称はユーザー指定により「月次勤怠表」とした）。
 * 3a〜3lではこのタイルが未登録で、ポータルから /portal/attendance/ へ入る導線が無かった。
 *
 * 3m-2：未提出バッジ（§3.2.4「月末が近く未提出の場合に警告バッジ」）。段階の判定は
 * SubmissionReminderCalculator（純粋関数）に任せ、ここは対象月の状態を集めるだけにする。
 * 対象は今日が属する月と、その前の2か月（提出期限を過ぎて未提出のまま残っている月を拾うため）。
 * 勤怠の記録が1日も無い月（運用開始前・入社前など）は対象外。
 */
final class DashboardIntegration
{
    public static function init(): void
    {
        add_action('ims_portal_register_tile', [self::class, 'register_tile']);
    }

    public static function register_tile(string $registry_class): void
    {
        $registry_class::add([
            'id'       => 'attendance',
            'label'    => __('月次勤怠表', 'ims-portal'),
            'icon'     => '',
            'url'      => '/portal/attendance/',
            'desc'     => __('月次の勤怠入力・提出', 'ims-portal'),
            'priority' => 20, // §3.2.4 の一覧で「打刻」（10）の次
            'caps'     => ['ims_use_portal'],
            'badge'    => [self::class, 'badge'],
        ]);
    }

    /** 前の何か月までさかのぼって未提出を確認するか。 */
    private const LOOKBACK_MONTHS = 2;

    /**
     * @return array{text:string, tone:string}|null
     */
    public static function badge(int $user_id): ?array
    {
        if (UserRepository::is_retired($user_id)) {
            return null;
        }

        $today   = current_time('Y-m-d');
        $current = SalaryCycleSettings::year_month_for_date($today);
        [$year, $month] = array_map('intval', explode('-', $current));

        $levels = [];
        for ($offset = 0; $offset <= self::LOOKBACK_MONTHS; $offset++) {
            $ym     = date('Y-m', mktime(0, 0, 0, $month - $offset, 1, $year));
            $period = SalaryCycleSettings::period_for_year_month($ym);
            if ($period['start'] > $today) {
                continue;
            }
            if (!DailyAttendanceRepository::has_any_in_range($user_id, $period['start'], $period['end'])) {
                continue;
            }
            $levels[] = SubmissionReminderCalculator::level(
                $today,
                $period['deadline'],
                MonthlySummaryService::current_status($user_id, $ym)
            );
        }

        $tone = SubmissionReminderCalculator::worst($levels);
        return $tone === null ? null : ['text' => __('未提出', 'ims-portal'), 'tone' => $tone];
    }
}
