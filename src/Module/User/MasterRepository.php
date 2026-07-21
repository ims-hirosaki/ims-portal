<?php

declare(strict_types=1);

namespace IMS\Module\User;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 各種マスタ（所属・部署・役職・職種・雇用形態・手当）の共通CRUD。
 *
 * マスタは構造がほぼ共通のため、種別ごとの差分（テーブル名・名称カラム・
 * コードカラム・追加カラム・参照元 usermeta キー）を設定で吸収し、
 * 6種を1つのリポジトリで扱う（01_user_management.md §2.1・§4 準拠）。
 *
 * 運用ルール（§2.1）：
 * ・物理削除は原則禁止。is_active = 0 の論理削除（停止）を基本とする。
 * ・在籍社員が参照している項目の停止時は対象社員数を警告する（呼び出し側で表示）。
 * ・在籍社員が0名の項目のみ完全削除を許可する。
 */
final class MasterRepository
{
    /**
     * 種別ごとの設定。
     * name_col: 名称カラム / code_col: コードカラム(なければ null) /
     * extra: 追加カラム / meta_key: 在籍社員数カウント用の usermeta キー
     *
     * @var array<string, array<string, mixed>>
     */
    private const CONFIG = [
        'affiliation' => [
            'table_suffix' => 'ims_affiliations',
            'label'        => '所属',
            'name_col'     => 'name',
            'code_col'     => 'affiliation_code',
            'extra'        => [],
            'meta_key'     => 'affiliation_id',
        ],
        'department' => [
            'table_suffix' => 'ims_departments',
            'label'        => '部署',
            'name_col'     => 'name',
            'code_col'     => 'department_code',
            'extra'        => ['affiliation_id'],
            'meta_key'     => 'department_id',
        ],
        'position' => [
            'table_suffix' => 'ims_positions',
            'label'        => '役職',
            'name_col'     => 'name',
            'code_col'     => null,
            'extra'        => [],
            'meta_key'     => 'position_id',
        ],
        'job_type' => [
            'table_suffix' => 'ims_job_types',
            'label'        => '職種',
            'name_col'     => 'name',
            'code_col'     => null,
            'extra'        => [],
            'meta_key'     => 'job_type_id',
        ],
        'employment_type' => [
            'table_suffix' => 'ims_employment_types',
            'label'        => '雇用形態',
            'name_col'     => 'name',
            'code_col'     => null,
            'extra'        => [],
            'meta_key'     => 'employment_type_id',
        ],
        'allowance' => [
            'table_suffix' => 'allowance_masters',
            'label'        => '手当',
            'name_col'     => 'allowance_name',
            'code_col'     => 'allowance_code',
            'extra'        => [],
            'meta_key'     => null, // 手当は user_allowances テーブルで別集計
        ],
    ];

    public static function types(): array
    {
        return array_keys(self::CONFIG);
    }

    public static function is_valid_type(string $type): bool
    {
        return isset(self::CONFIG[$type]);
    }

    public static function config(string $type): array
    {
        return self::CONFIG[$type];
    }

    public static function label(string $type): string
    {
        return self::CONFIG[$type]['label'] ?? $type;
    }

    private static function table(string $type): string
    {
        global $wpdb;
        return $wpdb->prefix . self::CONFIG[$type]['table_suffix'];
    }

    /**
     * 一覧取得。$include_inactive = false のときは有効項目のみ（社員登録の選択肢用）。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(string $type, bool $include_inactive = true): array
    {
        global $wpdb;
        $table = self::table($type);
        $where = $include_inactive ? '' : 'WHERE is_active = 1';
        // テーブル名は内部定義（ユーザー入力ではない）なので prepare 不要
        $sql = "SELECT * FROM {$table} {$where} ORDER BY sort_order ASC, id ASC";
        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public static function find(string $type, int $id): ?array
    {
        global $wpdb;
        $table = self::table($type);
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * コード重複チェック（affiliation / department / allowance）。
     */
    public static function code_exists(string $type, string $code, int $exclude_id = 0): bool
    {
        $code_col = self::CONFIG[$type]['code_col'];
        if ($code_col === null) {
            return false;
        }
        global $wpdb;
        $table = self::table($type);
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE {$code_col} = %s AND id <> %d",
            $code,
            $exclude_id
        ));
        return $count > 0;
    }

    /**
     * 新規登録。$data のキーは name / code / sort_order / affiliation_id 等（種別に応じて）。
     * @return int|\WP_Error 挿入されたID、または検証エラー
     */
    public static function insert(string $type, array $data)
    {
        $prepared = self::prepare_data($type, $data);
        if (is_wp_error($prepared)) {
            return $prepared;
        }

        global $wpdb;
        $prepared['is_active'] = 1;
        $result = $wpdb->insert(self::table($type), $prepared);
        if ($result === false) {
            return new \WP_Error('db_insert_error', 'データの登録に失敗しました。');
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * 更新。
     * @return true|\WP_Error
     */
    public static function update(string $type, int $id, array $data)
    {
        $prepared = self::prepare_data($type, $data, $id);
        if (is_wp_error($prepared)) {
            return $prepared;
        }

        global $wpdb;
        $result = $wpdb->update(self::table($type), $prepared, ['id' => $id]);
        if ($result === false) {
            return new \WP_Error('db_update_error', 'データの更新に失敗しました。');
        }
        return true;
    }

    /**
     * 停止 / 再開（論理削除の切り替え）。
     */
    public static function set_active(string $type, int $id, bool $active): bool
    {
        global $wpdb;
        return $wpdb->update(
            self::table($type),
            ['is_active' => $active ? 1 : 0],
            ['id' => $id]
        ) !== false;
    }

    /**
     * 完全削除（在籍社員0名のときのみ呼ぶこと。呼び出し側で count_members を確認する）。
     */
    public static function delete(string $type, int $id): bool
    {
        global $wpdb;
        return $wpdb->delete(self::table($type), ['id' => $id]) !== false;
    }

    /**
     * この項目を参照している在籍社員数。停止・削除の可否判定に使う。
     * 手当（meta_key = null）は user_allowances テーブルから集計する。
     */
    public static function count_members(string $type, int $id): int
    {
        global $wpdb;
        $meta_key = self::CONFIG[$type]['meta_key'];

        if ($meta_key !== null) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %d",
                $meta_key,
                $id
            ));
        }

        // 手当：user_allowances に金額設定が存在する社員数
        $ua = $wpdb->prefix . 'user_allowances';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM {$ua} WHERE allowance_master_id = %d",
            $id
        ));
    }

    /**
     * 入力データを検証・整形して、DBカラム名にマッピングする。
     * @return array<string, mixed>|\WP_Error
     */
    private static function prepare_data(string $type, array $data, int $exclude_id = 0)
    {
        $config   = self::CONFIG[$type];
        $name_col = $config['name_col'];
        $code_col = $config['code_col'];

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return new \WP_Error('validation', '名称は必須です。');
        }

        $out = [
            $name_col    => $name,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];

        if ($code_col !== null) {
            $code = trim((string) ($data['code'] ?? ''));
            if ($code === '') {
                return new \WP_Error('validation', 'コードは必須です。');
            }
            if (self::code_exists($type, $code, $exclude_id)) {
                return new \WP_Error('validation', 'そのコードは既に使用されています。');
            }
            $out[$code_col] = $code;
        }

        if (in_array('affiliation_id', $config['extra'], true)) {
            $aff = (int) ($data['affiliation_id'] ?? 0);
            $out['affiliation_id'] = $aff > 0 ? $aff : null;
        }

        return $out;
    }
}
