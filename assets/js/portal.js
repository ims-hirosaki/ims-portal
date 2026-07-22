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
        setupLauncherSchemeLaunch();

        if (typeof window.imsPortal === 'undefined') {
            return;
        }
        // Phase 0 時点では動作確認用の疎通ログのみ。
        // 01/00 モジュール実装時に、バッジ件数取得などの実処理をここに追加する。
        // eslint-disable-next-line no-console
        console.debug('[IMS Portal] assets loaded', window.imsPortal.restUrl);
    });

    /**
     * 外部ツールランチャー：protocol_scheme があるツールは、クリック時に
     * デスクトップアプリ起動を優先し、失敗時は data-href（Web URL）を別タブで開く。
     * ブラウザ仕様上、起動可否の確実な検知はできないため、短いタイムアウトで
     * フォールバックする一般的な方式を用いる。
     */
    function setupLauncherSchemeLaunch() {
        var items = document.querySelectorAll('.ims-launcher-item[data-scheme]');
        items.forEach(function (link) {
            link.addEventListener('click', function (e) {
                var scheme = link.getAttribute('data-scheme');
                var webUrl = link.getAttribute('href');
                if (!scheme) { return; }
                e.preventDefault();

                // アプリ起動を試みる（隠しiframe）
                var iframe = document.createElement('iframe');
                iframe.style.display = 'none';
                iframe.src = scheme;
                document.body.appendChild(iframe);

                // 一定時間後、Web URL を別タブで開く（アプリが前面化すれば
                // ページはブラー状態になり、このタイマは実質無視されやすい）
                var fallback = window.setTimeout(function () {
                    window.open(webUrl, '_blank', 'noopener,noreferrer');
                }, 1200);
                window.addEventListener('blur', function () {
                    window.clearTimeout(fallback);
                }, { once: true });

                window.setTimeout(function () {
                    if (iframe.parentNode) { iframe.parentNode.removeChild(iframe); }
                }, 2000);
            });
        });
    }
})();
