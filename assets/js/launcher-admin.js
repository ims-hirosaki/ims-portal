/**
 * IMS Hirosaki 外部ツール設定（ランチャー管理）JS
 * ・アイコンをメディアライブラリから選択（wp.media）
 * ・起動方法トグルでデスクトップアプリ起動URL欄を出し分け
 * ・アイコン/ツール名のバー上プレビュー
 * ・一覧のドラッグ並べ替え（HTML5 DnD）→ admin-ajax で sort_order 保存
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        setupMediaPicker();
        setupLaunchMethodToggle();
        setupLivePreview();
        setupDragReorder();
    });

    // ── アイコン選択（メディアライブラリ） ──
    function setupMediaPicker() {
        var selectBtn = document.getElementById('ims-icon-select');
        var clearBtn = document.getElementById('ims-icon-clear');
        var urlInput = document.getElementById('ims-icon-url');
        var preview = document.getElementById('ims-icon-preview');
        var previewBar = document.getElementById('ims-preview-icon');
        if (!selectBtn || !urlInput) { return; }

        var frame = null;
        selectBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (typeof wp === 'undefined' || !wp.media) { return; }
            if (frame) { frame.open(); return; }
            frame = wp.media({
                title: 'アイコン画像を選択',
                button: { text: 'このアイコンを使用' },
                library: { type: ['image'] },
                multiple: false
            });
            frame.on('select', function () {
                var att = frame.state().get('selection').first().toJSON();
                setIcon(att.url);
            });
            frame.open();
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', function (e) {
                e.preventDefault();
                setIcon('');
            });
        }

        function setIcon(url) {
            urlInput.value = url;
            [preview, previewBar].forEach(function (img) {
                if (!img) { return; }
                if (url) { img.src = url; img.style.display = ''; }
                else { img.removeAttribute('src'); img.style.display = 'none'; }
            });
        }
    }

    // ── 起動方法トグル ──
    function setupLaunchMethodToggle() {
        var radios = document.querySelectorAll('.ims-launch-method');
        var schemeRow = document.getElementById('ims-scheme-row');
        if (!radios.length || !schemeRow) { return; }
        function apply() {
            var checked = document.querySelector('.ims-launch-method:checked');
            schemeRow.style.display = (checked && checked.value === 'app') ? '' : 'none';
        }
        radios.forEach(function (r) { r.addEventListener('change', apply); });
        apply();
    }

    // ── バー上プレビュー（ツール名） ──
    function setupLivePreview() {
        var nameInput = document.querySelector('#ims-launcher-form input[name="app_name"]');
        var label = document.getElementById('ims-preview-label');
        if (!nameInput || !label) { return; }
        nameInput.addEventListener('input', function () {
            label.textContent = nameInput.value || 'ツール名';
        });
    }

    // ── ドラッグ並べ替え ──
    function setupDragReorder() {
        var table = document.getElementById('ims-launcher-table');
        if (!table || typeof imsLauncher === 'undefined') { return; }
        var tbody = table.querySelector('tbody');
        if (!tbody) { return; }

        var dragEl = null;

        tbody.querySelectorAll('tr[data-id]').forEach(function (row) {
            var handle = row.querySelector('.ims-drag-handle');
            if (!handle) { return; }
            row.setAttribute('draggable', 'false');
            handle.addEventListener('mousedown', function () { row.setAttribute('draggable', 'true'); });
            row.addEventListener('mouseup', function () { row.setAttribute('draggable', 'false'); });

            row.addEventListener('dragstart', function () {
                dragEl = row;
                row.style.opacity = '0.4';
            });
            row.addEventListener('dragend', function () {
                row.style.opacity = '';
                row.setAttribute('draggable', 'false');
                persistOrder();
            });
            row.addEventListener('dragover', function (e) {
                e.preventDefault();
                if (!dragEl || dragEl === row) { return; }
                var rect = row.getBoundingClientRect();
                var after = (e.clientY - rect.top) > (rect.height / 2);
                tbody.insertBefore(dragEl, after ? row.nextSibling : row);
            });
        });

        function persistOrder() {
            var ids = Array.prototype.map.call(
                tbody.querySelectorAll('tr[data-id]'),
                function (r) { return r.getAttribute('data-id'); }
            );
            var body = new URLSearchParams();
            body.append('action', 'ims_launcher_reorder');
            body.append('nonce', imsLauncher.reorderNonce);
            ids.forEach(function (id) { body.append('order[]', id); });

            fetch(imsLauncher.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).catch(function () { /* 失敗時は次回リロードで再取得 */ });
        }
    }
})();
