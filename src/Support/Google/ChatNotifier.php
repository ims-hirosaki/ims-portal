<?php

declare(strict_types=1);

namespace IMS\Support\Google;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google Chat Webhook 通知（06_google_workspace_integration.md Tier 2 / 02 §7.4）。
 *
 * 設計上の要点：
 * ・**ベストエフォート。** 送信に失敗しても呼び出し元の業務処理（打刻の記録など）を
 *   絶対にブロックしない。戻り値を無視しても安全に書いてある。
 * ・**リトライで sleep() しない。** 打刻APIのレスポンス中に指数バックオフで待つと
 *   ユーザーを待たせてしまうため、失敗時は WP-Cron の単発イベントで再送を予約する
 *   （最大3回 / 30秒・60秒間隔）。
 * ・3回失敗した時点で error_log に記録する（02 §3.3「通知の失敗処理」）。
 *
 * 使い方：
 *   ChatNotifier::notify($webhook_url, $text);        // 業務処理から（非同期リトライ付き）
 *   $result = ChatNotifier::send_now($url, $text);    // 設定画面のテスト送信（同期・結果を見たい）
 */
final class ChatNotifier
{
    /** リトライ予約に使う cron フック名 */
    public const RETRY_HOOK = 'ims_chat_notify_retry';

    /** 最大試行回数（初回 + リトライ2回 = 計3回） */
    private const MAX_ATTEMPTS = 3;

    /** リクエストのタイムアウト（秒）。打刻レスポンスを長く待たせないため短めにする。 */
    private const TIMEOUT = 8;

    public static function init(): void
    {
        add_action(self::RETRY_HOOK, [self::class, 'handle_retry'], 10, 3);
    }

    /**
     * 通知を送る（業務処理からの呼び出し口）。
     * 失敗しても例外を投げず、リトライを予約して false を返すだけ。
     *
     * @param string $webhook_url 復号済みの Webhook URL
     * @param string $text        送信するプレーンテキスト
     * @return bool 初回送信が成功したか（false でもリトライが予約される場合がある）
     */
    public static function notify(string $webhook_url, string $text): bool
    {
        if ($webhook_url === '' || $text === '') {
            return false;
        }

        $result = self::send_now($webhook_url, $text);
        if ($result === true) {
            return true;
        }

        self::schedule_retry($webhook_url, $text, 2, $result instanceof \WP_Error ? $result->get_error_message() : '');
        return false;
    }

    /**
     * 同期送信を1回だけ試みる。設定画面の「テスト送信」から使う。
     *
     * @return true|\WP_Error 成功なら true、失敗なら理由を持つ WP_Error
     */
    public static function send_now(string $webhook_url, string $text): true|\WP_Error
    {
        if ($webhook_url === '') {
            return new \WP_Error('ims_chat_no_url', '通知先のチャットルーム URL が設定されていません。');
        }

        $response = wp_remote_post($webhook_url, [
            'timeout'     => self::TIMEOUT,
            'redirection' => 0,
            'headers'     => ['Content-Type' => 'application/json; charset=UTF-8'],
            'body'        => wp_json_encode(['text' => $text]),
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error(
                'ims_chat_http_error',
                'Google Chat への接続に失敗しました：' . $response->get_error_message()
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            return true;
        }

        // 本文はエラー原因の特定に有用だが、長大な HTML が返ることもあるので切り詰める
        $body = trim((string) wp_remote_retrieve_response_message($response));
        if ($body === '') {
            $body = mb_substr(trim((string) wp_remote_retrieve_body($response)), 0, 200);
        }

        return new \WP_Error(
            'ims_chat_bad_status',
            sprintf('Google Chat がエラーを返しました（HTTP %d）：%s', $code, $body)
        );
    }

    /**
     * WP-Cron から呼ばれる再送処理。
     *
     * @param string $webhook_url
     * @param string $text
     * @param int    $attempt 今回が何回目の試行か（2 以上）
     */
    public static function handle_retry(string $webhook_url, string $text, int $attempt): void
    {
        $result = self::send_now($webhook_url, $text);
        if ($result === true) {
            return;
        }

        $reason = $result instanceof \WP_Error ? $result->get_error_message() : '';

        if ($attempt >= self::MAX_ATTEMPTS) {
            self::log_failure($text, $attempt, $reason);
            return;
        }
        self::schedule_retry($webhook_url, $text, $attempt + 1, $reason);
    }

    /**
     * 次回リトライを予約する。同一内容の重複予約は行わない。
     */
    private static function schedule_retry(string $webhook_url, string $text, int $next_attempt, string $reason): void
    {
        if ($next_attempt > self::MAX_ATTEMPTS) {
            self::log_failure($text, self::MAX_ATTEMPTS, $reason);
            return;
        }

        // 指数バックオフ：2回目 = 30秒後、3回目 = 60秒後
        $delay = 30 * (2 ** ($next_attempt - 2));
        $args  = [$webhook_url, $text, $next_attempt];

        if (wp_next_scheduled(self::RETRY_HOOK, $args) !== false) {
            return; // 既に同一内容が予約済み
        }
        wp_schedule_single_event(time() + $delay, self::RETRY_HOOK, $args);
    }

    /**
     * 最終失敗を記録する（02 §7.4）。URL は認証情報のためログに出さない。
     */
    private static function log_failure(string $text, int $attempts, string $reason): void
    {
        $summary = mb_substr(str_replace(["\r", "\n"], ' / ', $text), 0, 120);
        error_log(sprintf(
            '[IMS Portal] Google Chat 通知に%d回失敗しました。理由: %s / 本文: %s',
            $attempts,
            $reason !== '' ? $reason : '不明',
            $summary
        ));

        /**
         * 将来の Gmail フォールバック通知（06 §6.8）の拡張ポイント（02 §7.4）。
         * ここに add_action すれば、Chat 不達時の代替通知を差し込める。
         */
        do_action('ims_chat_notify_failed', $text, $attempts, $reason);
    }
}
