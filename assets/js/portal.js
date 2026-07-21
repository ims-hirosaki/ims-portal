/**
 * IMS Hirosaki ポータル共通JS（Phase 0 最小実装）
 *
 * imsPortal グローバル変数（restUrl, nonce）は Assets::enqueue_portal_assets() から
 * wp_localize_script で渡される。各モジュールのJSはこれを使って ims/v1 を叩く。
 *
 * 例：
 *   fetch(imsPortal.restUrl + 'portal/summary', {
 *       headers: { 'X-WP-Nonce': imsPortal.nonce }
 *   }).then(res => res.json());
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        if (typeof window.imsPortal === 'undefined') {
            return;
        }
        // Phase 0 時点では動作確認用の疎通ログのみ。
        // 01/00 モジュール実装時に、バッジ件数取得などの実処理をここに追加する。
        // eslint-disable-next-line no-console
        console.debug('[IMS Portal] assets loaded', window.imsPortal.restUrl);
    });
})();
