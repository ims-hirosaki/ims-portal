/**
 * 打刻システム設定画面（ポータル設定 > 打刻システム設定）のJS。
 *
 * 役割は3つだけで、業務ロジックは持たない：
 *  ① 左メニューによるセクション切り替え（ワイヤーフレームの switchSetting 相当）
 *  ② ラジオ選択時のカード見た目の更新
 *  ③ 「テスト送信」ボタン → 入力中のURLを別フォームへ渡して送信
 *
 * JS が無効でも、保存自体は通常のフォーム送信で動作する
 * （その場合セクションは全件が縦に並ぶのではなく、初期表示のものだけが見える）。
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('.ims-tc-settings');
        if (!root) {
            return;
        }
        setupSectionSwitch(root);
        setupRadioHighlight(root);
        setupTestSend(root);
    });

    /** ① セクション切り替え */
    function setupSectionSwitch(root) {
        var items = root.querySelectorAll('.ims-tc-menu-item');
        var activeField = root.querySelector('#ims-tc-active-section');

        items.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = btn.getAttribute('data-target');
                if (!target) {
                    return;
                }

                items.forEach(function (b) { b.classList.remove('is-active'); });
                btn.classList.add('is-active');

                root.querySelectorAll('.ims-tc-section').forEach(function (sec) {
                    sec.classList.toggle('is-active', sec.id === 'ims-tc-' + target);
                });

                // 保存後に同じセクションへ戻れるよう、現在位置をフォームに持たせる
                if (activeField) {
                    activeField.value = target;
                }
            });
        });
    }

    /** ② ラジオの選択状態をカードの見た目に反映 */
    function setupRadioHighlight(root) {
        var radios = root.querySelectorAll('.ims-tc-radio input[type="radio"]');
        radios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                root.querySelectorAll('.ims-tc-radio').forEach(function (card) {
                    var input = card.querySelector('input[type="radio"]');
                    card.classList.toggle('is-selected', !!input && input.checked);
                });
            });
        });
    }

    /**
     * ③ テスト送信
     * 入力欄に未保存のURLがあればそれを送る。空欄なら保存済みの設定値がサーバー側で使われる。
     */
    function setupTestSend(root) {
        var btn = root.querySelector('#ims-tc-test-btn');
        var form = root.querySelector('#ims-tc-test-form');
        var urlField = root.querySelector('#chat_webhook_url');
        var hidden = root.querySelector('#ims-tc-test-url');

        if (!btn || !form || !hidden) {
            return;
        }

        btn.addEventListener('click', function () {
            hidden.value = urlField ? urlField.value.trim() : '';

            // 二重送信防止。画面遷移するのでこのまま無効のままでよい。
            btn.disabled = true;
            btn.textContent = '送信中…';
            form.submit();
        });
    }
})();
