<?php

declare(strict_types=1);

namespace IMS\Module\User\Csv;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * アカウント一括管理 CSV の列定義（01_user_management.md §3.1「取込列定義」）。
 *
 * エクスポート（一覧ダウンロード）と取り込みの「唯一の正本」。
 * 列順は固定（A〜Q の17列）。ここを変えれば両方が追従する。
 *
 * 各列：
 *   key      … 内部フィールドキー
 *   label    … CSV ヘッダ行に出す日本語見出し
 *   required … 取り込み時の必須列か
 *   sensitive… 給与機微（ims_view_salary が無い操作者にはエクスポートで空欄化）
 */
final class CsvSchema
{
    /** ドメイン制限（auth_type = google_sso のとき必須） */
    public const GOOGLE_DOMAIN = '@ims-hirosaki.com';

    /**
     * @return array<int, array{key:string,label:string,required:bool,sensitive:bool}>
     */
    public static function columns(): array
    {
        return [
            ['key' => 'employee_code',         'label' => '社員番号',                 'required' => true,  'sensitive' => false], // A
            ['key' => 'last_name',             'label' => '姓',                       'required' => true,  'sensitive' => false], // B
            ['key' => 'first_name',            'label' => '名',                       'required' => true,  'sensitive' => false], // C
            ['key' => 'email',                 'label' => 'メールアドレス',           'required' => true,  'sensitive' => false], // D
            ['key' => 'auth_type',             'label' => '認証方式',                 'required' => true,  'sensitive' => false], // E
            ['key' => 'affiliation_code',      'label' => '所属コード',               'required' => false, 'sensitive' => false], // F
            ['key' => 'department_code',       'label' => '部署コード',               'required' => false, 'sensitive' => false], // G
            ['key' => 'position_name',         'label' => '役職名',                   'required' => false, 'sensitive' => false], // H
            ['key' => 'job_type_name',         'label' => '職種名',                   'required' => false, 'sensitive' => false], // I
            ['key' => 'employment_type_name',  'label' => '雇用形態名',               'required' => false, 'sensitive' => false], // J
            ['key' => 'employment_status',     'label' => '在籍状況',                 'required' => false, 'sensitive' => false], // K
            ['key' => 'scheduled_work_hours',  'label' => '所定労働時間/日（時間）',  'required' => false, 'sensitive' => false], // L
            ['key' => 'base_salary',           'label' => '基本給',                   'required' => false, 'sensitive' => true],  // M
            ['key' => 'travel_unit_price',     'label' => '交通費単価',               'required' => false, 'sensitive' => false], // N
            ['key' => 'commute_one_way_km',    'label' => '通勤片道距離km',           'required' => false, 'sensitive' => false], // O
            ['key' => 'first_approver_code',   'label' => '確認者社員番号',           'required' => false, 'sensitive' => false], // P
            ['key' => 'final_approver_code',   'label' => '最終承認者社員番号',       'required' => false, 'sensitive' => false], // Q
        ];
    }

    /**
     * ヘッダ行（日本語見出しの配列）。
     * @return array<int, string>
     */
    public static function header_labels(): array
    {
        return array_map(static fn (array $c): string => $c['label'], self::columns());
    }

    /**
     * フィールドキーの配列（列順）。
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_map(static fn (array $c): string => $c['key'], self::columns());
    }

    /** 認証方式の許可値 */
    public static function is_valid_auth_type(string $auth_type): bool
    {
        return in_array($auth_type, ['google_sso', 'password'], true);
    }
}
