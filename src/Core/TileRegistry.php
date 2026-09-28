<?php

declare(strict_types=1);

namespace IMS\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ダッシュボードのタイルを、各モジュールからの寄与で組み立てるレジストリ。
 *
 * 設計方針（08 §6.2 準拠・新規定義）：
 * ・タイルを 00 側に直書きせず、各モジュールが `ims_portal_register_tile` アクションで
 *   自分のタイルを登録する。これにより新モジュール追加時も 00 のコードを一切改修しない。
 * ・表示順は `priority` で固定する（00_portal.md §3.2.4「表示順は固定・パーソナライズ不可」）。
 * ・badge はコールバックとして遅延評価し、一覧描画をブロックしない。
 *
 * モジュール側の登録例：
 *
 *   add_action('ims_portal_register_tile', function (\IMS\Core\TileRegistry $registry) {
 *       $registry->add([
 *           'id'       => 'timecard',
 *           'label'    => '打刻',
 *           'icon'     => 'clock',
 *           'url'      => '/portal/timecard/',
 *           'priority' => 10,
 *           'caps'     => ['ims_use_portal'],
 *           'badge'    => static fn (int $user_id): ?string =>
 *               ims_timecard_is_missing($user_id) ? '!' : null,
 *       ]);
 *   });
 *
 * badge の戻り値は文字列（従来どおり朱色のバッジ）か、色を指定する配列
 * ['text' => '未提出', 'tone' => 'neutral'|'warning'|'danger'] のどちらか（3m で追加。ユーザー確認済み）。
 * tone は 07_design_system.md の色の意味に対応する（neutral＝グレー、warning＝金、danger＝朱）。
 */
final class TileRegistry
{
    /** badge で指定できる色（07_design_system.md の色の意味に対応）。 */
    public const BADGE_TONES = ['neutral', 'warning', 'danger'];

    /** @var array<int, array<string, mixed>> */
    private static array $tiles = [];

    private static bool $collected = false;

    public static function init(): void
    {
        // 実際の収集はダッシュボード描画時（get_tiles_for_user 呼び出し時）に遅延させる。
        // これにより、モジュールのロード順序に依存せず、全モジュール登録済みの状態で集められる。
    }

    /**
     * モジュール側から呼ぶ登録メソッド。
     *
     * 期待するキー：
     *   id (string), label (string), icon (string), url (string),
     *   priority (int), caps (string[]), badge (?callable(int): ?string)
     */
    public static function add(array $tile): void
    {
        $tile += [
            'priority' => 100,
            'caps'     => ['ims_use_portal'],
            'badge'    => null,
        ];
        self::$tiles[] = $tile;
    }

    /**
     * 現在のユーザーが閲覧可能なタイルを、priority 昇順で返す。
     * バッジは呼び出し時点で評価する（describe: 遅延だが同期的に解決する軽量版。
     * 将来的に非同期化する場合はここを REST 経由に差し替える）。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_tiles_for_user(int $user_id): array
    {
        self::collect_once();

        $user = get_userdata($user_id);
        if (!$user) {
            return [];
        }

        $visible = array_values(array_filter(
            self::$tiles,
            static function (array $tile) use ($user): bool {
                foreach ($tile['caps'] as $cap) {
                    if (!$user->has_cap($cap)) {
                        return false;
                    }
                }
                return true;
            }
        ));

        usort($visible, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);

        foreach ($visible as &$tile) {
            $badge = $tile['badge'];
            [$tile['resolved_badge'], $tile['resolved_badge_tone']] = self::normalize_badge(
                is_callable($badge) ? $badge($user_id) : null
            );
            unset($tile['badge']);
        }
        unset($tile);

        return $visible;
    }

    /**
     * badge コールバックの戻り値を [表示文字列, 色] にそろえる。
     * 文字列はそのまま（色は null＝従来の朱色）。配列は text と tone を取り出し、
     * 知らない tone は null（従来の朱色）にする。
     *
     * @param mixed $badge
     * @return array{0:?string, 1:?string}
     */
    public static function normalize_badge($badge): array
    {
        if (is_array($badge)) {
            $text = isset($badge['text']) ? (string) $badge['text'] : '';
            $tone = isset($badge['tone']) && in_array($badge['tone'], self::BADGE_TONES, true) ? (string) $badge['tone'] : null;
            return $text === '' ? [null, null] : [$text, $tone];
        }
        if ($badge === null || $badge === '') {
            return [null, null];
        }
        return [(string) $badge, null];
    }

    private static function collect_once(): void
    {
        if (self::$collected) {
            return;
        }
        do_action('ims_portal_register_tile', self::class);
        self::$collected = true;
    }
}
