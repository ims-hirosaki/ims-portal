<?php

declare(strict_types=1);

namespace IMS\Module\User\Csv;

use IMS\Module\User\EmployeeRepository;
use IMS\Module\User\MasterRepository;
use IMS\Support\UserRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * アカウント一括ダウンロード（01_user_management.md §3.1「ダウンロード仕様」）。
 *
 * ・退職者を含む全ユーザーを対象とする。
 * ・出力列は取り込み列と同一（CsvSchema）。→ ラウンドトリップ可能。
 * ・文字コード：UTF-8（BOM 付き。Excel での文字化け防止）。
 * ・給与機微列（基本給）は、操作者が ims_view_salary を持たない場合は空欄で出力する。
 */
final class CsvExporter
{
    /**
     * CSV 本文（BOM 付き UTF-8）を生成して返す。
     */
    public static function build(): string
    {
        $can_view_salary = current_user_can('ims_view_salary');

        // マスタの ID → コード / 名称の逆引き表を先に構築（N+1回避）
        $lookup = self::build_master_lookups();

        // 社員番号の逆引き（承認者 user_id → employee_code）用に全ユーザーを取得
        $users = EmployeeRepository::list_employees(true); // 退職者も含む

        $rows = [];
        $rows[] = CsvSchema::header_labels();

        foreach ($users as $u) {
            $uid = $u->ID;

            $affiliation_id     = (int) get_user_meta($uid, 'affiliation_id', true);
            $department_id      = (int) get_user_meta($uid, 'department_id', true);
            $position_id        = (int) get_user_meta($uid, 'position_id', true);
            $job_type_id        = (int) get_user_meta($uid, 'job_type_id', true);
            $employment_type_id = (int) get_user_meta($uid, 'employment_type_id', true);

            $first_approver_id = UserRepository::get_first_approver_id($uid);
            $final_approver_id = UserRepository::get_final_approver_id($uid);

            $row = [
                'employee_code'        => UserRepository::get_employee_code($uid),
                'last_name'            => (string) get_user_meta($uid, 'last_name', true),
                'first_name'           => (string) get_user_meta($uid, 'first_name', true),
                'email'                => $u->user_email,
                'auth_type'            => UserRepository::get_auth_type($uid),
                'affiliation_code'     => $lookup['affiliation_code'][$affiliation_id] ?? '',
                'department_code'      => $lookup['department_code'][$department_id] ?? '',
                'position_name'        => $lookup['position_name'][$position_id] ?? '',
                'job_type_name'        => $lookup['job_type_name'][$job_type_id] ?? '',
                'employment_type_name' => $lookup['employment_type_name'][$employment_type_id] ?? '',
                'employment_status'    => UserRepository::get_employment_status($uid),
                'scheduled_work_hours' => self::num(UserRepository::get_scheduled_work_hours($uid)),
                'base_salary'          => $can_view_salary ? (string) UserRepository::get_base_salary($uid) : '',
                'travel_unit_price'    => self::num(UserRepository::get_travel_unit_price($uid)),
                'commute_one_way_km'   => self::num(UserRepository::get_commute_one_way_km($uid)),
                'first_approver_code'  => self::approver_code($first_approver_id),
                'final_approver_code'  => self::approver_code($final_approver_id),
            ];

            // CsvSchema の列順に並べる
            $ordered = [];
            foreach (CsvSchema::keys() as $key) {
                $ordered[] = (string) ($row[$key] ?? '');
            }
            $rows[] = $ordered;
        }

        return self::to_csv_string($rows);
    }

    /**
     * ダウンロード用ファイル名。
     */
    public static function filename(): string
    {
        return 'ims_users_' . current_time('Ymd') . '.csv';
    }

    // ── 内部ヘルパー ───────────────────────────────────────

    /**
     * マスタ ID → コード / 名称 の逆引き表を構築する。
     *
     * @return array<string, array<int, string>>
     */
    private static function build_master_lookups(): array
    {
        $lookup = [
            'affiliation_code'     => [],
            'department_code'      => [],
            'position_name'        => [],
            'job_type_name'        => [],
            'employment_type_name' => [],
        ];

        foreach (MasterRepository::all('affiliation') as $r) {
            $lookup['affiliation_code'][(int) $r['id']] = (string) ($r['affiliation_code'] ?? '');
        }
        foreach (MasterRepository::all('department') as $r) {
            $lookup['department_code'][(int) $r['id']] = (string) ($r['department_code'] ?? '');
        }
        foreach (MasterRepository::all('position') as $r) {
            $lookup['position_name'][(int) $r['id']] = (string) ($r['name'] ?? '');
        }
        foreach (MasterRepository::all('job_type') as $r) {
            $lookup['job_type_name'][(int) $r['id']] = (string) ($r['name'] ?? '');
        }
        foreach (MasterRepository::all('employment_type') as $r) {
            $lookup['employment_type_name'][(int) $r['id']] = (string) ($r['name'] ?? '');
        }

        return $lookup;
    }

    private static function approver_code(?int $user_id): string
    {
        if (!$user_id) {
            return '';
        }
        return (string) get_user_meta($user_id, 'employee_code', true);
    }

    /**
     * 数値の表示整形。小数は末尾ゼロを落とし、整数は整数のまま。
     */
    private static function num(float $v): string
    {
        if ($v == (int) $v) {
            return (string) (int) $v;
        }
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    /**
     * 二次元配列を CSV 文字列（BOM 付き UTF-8, CRLF）に変換する。
     *
     * @param array<int, array<int, string>> $rows
     */
    private static function to_csv_string(array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return '';
        }
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        rewind($fh);
        $body = stream_get_contents($fh) ?: '';
        fclose($fh);

        // fputcsv は LF 区切り。Excel 互換のため CRLF に統一し、先頭に BOM を付与。
        $body = preg_replace('/(?<!\r)\n/', "\r\n", $body);
        return "\xEF\xBB\xBF" . $body;
    }
}
