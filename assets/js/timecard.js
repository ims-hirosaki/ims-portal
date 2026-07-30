/**
 * 打刻コンソール（/portal/timecard/）のフロントJS。
 *
 * 【2b の範囲】表示の補助のみ。打刻APIは呼ばない。
 *  ① リアルタイム時計（参考値。打刻時刻は必ずサーバー側を使う）
 *  ② 実勤務時間（経過）を勤務中は1秒ごとに進める
 *  ③ 打刻ボタン押下 → 「準備中」トーストを出すだけ（2cでAPI接続）
 *
 * サーバー時刻とブラウザ時刻のズレを吸収するため、初期表示時に
 * サーバーの now（data-server-now）とブラウザの now の差分（オフセット）を
 * 記録し、以降の時計・経過表示はそのオフセットを当てた値で進める。
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var wrap = document.querySelector('.tc-wrap');
        if (!wrap) {
            return;
        }

        var serverNow = parseInt(wrap.getAttribute('data-server-now'), 10) || Math.floor(Date.now() / 1000);
        var status = wrap.getAttribute('data-status') || 'before';
        var workedSec = parseInt(wrap.getAttribute('data-worked-sec'), 10) || 0;

        // サーバー時刻 − ブラウザ時刻（秒）。以降 serverEpoch() で補正した時刻を使う。
        var offset = serverNow - Math.floor(Date.now() / 1000);

        setupClock(offset);
        setupWorkedTimer(status, workedSec, serverNow, offset);
        setupPunchButtons(wrap);
    });

    /** サーバー基準の現在エポック秒 */
    function serverEpoch(offset) {
        return Math.floor(Date.now() / 1000) + offset;
    }

    /** ① 参考時計（HH:MM:SS）を毎秒更新 */
    function setupClock(offset) {
        var el = document.getElementById('tc-clock');
        if (!el) {
            return;
        }
        function tick() {
            var d = new Date(serverEpoch(offset) * 1000);
            el.textContent = pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
        }
        tick();
        setInterval(tick, 1000);
    }

    /**
     * ② 実勤務時間（経過）。
     * 勤務中のみ進める。休憩中・退勤後・出勤前はサーバーが算出した値で固定する
     *  （休憩中に経過を進めると休憩分が労働に混ざるため）。
     */
    function setupWorkedTimer(status, baseWorkedSec, serverNowAtRender, offset) {
        var el = document.getElementById('tc-worked');
        if (!el) {
            return;
        }
        if (status !== 'working') {
            el.textContent = formatDuration(baseWorkedSec);
            return;
        }
        function tick() {
            var elapsed = serverEpoch(offset) - serverNowAtRender;
            if (elapsed < 0) {
                elapsed = 0;
            }
            el.textContent = formatDuration(baseWorkedSec + elapsed);
        }
        tick();
        setInterval(tick, 1000);
    }

    /** ③ 打刻ボタン：2bでは「準備中」トーストのみ */
    function setupPunchButtons(wrap) {
        var buttons = wrap.querySelectorAll('.tc-punch-btn[data-punch]');
        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (btn.disabled) {
                    return;
                }
                showToast('打刻機能は次の更新で有効になります（準備中）');
            });
        });
    }

    // ── ユーティリティ ──

    function formatDuration(totalSec) {
        if (totalSec < 0) {
            totalSec = 0;
        }
        var h = Math.floor(totalSec / 3600);
        var m = Math.floor((totalSec % 3600) / 60);
        return h + '時間 ' + m + '分';
    }

    function pad(n) {
        return (n < 10 ? '0' : '') + n;
    }

    var toastTimer = null;
    function showToast(message) {
        var toast = document.getElementById('tc-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'tc-toast';
            toast.className = 'tc-toast';
            document.body.appendChild(toast);
        }
        toast.textContent = message;
        // reflow を挟んでトランジションを効かせる
        void toast.offsetWidth;
        toast.classList.add('is-visible');

        if (toastTimer) {
            clearTimeout(toastTimer);
        }
        toastTimer = setTimeout(function () {
            toast.classList.remove('is-visible');
        }, 2400);
    }
})();
