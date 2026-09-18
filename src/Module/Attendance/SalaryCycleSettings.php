<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 給与計算サイクル設定の読み書き（03_attendance_management.md §2.1）。
 *
 * wp_options に単一の配列オプションとして保存する（Module\Timecard\Settings と同じ方針）。
 * 画面（AdminSalaryCycleSettingsPage）は必ずこのクラス経由で設定値を読み書きする。
 *
 * 保存キー：
 *   closing_day … 締め日（既定 eom ＝ 当月末日）  → §2.1 表
 *   payment_day … 支払日（既定 next25 ＝ 翌月25日） → §2.1 表
 *
 * システム管理者（administrator）のみが変更できる。cap判定は画面側（ims_manage_system）で行う。
 */
final class SalaryCycleSettings
{
    public const OPTION = 'ims_salary_cycle_settings';

    /** 締め日の選択肢（値 => 表示ラベル。表示順もこの順）。 */
    public const CLOSING_DAYS = [
        'day20'  => '当月20日',
        'day25'  => '当月25日',
        'eom'    => '当月末日',
        'next20' => '翌月20日',
        'next25' => '翌月25日',
    ];

    /** 支払日の選択肢（値 => 表示ラベル。表示順もこの順）。 */
    public const PAYMENT_DAYS = [
        'day10'  => '当月10日',
        'day20'  => '当月20日',
        'day25'  => '当月25日',
        'next10' => '翌月10日',
        'next20' => '翌月20日',
        'next25' => '翌月25日',
    ];

    /** 既定値。未保存のキーはこの値が返る。 */
    private const DEFAULTS = [
        'closing_day' => 'eom',
        'payment_day' => 'next25',
    ];

    /**
     * 全設定値を既定値とマージして返す。
     *
     * @return array{closing_day:string, payment_day:string}
     */
    public static function all(): array
    {
        $saved = get_option(self::OPTION, []);
        if (!is_array($saved)) {
            $saved = [];
        }
        $merged = array_merge(self::DEFAULTS, $saved);

        // 保存値が壊れていても業務ロジックが落ちないよう、ここで正規化する
        $merged['closing_day'] = self::normalize((string) $merged['closing_day'], self::CLOSING_DAYS, self::DEFAULTS['closing_day']);
        $merged['payment_day'] = self::normalize((string) $merged['payment_day'], self::PAYMENT_DAYS, self::DEFAULTS['payment_day']);

        return $merged;
    }

    public static function closing_day(): string
    {
        return self::all()['closing_day'];
    }

    public static function payment_day(): string
    {
        return self::all()['payment_day'];
    }

    /**
     * 指定した年月（'Y-m'。対象期間の終了日が属する月）の対象期間・提出期限を返す。
     * 締め日の種類による対象期間のずれは PayPeriodCalculator 参照。
     *
     * @return array{start:string, end:string, deadline:string} 'Y-m-d'
     */
    public static function period_for_year_month(string $year_month): array
    {
        return PayPeriodCalculator::period_for(self::closing_day(), $year_month);
    }

    /** 指定した勤務日が属する年月（'Y-m'）を、現在の締め日設定に基づいて判定する。 */
    public static function year_month_for_date(string $work_date): string
    {
        return PayPeriodCalculator::year_month_for_date(self::closing_day(), $work_date);
    }

    /**
     * 設定を保存する。渡されたキーのみ更新し、他は既存値を維持する。
     *
     * @param array{closing_day?:string, payment_day?:string} $input
     */
    public static function update(array $input): void
    {
        $current = self::all();

        if (array_key_exists('closing_day', $input)) {
            $current['closing_day'] = self::normalize((string) $input['closing_day'], self::CLOSING_DAYS, $current['closing_day']);
        }
        if (array_key_exists('payment_day', $input)) {
            $current['payment_day'] = self::normalize((string) $input['payment_day'], self::PAYMENT_DAYS, $current['payment_day']);
        }

        update_option(self::OPTION, $current, false);
    }

    /**
     * 締め日変更の制限（§2.1「締め日変更の制限」）：
     * confirmed ステータスの月度が1件でも存在する場合は変更操作をブロックする。
     *
     * 判定対象の wp_monthly_summary は 3f/3g で作成されるため、テーブル不在の間は
     * 常に false（制限なし）とする。CLAUDE.mdの方針どおり「テーブル不在時は無効」として
     * このメソッドに閉じ込め、3f/3g実装時にここへ実データ参照を接続する。
     */
    public static function has_confirmed_months(): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'monthly_summary';

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return false;
        }

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = %s",
            'confirmed'
        ));
        return $count > 0;
    }

    /** 選択肢に無い値が保存されていた場合に既定値へフォールバックする（DB/WPに触れない純粋関数）。 */
    public static function normalize(string $value, array $choices, string $default): string
    {
        return isset($choices[$value]) ? $value : $default;
    }
}
