<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 事業マスタ（wp_businesses）のCRUD（03_attendance_management.md §2.3 準拠）。
 *
 * ・物理削除は在籍参照が0件のときのみ許可し、通常は is_active による論理削除とする
 *   （Module\User\MasterRepository と同じ運用方針）。
 * ・`is_default_for_paid_leave` は「必ず1件のみ1」という制約があるため、
 *   専用メソッド（set_default_for_paid_leave）以外では切り替えない。
 * ・validate_fields() はDB/WPに触れない純粋関数として切り出す（StatusCalculatorと同方針）。
 *   コード重複チェックのみDBアクセスが要るため insert()/update() 側で別途行う。
 */
final class BusinessRepository
{
    private static function table(): string
    {
        return Schema::businesses_table();
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(bool $include_inactive = true): array
    {
        global $wpdb;
        $table = self::table();
        $where = $include_inactive ? '' : 'WHERE is_active = 1';
        // テーブル名は内部定義（ユーザー入力ではない）なので prepare 不要
        $sql = "SELECT * FROM {$table} {$where} ORDER BY sort_order ASC, business_id ASC";
        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public static function find(int $business_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE business_id = %d', $business_id),
            ARRAY_A
        );
        return $row ?: null;
    }

    /** 有給日のデフォルト割当先（§2.3「必ず1件のみ1」）。未設定なら null。 */
    public static function default_for_paid_leave(): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            'SELECT * FROM ' . self::table() . ' WHERE is_default_for_paid_leave = 1 LIMIT 1',
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function code_exists(string $code, int $exclude_id = 0): bool
    {
        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table() . ' WHERE business_code = %s AND business_id <> %d',
            $code,
            $exclude_id
        ));
        return $count > 0;
    }

    /**
     * 新規登録。$data: business_code / business_name / color_code / client_name / sort_order
     * @param array<string, mixed> $data
     * @return int|\WP_Error 挿入されたID、または検証エラー
     */
    public static function insert(array $data)
    {
        $prepared = self::validate_fields($data);
        if (is_wp_error($prepared)) {
            return $prepared;
        }
        if (self::code_exists($prepared['business_code'])) {
            return new \WP_Error('validation', 'その事業コードは既に使用されています。');
        }

        global $wpdb;
        $prepared['is_active'] = 1;
        $result = $wpdb->insert(self::table(), $prepared);
        if ($result === false) {
            return new \WP_Error('db_insert_error', 'データの登録に失敗しました。');
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * 更新。
     * @param array<string, mixed> $data
     * @return true|\WP_Error
     */
    public static function update(int $business_id, array $data)
    {
        $prepared = self::validate_fields($data);
        if (is_wp_error($prepared)) {
            return $prepared;
        }
        if (self::code_exists($prepared['business_code'], $business_id)) {
            return new \WP_Error('validation', 'その事業コードは既に使用されています。');
        }

        global $wpdb;
        $result = $wpdb->update(self::table(), $prepared, ['business_id' => $business_id]);
        if ($result === false) {
            return new \WP_Error('db_update_error', 'データの更新に失敗しました。');
        }
        return true;
    }

    /** 停止 / 再開（論理削除の切り替え）。 */
    public static function set_active(int $business_id, bool $active): bool
    {
        global $wpdb;
        return $wpdb->update(
            self::table(),
            ['is_active' => $active ? 1 : 0],
            ['business_id' => $business_id]
        ) !== false;
    }

    /** 完全削除（呼び出し側で pending_reference_count と is_default_for_paid_leave を確認すること）。 */
    public static function delete(int $business_id): bool
    {
        global $wpdb;
        return $wpdb->delete(self::table(), ['business_id' => $business_id]) !== false;
    }

    /**
     * 有給日のデフォルト割当先に設定する（他の全行は自動的に解除する）。
     * 「必ず1件のみ1」（§2.3）を保証する唯一の入り口。
     */
    public static function set_default_for_paid_leave(int $business_id): void
    {
        global $wpdb;
        $table = self::table();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET is_default_for_paid_leave = 0 WHERE business_id <> %d",
            $business_id
        ));
        $wpdb->update($table, ['is_default_for_paid_leave' => 1], ['business_id' => $business_id]);
    }

    /**
     * この事業を参照している当月以降の事業別時間実績（wp_project_hours）件数。
     * §2.3「当月以降の wp_project_hours に未確定データが存在する事業の無効化は警告付き」の
     * 判定対象だが、wp_project_hours は 3d で作成されるため、テーブル不在の間は常に 0
     * （警告なし）とする。3d 実装時にここへ実データ参照を接続する。
     */
    public static function pending_reference_count(int $business_id): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'project_hours';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE business_id = %d AND work_date >= %s",
            $business_id,
            gmdate('Y-m-01')
        ));
    }

    /**
     * 入力データを検証・整形する（DB/WPに触れない純粋関数）。
     * コードの重複チェックはDBアクセスが必要なため、呼び出し側（insert/update）で別途行う。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|\WP_Error
     */
    public static function validate_fields(array $data)
    {
        $code   = trim((string) ($data['business_code'] ?? ''));
        $name   = trim((string) ($data['business_name'] ?? ''));
        $color  = trim((string) ($data['color_code'] ?? ''));
        $client = trim((string) ($data['client_name'] ?? ''));

        if ($code === '') {
            return new \WP_Error('validation', '事業コードは必須です。');
        }
        if ($name === '') {
            return new \WP_Error('validation', '事業名は必須です。');
        }
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            return new \WP_Error('validation', '表示カラーは #RRGGBB 形式で指定してください。');
        }

        return [
            'business_code' => $code,
            'business_name' => $name,
            'color_code'    => strtoupper($color),
            'client_name'   => $client !== '' ? $client : null,
            'sort_order'    => (int) ($data['sort_order'] ?? 0),
        ];
    }
}
