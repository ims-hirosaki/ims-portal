/**
 * IMS Hirosaki 管理画面JS（社員編集画面）
 * ・認証方式が Google アカウントのとき、WordPress標準のパスワード関連UIを隠す
 *   （01_user_management.md §3.1：Google SSO ユーザーはWPパスワードを使用しない）。
 */
(function () {
    'use strict';

    function togglePasswordUI() {
        var checked = document.querySelector('.ims-auth-radio:checked');
        if (!checked) { return; }
        var isGoogle = checked.value === 'google_sso';

        // 標準ユーザー編集画面のパスワード行・「パスワードを生成」ボタン行を対象にする
        var selectors = ['.user-pass1-wrap', '.user-pass2-wrap', '.user-generate-reset-pass-wrap', '#password'];
        selectors.forEach(function (sel) {
            document.querySelectorAll(sel).forEach(function (el) {
                var row = el.closest('tr') || el;
                row.style.display = isGoogle ? 'none' : '';
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var radios = document.querySelectorAll('.ims-auth-radio');
        if (!radios.length) { return; }
        radios.forEach(function (r) { r.addEventListener('change', togglePasswordUI); });
        togglePasswordUI();
    });
})();
