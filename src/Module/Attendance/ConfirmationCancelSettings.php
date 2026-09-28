<?php

declare(strict_types=1);

namespace IMS\Module\Attendance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 「確定の取り消しを許可する」設定の読み書き（03 §3.5「確定の取り消し」。3n-2。ユーザー確認済み）。
 *
 * テスト運用中は確定を誤ることが多いため、既定はオン（許可）。本番運用に入ったらオフにして、
 * 要件定義書の「確定後は不変」（§3.5・§7.3）に戻す。wp_options に単一の配列で保存する
 * （TimeRoundingSettings と同じ方針）。
 */
final class ConfirmationCancelSettings
{
    public const OPTION = 'ims_confirmation_cancel_settings';

    private const DEFAULT_ALLOWED = true;

    public static function is_allowed(): bool
    {
        $saved = get_option(self::OPTION, []);
        if (!is_array($saved) || !array_key_exists('allowed', $saved)) {
            return self::DEFAULT_ALLOWED;
        }
        return (bool) $saved['allowed'];
    }

    public static function update(bool $allowed): void
    {
        update_option(self::OPTION, ['allowed' => $allowed], false);
    }
}
