<?php

declare(strict_types=1);

namespace IMS\Module\Timecard;

use IMS\Support\Crypto;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 打刻システム設定の読み書き（02_time_tracking.md §2.1）。
 *
 * wp_options に単一の配列オプションとして保存する。
 * 画面（AdminSettingsPage）と業務ロジック（2c以降）の双方が、必ずこのクラス経由で
 * 設定値を読む。既定値の定義もここ1か所に集約する。
 *
 * 保存キー：
 *   date_boundary_hour     … 深夜勤務の日付境界（0〜23・既定 0）           → §2.1①
 *   staff_correction_level … スタッフ自身の打刻修正レベル（既定 pre_closing）→ §2.1②
 *   chat_webhook_enc       … Google Chat Webhook URL（暗号化して保存）      → §2.1③
 *   alert_threshold_min    … 残業乖離アラートの閾値（分・既定 30）          → §2.1③
 *
 * Webhook URL は「知っていれば誰でもそのルームに投稿できる」実質的な認証情報のため、
 * OAuth クライアントシークレットと同様に Crypto で暗号化して保存する（08 §11）。
 */
final class Settings
{
    public const OPTION = 'ims_timecard_settings';

    /** 修正許可レベルの許可値（§2.1②） */
    public const CORRECTION_LEVELS = ['disabled', 'today_only', 'pre_closing'];

    /** 既定値。未保存のキーはこの値が返る。 */
    private const DEFAULTS = [
        'date_boundary_hour'     => 0,
        'staff_correction_level' => 'pre_closing',
        'chat_webhook_enc'       => '',
        'alert_threshold_min'    => 30,
    ];

    /**
     * 全設定値を既定値とマージして返す。
     *
     * @return array{
     *     date_boundary_hour:int,
     *     staff_correction_level:string,
     *     chat_webhook_enc:string,
     *     alert_threshold_min:int
     * }
     */
    public static function all(): array
    {
        $saved = get_option(self::OPTION, []);
        if (!is_array($saved)) {
            $saved = [];
        }
        $merged = array_merge(self::DEFAULTS, $saved);

        // 保存値が壊れていても業務ロジックが落ちないよう、ここで正規化する
        $merged['date_boundary_hour']     = self::clamp_hour($merged['date_boundary_hour']);
        $merged['staff_correction_level'] = self::normalize_level((string) $merged['staff_correction_level']);
        $merged['chat_webhook_enc']       = (string) $merged['chat_webhook_enc'];
        $merged['alert_threshold_min']    = self::clamp_threshold($merged['alert_threshold_min']);

        return $merged;
    }

    /** 単一キーの取得。 */
    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    // ── 業務ロジック向けアクセサ ─────────────────────────────

    /**
     * 深夜勤務の日付境界（時）。0 のときは日またぎ考慮なし。
     * 退勤打刻の work_date 判定にのみ使用する（§2.1①「適用範囲」）。
     */
    public static function date_boundary_hour(): int
    {
        return (int) self::get('date_boundary_hour');
    }

    /** スタッフ自身の打刻修正レベル（disabled / today_only / pre_closing）。 */
    public static function staff_correction_level(): string
    {
        return (string) self::get('staff_correction_level');
    }

    /** 乖離検知の閾値（分）。 */
    public static function alert_threshold_min(): int
    {
        return (int) self::get('alert_threshold_min');
    }

    /** Google Chat Webhook URL（復号済み平文）。未設定なら空文字。 */
    public static function chat_webhook_url(): string
    {
        $enc = (string) self::get('chat_webhook_enc');
        if ($enc === '') {
            return '';
        }
        return Crypto::decrypt($enc);
    }

    /** Webhook URL が設定済みか（復号せずに判定できる軽量チェック）。 */
    public static function has_chat_webhook(): bool
    {
        return (string) self::get('chat_webhook_enc') !== '';
    }

    // ── 保存 ───────────────────────────────────────────────

    /**
     * 設定を保存する。渡されたキーのみ更新し、他は既存値を維持する。
     *
     * `chat_webhook_url` を平文で渡すと暗号化して格納する。
     * 空文字を渡した場合は「変更しない」扱いとし、既存の Webhook を維持する
     * （画面ではマスク表示するため、空欄＝消したい ではない）。
     * 明示的に削除する場合は `chat_webhook_clear => true` を渡す。
     *
     * @param array<string, mixed> $input
     */
    public static function update(array $input): void
    {
        $current = self::all();

        if (array_key_exists('date_boundary_hour', $input)) {
            $current['date_boundary_hour'] = self::clamp_hour($input['date_boundary_hour']);
        }
        if (array_key_exists('staff_correction_level', $input)) {
            $current['staff_correction_level'] = self::normalize_level((string) $input['staff_correction_level']);
        }
        if (array_key_exists('alert_threshold_min', $input)) {
            $current['alert_threshold_min'] = self::clamp_threshold($input['alert_threshold_min']);
        }

        if (!empty($input['chat_webhook_clear'])) {
            $current['chat_webhook_enc'] = '';
        } elseif (array_key_exists('chat_webhook_url', $input)) {
            $plain = trim((string) $input['chat_webhook_url']);
            if ($plain !== '') {
                $current['chat_webhook_enc'] = Crypto::encrypt($plain);
            }
            // 空文字なら既存を維持（＝何もしない）
        }

        update_option(self::OPTION, $current, false);
    }

    // ── 正規化ヘルパー ─────────────────────────────────────

    private static function clamp_hour(mixed $value): int
    {
        $hour = (int) $value;
        if ($hour < 0) {
            return 0;
        }
        if ($hour > 23) {
            return 23;
        }
        return $hour;
    }

    private static function clamp_threshold(mixed $value): int
    {
        $min = (int) $value;
        if ($min < 1) {
            return 1;
        }
        // 現実的な上限（1日分）を超える値は誤入力とみなす
        if ($min > 1440) {
            return 1440;
        }
        return $min;
    }

    private static function normalize_level(string $level): string
    {
        return in_array($level, self::CORRECTION_LEVELS, true) ? $level : 'pre_closing';
    }

    /**
     * Google Chat の Webhook URL として妥当かを検証する。
     * ホストを固定することで、設定ミスや取り違えで社内情報が外部へ飛ぶのを防ぐ。
     */
    public static function is_valid_webhook_url(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));

        return $scheme === 'https' && $host === 'chat.googleapis.com';
    }
}
