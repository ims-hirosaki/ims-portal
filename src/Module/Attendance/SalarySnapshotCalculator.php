<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 月次データスナップショット（給与条件の固定）の値を決める純粋ロジック
 * （03_attendance_management.md §3.5）。DB/WordPressに触れない。
 *
 * 取得ルール：
 * ・基本給：wp_salary_history のうち effective_date <= 対象月末日 の最新値
 * ・各手当：wp_user_allowances のうち effective_date <= 対象月末日 の手当ごとの最新値
 * 「最新」は effective_date の降順、同日なら id の降順（後から登録した行を優先）で決める
 * （01モジュールの EmployeeRepository::salary_history() / current_allowance_amount() と同じ並び順）。
 *
 * 手当マスタの有効・無効（is_active）は見ない。無効化された手当も既存の金額レコードは
 * スナップショット時に参照する（§2.2）。
 */
final class SalarySnapshotCalculator
{
    /**
     * 対象月末日（'Y-m-d'）。「対象月」は年月ラベル（'Y-m'）のカレンダー月として扱う。
     */
    public static function month_end_date(string $year_month): string
    {
        [$year, $month] = array_map('intval', explode('-', $year_month));
        return sprintf('%04d-%02d-%02d', $year, $month, self::days_in_month($year, $month));
    }

    /**
     * 基本給のスナップショット値。該当する履歴が無ければ null。
     *
     * @param array<int, array{id:int|string, base_salary:int|string, effective_date:string}> $rows
     */
    public static function base_salary(array $rows, string $as_of_date): ?int
    {
        $latest = null;
        foreach ($rows as $row) {
            if ((string) $row['effective_date'] > $as_of_date) {
                continue;
            }
            if ($latest === null || self::is_newer($row, $latest)) {
                $latest = $row;
            }
        }
        return $latest === null ? null : (int) $latest['base_salary'];
    }

    /**
     * 手当のスナップショット値（手当マスタID => 金額）。手当マスタIDの昇順で返す。
     *
     * @param array<int, array{id:int|string, allowance_master_id:int|string, amount:int|string, effective_date:string}> $rows
     * @return array<int, int>
     */
    public static function allowances(array $rows, string $as_of_date): array
    {
        $latest = [];
        foreach ($rows as $row) {
            if ((string) $row['effective_date'] > $as_of_date) {
                continue;
            }
            $master_id = (int) $row['allowance_master_id'];
            if (!isset($latest[$master_id]) || self::is_newer($row, $latest[$master_id])) {
                $latest[$master_id] = $row;
            }
        }
        ksort($latest);

        $result = [];
        foreach ($latest as $master_id => $row) {
            $result[$master_id] = (int) $row['amount'];
        }
        return $result;
    }

    /**
     * wp_monthly_summary.snapshot_allowances に保存するJSON（{"手当マスタID": 金額, ...}。§5.5）。
     * 手当が1件も無い場合も空オブジェクト "{}" を保存し、「未取得（NULL）」と区別する。
     *
     * @param array<int, int> $allowances
     */
    public static function allowances_json(array $allowances): string
    {
        $obj = [];
        foreach ($allowances as $master_id => $amount) {
            $obj[(string) $master_id] = $amount;
        }
        return (string) json_encode((object) $obj);
    }

    /** $a が $b より新しいか（effective_date 降順 → id 降順）。 */
    private static function is_newer(array $a, array $b): bool
    {
        $da = (string) $a['effective_date'];
        $db = (string) $b['effective_date'];
        if ($da !== $db) {
            return $da > $db;
        }
        return (int) $a['id'] > (int) $b['id'];
    }

    /** 指定年月の日数。calendar拡張に依存しないよう date() で求める。 */
    private static function days_in_month(int $year, int $month): int
    {
        return (int) date('t', mktime(0, 0, 0, $month, 1, $year));
    }
}

