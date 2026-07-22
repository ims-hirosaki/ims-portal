<?php

declare(strict_types=1);

namespace IMS\Module\User;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 外部ツールランチャーのデータ操作（00_portal.md §3.2.1 / §3.2.5、01 §2.2）。
 *
 * テーブル：{prefix}ims_launcher_apps
 *   id / app_name / url / icon_url / protocol_scheme / sort_order / is_active / created_by / timestamps
 *
 * ・並べ替えは sort_order の一括更新で行う。
 * ・削除は物理削除（要件どおり）。無効化は is_active=0 で「非表示」にするだけ。
 */
final class LauncherRepository
{
    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ims_launcher_apps';
    }

    /**
     * 全ツール（管理画面用。無効も含む）。表示順→ID順。
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        global $wpdb;
        $t = self::table();
        return $wpdb->get_results("SELECT * FROM {$t} ORDER BY sort_order ASC, id ASC", ARRAY_A) ?: [];
    }

    /**
     * 有効なツールのみ（ポータル表示用）。表示順→ID順。
     * @return array<int, array<string, mixed>>
     */
    public static function active(): array
    {
        global $wpdb;
        $t = self::table();
        return $wpdb->get_results("SELECT * FROM {$t} WHERE is_active = 1 ORDER BY sort_order ASC, id ASC", ARRAY_A) ?: [];
    }

    public static function find(int $id): ?array
    {
        global $wpdb;
        $t = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $id), ARRAY_A);
        return $row ?: null;
    }

    /**
     * 追加。
     * @return int|\WP_Error 新規ID
     */
    public static function create(array $input, int $operator_id)
    {
        $data = self::validate($input);
        if (is_wp_error($data)) {
            return $data;
        }
        global $wpdb;
        $data['sort_order'] = self::resolve_sort_order($input);
        $data['created_by'] = $operator_id;
        $ok = $wpdb->insert(self::table(), $data);
        if ($ok === false) {
            return new \WP_Error('db', 'ツールの登録に失敗しました。');
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * 更新。
     * @return true|\WP_Error
     */
    public static function update(int $id, array $input)
    {
        if (self::find($id) === null) {
            return new \WP_Error('not_found', '対象のツールが見つかりません。');
        }
        $data = self::validate($input);
        if (is_wp_error($data)) {
            return $data;
        }
        $data['sort_order'] = self::resolve_sort_order($input);
        global $wpdb;
        $ok = $wpdb->update(self::table(), $data, ['id' => $id]);
        if ($ok === false) {
            return new \WP_Error('db', 'ツールの更新に失敗しました。');
        }
        return true;
    }

    public static function delete(int $id): bool
    {
        global $wpdb;
        return (bool) $wpdb->delete(self::table(), ['id' => $id], ['%d']);
    }

    /**
     * 表示/非表示の切り替え（削除しない）。
     */
    public static function set_active(int $id, bool $active): bool
    {
        global $wpdb;
        return $wpdb->update(self::table(), ['is_active' => $active ? 1 : 0], ['id' => $id]) !== false;
    }

    /**
     * 並べ替え。渡された ID 順に sort_order を 1 から振り直す。
     *
     * @param array<int, int> $ordered_ids
     */
    public static function reorder(array $ordered_ids): void
    {
        global $wpdb;
        $t = self::table();
        $order = 1;
        foreach ($ordered_ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $wpdb->update($t, ['sort_order' => $order], ['id' => $id]);
            $order++;
        }
    }

    // ── バリデーション ─────────────────────────────────────

    /**
     * 入力を検証し、DB カラムに対応する連想配列を返す。
     * @return array<string,mixed>|\WP_Error
     */
    public static function validate(array $input)
    {
        $name = trim((string) ($input['app_name'] ?? ''));
        $url  = trim((string) ($input['url'] ?? ''));
        $icon = trim((string) ($input['icon_url'] ?? ''));
        $method = ($input['launch_method'] ?? 'tab') === 'app' ? 'app' : 'tab';
        $scheme = trim((string) ($input['protocol_scheme'] ?? ''));
        $active = !empty($input['is_active']) ? 1 : 0;

        if ($name === '') {
            return new \WP_Error('validation', 'ツール名は必須です。');
        }
        if (mb_strlen($name) > 50) {
            return new \WP_Error('validation', 'ツール名は50文字以内で入力してください。');
        }
        if (!preg_match('#^https?://#', $url)) {
            return new \WP_Error('validation', 'リンク先URLは http:// または https:// で始めてください。');
        }
        if ($icon === '') {
            return new \WP_Error('validation', 'アイコン画像を指定してください。');
        }

        // 起動方法が「デスクトップアプリ」のときのみ protocol_scheme を保持
        if ($method === 'app') {
            if ($scheme === '' || !preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $scheme)) {
                return new \WP_Error('validation', 'デスクトップアプリ起動URL（例：msteams://）を正しく入力してください。');
            }
        } else {
            $scheme = '';
        }

        return [
            'app_name'        => $name,
            'url'             => esc_url_raw($url),
            'icon_url'        => esc_url_raw($icon),
            'protocol_scheme' => $scheme !== '' ? $scheme : null,
            'is_active'       => $active,
        ];
    }

    private static function resolve_sort_order(array $input): int
    {
        if (isset($input['sort_order']) && $input['sort_order'] !== '') {
            return max(0, (int) $input['sort_order']);
        }
        // 未指定なら末尾に付ける
        global $wpdb;
        $max = (int) $wpdb->get_var('SELECT MAX(sort_order) FROM ' . self::table());
        return $max + 1;
    }
}
