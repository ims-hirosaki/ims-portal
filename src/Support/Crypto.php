<?php

declare(strict_types=1);

namespace IMS\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 機密情報（OAuthクライアントシークレット等）を wp_options に平文で置かないための
 * 簡易暗号化ヘルパー（08 §11 準拠）。
 *
 * 鍵は wp-config.php の SALT から導出する。DB が漏洩しても、SALT を知らなければ
 * 復号できない。SALT を変更すると復号できなくなる点に注意（その場合は再設定が必要）。
 */
final class Crypto
{
    private const CIPHER = 'aes-256-cbc';

    public static function available(): bool
    {
        return function_exists('openssl_encrypt') && function_exists('random_bytes');
    }

    private static function key(): string
    {
        // 認証用SALTから32バイト鍵を導出
        return hash('sha256', wp_salt('auth') . '|ims-portal-crypto', true);
    }

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '' || !self::available()) {
            return $plaintext;
        }
        $iv  = random_bytes(16);
        $ct  = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv);
        if ($ct === false) {
            return '';
        }
        return base64_encode($iv . $ct);
    }

    public static function decrypt(string $encoded): string
    {
        if ($encoded === '' || !self::available()) {
            return $encoded;
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 17) {
            return '';
        }
        $iv = substr($raw, 0, 16);
        $ct = substr($raw, 16);
        $pt = openssl_decrypt($ct, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv);
        return $pt === false ? '' : $pt;
    }
}
