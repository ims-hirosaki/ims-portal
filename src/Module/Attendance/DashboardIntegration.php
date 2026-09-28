<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ダッシュボード（`/portal/`）連携（3m）。
 *
 * core を改修せず、ims_portal_register_tile フックで「月次勤怠表」タイルを自己登録する
 * （00_portal.md §3.2.4 の「月次勤務表」タイル。名称はユーザー指定により「月次勤怠表」とした）。
 * 3a〜3lではこのタイルが未登録で、ポータルから /portal/attendance/ へ入る導線が無かった。
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
        ]);
    }
}
