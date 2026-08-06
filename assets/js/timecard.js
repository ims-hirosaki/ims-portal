/**
 * 打刻コンソール（/portal/timecard/）のフロントJS。
 *
 * 【2b】表示の補助：①リアルタイム時計 ②実勤務時間（経過）の加算
 * 【2c】打刻API接続：
 *  ③ ボタン押下 → POST ims/v1/timecard/punch → 応答でバッジ・経過・ボタン・当日行を更新
 *  ④ 多重サブミット防止（押下直後に全ボタンを disabled・§4.1）
 *  ⑤ GPS オプトイン（スマホのみ・拒否/失敗しても打刻は続行・§4.1）
 *
 * 打刻時刻は必ずサーバーが決める。このJSは時刻を一切送らない（§3.1）。
 *
 * サーバー時刻とブラウザ時刻のズレを吸収するため、初期表示時に
 * サーバーの now（data-server-now）とブラウザの now の差分（オフセット）を
 * 記録し、以降の時計・経過表示はそのオフセットを当てた値で進める。
 */
(function () {
    'use strict';

    /** 打刻種別 → 日本語ラベル（当日行への差し込み・エラー表示に使う） */
    var PUNCH_LABELS = {
        clock_in: '出勤',
        break_in: '休憩開始',
        break_out: '休憩終了',
        clock_out: '退勤'
    };

    /** GPS 取得のタイムアウト（ms）。打刻を待たせすぎない。 */
    var GEO_TIMEOUT_MS = 5000;

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

        // 経過タイマーは打刻のたびに作り直すため、ハンドルを保持しておく
        var workedTimer = createWorkedTimer(offset);
        workedTimer.reset(status, workedSec, serverNow);

        setupPunchButtons(wrap, workedTimer, offset);
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
     *
     * 打刻するたびに reset() を呼び直して、新しい基準値で数え直す。
     */
    function createWorkedTimer(offset) {
        var el = document.getElementById('tc-worked');
        var timerId = null;

        return {
            reset: function (status, baseWorkedSec, baseServerNow) {
                if (!el) {
                    return;
                }
                if (timerId) {
                    clearInterval(timerId);
                    timerId = null;
                }
                if (status !== 'working') {
                    el.textContent = formatDuration(baseWorkedSec);
                    return;
                }
                function tick() {
                    var elapsed = serverEpoch(offset) - baseServerNow;
                    if (elapsed < 0) {
                        elapsed = 0;
                    }
                    el.textContent = formatDuration(baseWorkedSec + elapsed);
                }
                tick();
                timerId = setInterval(tick, 1000);
            }
        };
    }

    // ── ③〜⑤ 打刻 ──────────────────────────────────────────

    function setupPunchButtons(wrap, workedTimer, offset) {
        var buttons = Array.prototype.slice.call(wrap.querySelectorAll('.tc-punch-btn[data-punch]'));
        if (!buttons.length) {
            return;
        }
        // 退職者などサーバー側で打刻不可の場合は接続しない（サーバーも拒否する）
        if (wrap.getAttribute('data-can-punch') !== '1') {
            return;
        }

        var sending = false;
        // 位置情報は「その日の初回打刻時のみ」許可を求める（§4.1）
        var geoAsked = false;

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (sending || btn.disabled) {
                    return;
                }
                var punchType = btn.getAttribute('data-punch');

                // ④ 押下直後に全ボタンを止める。応答が返るまで解除しない。
                sending = true;
                setAllDisabled(buttons, true);
                setFeedback('送信中…', 'sending');

                var needGeo = isMobile() && !geoAsked;
                geoAsked = true;

                acquirePosition(needGeo).then(function (coords) {
                    return postPunch(punchType, coords);
                }).then(function (res) {
                    handleResponse(res, punchType, wrap, buttons, workedTimer);
                }).catch(function () {
                    // 通信失敗。打刻できたと誤認させないよう明示し、再試行できる状態に戻す（§4.1）
                    setFeedback('通信に失敗しました。打刻は記録されていません。もう一度お試しください。', 'error');
                    showToast('通信に失敗しました。打刻は記録されていません。');
                    restoreButtons(wrap, buttons);
                }).then(function () {
                    sending = false;
                });
            });
        });
    }

    /**
     * ⑤ GPS のオプトイン取得。
     * ・PC では試みない（§4.1）。
     * ・拒否・失敗・タイムアウトのいずれでも null を返し、打刻自体はブロックしない。
     */
    function acquirePosition(enabled) {
        return new Promise(function (resolve) {
            if (!enabled || !navigator.geolocation) {
                resolve(null);
                return;
            }
            var settled = false;
            var done = function (value) {
                if (!settled) {
                    settled = true;
                    resolve(value);
                }
            };
            // ブラウザの timeout が効かない環境に備えて自前でも打ち切る
            setTimeout(function () { done(null); }, GEO_TIMEOUT_MS);

            navigator.geolocation.getCurrentPosition(
                function (pos) {
                    done({ lat: pos.coords.latitude, lng: pos.coords.longitude });
                },
                function () { done(null); },
                { enableHighAccuracy: false, timeout: GEO_TIMEOUT_MS, maximumAge: 60000 }
            );
        });
    }

    /** 打刻APIを叩く。時刻は送らない（サーバー時刻を強制するため）。 */
    function postPunch(punchType, coords) {
        var cfg = window.imsPortal || {};
        var body = { punch_type: punchType };
        if (coords) {
            body.gps_latitude = coords.lat;
            body.gps_longitude = coords.lng;
        }

        return fetch(cfg.restUrl + 'timecard/punch', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': cfg.nonce || ''
            },
            body: JSON.stringify(body)
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (data) {
                return { status: response.status, data: data };
            });
        });
    }

    function handleResponse(res, punchType, wrap, buttons, workedTimer) {
        var data = res.data || {};

        if (res.status >= 200 && res.status < 300 && data.ok) {
            applyState(wrap, buttons, workedTimer, data.state);
            if (data.punch) {
                appendPunchToRow(wrap, data.punch);
            }
            var msg = data.message || (PUNCH_LABELS[punchType] || '打刻') + 'を記録しました。';
            setFeedback(msg, 'success');
            showToast(msg);
            return;
        }

        // 409（状態不一致・重複）はサーバーが現在状態も返す。画面をそれに合わせて直す。
        if (data.state) {
            applyState(wrap, buttons, workedTimer, data.state);
        } else {
            restoreButtons(wrap, buttons);
        }

        var err = data.message || '打刻できませんでした。画面を再読み込みしてお試しください。';
        setFeedback(err, 'error');
        showToast(err);
    }

    /** サーバーが返した状態でコンソールを描き替える（リロードなし・§4.1） */
    function applyState(wrap, buttons, workedTimer, state) {
        if (!state) {
            restoreButtons(wrap, buttons);
            return;
        }

        wrap.setAttribute('data-status', state.status || 'before');
        wrap.setAttribute('data-worked-sec', String(state.worked_seconds || 0));
        if (state.work_date) {
            wrap.setAttribute('data-work-date', state.work_date);
        }

        var badge = document.getElementById('tc-status-badge');
        if (badge) {
            badge.textContent = state.status_label || '';
            badge.className = 'tc-badge tc-badge--' + (state.status_variant || 'before');
        }

        var active = state.active || [];
        buttons.forEach(function (btn) {
            var isActive = active.indexOf(btn.getAttribute('data-punch')) !== -1;
            btn.disabled = !isActive;
            btn.classList.toggle('is-inactive', !isActive);
        });

        workedTimer.reset(state.status, state.worked_seconds || 0, state.server_now || Math.floor(Date.now() / 1000));
    }

    /** 通信失敗時：状態は変わっていないので、直前の活性状態へ戻す */
    function restoreButtons(wrap, buttons) {
        buttons.forEach(function (btn) {
            btn.disabled = btn.classList.contains('is-inactive');
        });
    }

    function setAllDisabled(buttons, disabled) {
        buttons.forEach(function (btn) {
            btn.disabled = disabled;
        });
    }

    /** 打刻履歴テーブルの該当日の行へ、記録された時刻を差し込む */
    function appendPunchToRow(wrap, punch) {
        var row = wrap.querySelector('tr[data-date="' + punch.work_date + '"]');
        if (!row) {
            // 表示中の月が違う（月送り中）などで行がない場合は何もしない
            return;
        }
        var cell = row.querySelector('td[data-punch-cell="' + punch.punch_type + '"]');
        if (!cell) {
            return;
        }
        // 初回は「—」が入っているので消す
        if (cell.querySelectorAll('.tc-time').length === 0) {
            cell.textContent = '';
        }
        var span = document.createElement('span');
        span.className = 'tc-time';
        span.textContent = punch.time;
        cell.appendChild(span);
    }

    // ── ユーティリティ ──

    /**
     * スマートフォン判定。§4.1 は「スマートフォンのブラウザからアクセスした場合」に
     * 限って位置情報を求めると定めているため、粗いポインタ＋タッチ対応を条件にする。
     */
    function isMobile() {
        if (!window.matchMedia) {
            return false;
        }
        return window.matchMedia('(pointer: coarse)').matches && (navigator.maxTouchPoints || 0) > 0;
    }

    function setFeedback(message, variant) {
        var el = document.getElementById('tc-feedback');
        if (!el) {
            return;
        }
        el.textContent = message;
        el.className = 'tc-punch-hint is-' + variant;
    }

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
