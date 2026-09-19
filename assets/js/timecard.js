/**
 * 打刻コンソール（/portal/timecard/）のフロントJS。
 *
 * 【2b】表示の補助：①リアルタイム時計 ②実勤務時間（経過）の加算
 * 【2c】打刻API接続：
 *  ③ ボタン押下 → POST ims/v1/timecard/punch → 応答でバッジ・経過・ボタン・当日行を更新
 *  ④ 多重サブミット防止（押下直後に全ボタンを disabled・§4.1）
 *  ⑤ GPS オプトイン（スマホのみ・拒否/失敗しても打刻は続行・§4.1）
 * 【2d】退勤時の休憩補完・確認（02_time_tracking.md §3.2）：
 *  ⑥ ケースA：休憩中に退勤ボタン → 休憩補完ポップアップ（分数選択）
 *  ⑦ ケースB：休憩未打刻・実勤務6時間超で退勤ボタン → 確認ポップアップ
 *     （休憩を登録＝開始・終了時刻を直接入力 ／ 休憩なしで退勤＝通常の退勤と同じ）
 * 【2e】打刻修正（§3.4）：履歴テーブルの「修正」ボタン → 対象日の打刻一覧から1件選択
 *     → 新しい日時・修正理由を入力して保存。権限・締めロック・整合性チェックは
 *     すべてサーバー側（PunchService::correct_punch）が行う。
 * 【2h】打刻の追加：履歴テーブルの「追加」ボタン → 打刻種別・日時・理由を入力して保存。
 *     「修正」と違い対象を選ぶ手順が無い（存在しない打刻を新規作成するため）。
 *     権限・締めロック・整合性チェックはすべてサーバー側（PunchService::add_missing_punch）
 *     が行う。
 *
 * 打刻時刻は必ずサーバーが決める。このJSは時刻を一切送らない（§3.1）。
 * ケースA/Bのポップアップが表示する時刻・経過時間も、サーバー時刻ベースの
 * 値（後述の offset 補正）から計算するのみで、送信する値は「分数」または
 * 「HH:MM」の時刻文字列であり、最終的な妥当性はサーバー側で必ず再検証される。
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
        setupCorrectionButtons(wrap);
        setupAddButtons(wrap);
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

    // ── ③〜⑦ 打刻 ──────────────────────────────────────────

    function setupPunchButtons(wrap, workedTimer, offset) {
        var buttons = Array.prototype.slice.call(wrap.querySelectorAll('.tc-punch-btn[data-punch]'));
        if (!buttons.length) {
            return;
        }
        // 退職者などサーバー側で打刻不可の場合は接続しない（サーバーも拒否する）
        if (wrap.getAttribute('data-can-punch') !== '1') {
            return;
        }

        // 2d：ケースA/Bの判定・補完に使う状態。押下ごとの sending/geoAsked も含めて
        // ここに集約し、モーダル側の関数にもそのまま渡す。
        var ctx = {
            offset: offset,
            sending: false,
            // 位置情報は「その日の初回打刻時のみ」許可を求める（§4.1）
            geoAsked: false,
            clockInEpoch: parseInt(wrap.getAttribute('data-clock-in-epoch'), 10) || 0,
            breakInEpoch: parseInt(wrap.getAttribute('data-break-in-epoch'), 10) || 0,
            hasBreakToday: wrap.getAttribute('data-has-break') === '1'
        };

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (ctx.sending || btn.disabled) {
                    return;
                }
                var punchType = btn.getAttribute('data-punch');

                if (punchType === 'clock_out') {
                    var currentStatus = wrap.getAttribute('data-status');

                    // ケースA（§3.2）：休憩中に退勤 → 休憩補完ポップアップ
                    if (currentStatus === 'break') {
                        openCaseAModal(ctx, wrap, buttons, workedTimer);
                        return;
                    }

                    // ケースB（§3.2）：休憩未打刻・実勤務6時間超 → 確認ポップアップ
                    if (currentStatus === 'working' && !ctx.hasBreakToday) {
                        var elapsed = serverEpoch(ctx.offset) - ctx.clockInEpoch;
                        if (elapsed > laborCfg().tier1Hours * 3600) {
                            openCaseBModal(ctx, wrap, buttons, workedTimer);
                            return;
                        }
                    }
                }

                performPunch(punchType, wrap, buttons, workedTimer, ctx);
            });
        });
    }

    /** 通常の打刻（③〜⑤の本体）。ケースB「休憩なしで退勤する」からも呼ばれる。 */
    function performPunch(punchType, wrap, buttons, workedTimer, ctx) {
        ctx.sending = true;
        setAllDisabled(buttons, true);
        setFeedback('送信中…', 'sending');

        var needGeo = isMobile() && !ctx.geoAsked;
        ctx.geoAsked = true;

        acquirePosition(needGeo).then(function (coords) {
            return postPunch(punchType, coords);
        }).then(function (res) {
            handleResponse(res, punchType, wrap, buttons, workedTimer, ctx);
        }).catch(function () {
            // 通信失敗。打刻できたと誤認させないよう明示し、再試行できる状態に戻す（§4.1）
            setFeedback('通信に失敗しました。打刻は記録されていません。もう一度お試しください。', 'error');
            showToast('通信に失敗しました。打刻は記録されていません。');
            restoreButtons(wrap, buttons);
        }).then(function () {
            ctx.sending = false;
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

    /** ims/v1 配下へのPOST共通処理。 */
    function restPost(path, body) {
        var cfg = window.imsPortal || {};
        return fetch(cfg.restUrl + path, {
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

    /** 打刻APIを叩く。時刻は送らない（サーバー時刻を強制するため）。 */
    function postPunch(punchType, coords) {
        var body = { punch_type: punchType };
        if (coords) {
            body.gps_latitude = coords.lat;
            body.gps_longitude = coords.lng;
        }
        return restPost('timecard/punch', body);
    }

    /** 2d：ケースA・ケースBのAPIルート（TimecardPage::enqueue() が localize）。 */
    function routes() {
        return (window.imsTimecard || {}).routes || {};
    }

    /** 2d：労基法の休憩の目安（PunchService の定数が正本。JS は数値を持たない）。 */
    function laborCfg() {
        return (window.imsTimecard || {}).laborBreak || {
            tier1Hours: 6, tier2Hours: 8, tier1Minutes: 45, tier2Minutes: 60
        };
    }

    function handleResponse(res, punchType, wrap, buttons, workedTimer, ctx) {
        var data = res.data || {};

        if (res.status >= 200 && res.status < 300 && data.ok) {
            applyState(wrap, buttons, workedTimer, data.state);
            if (data.punch) {
                appendPunchToRow(wrap, data.punch);
            }
            // 2d：休憩を1件でも打刻したら、以後ケースBの確認は不要になる
            if (punchType === 'break_in') {
                ctx.hasBreakToday = true;
                ctx.breakInEpoch = serverEpoch(ctx.offset);
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

    /**
     * 2d：ケースA/Bの補完API（clock_outを含む複数レコードを1回で保存する）の応答処理。
     * handle_punch() とは異なり `punches`（複数形）で返る。
     */
    function handleMultiPunchResponse(res, wrap, buttons, workedTimer, ctx) {
        var data = res.data || {};

        if (res.status >= 200 && res.status < 300 && data.ok) {
            applyState(wrap, buttons, workedTimer, data.state);
            (data.punches || []).forEach(function (p) {
                appendPunchToRow(wrap, p);
            });
            var msg = data.message || '退勤を記録しました。';
            setFeedback(msg, 'success');
            showToast(msg);
            return;
        }

        if (data.state) {
            applyState(wrap, buttons, workedTimer, data.state);
        } else {
            restoreButtons(wrap, buttons);
        }

        var err = data.message || '操作できませんでした。画面を再読み込みしてお試しください。';
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
        // 2h：log_id・punched_at を持たせておくと、リロードなしで直後に「修正」の
        // 対象としても選べる（render_times() のサーバー描画と同じ属性）。
        if (punch.log_id) {
            span.setAttribute('data-log-id', String(punch.log_id));
        }
        if (punch.punched_at) {
            span.setAttribute('data-punched-at', punch.punched_at);
        }
        span.textContent = punch.time;
        // 2d：休憩補完・登録で保存したログは自動補完タグを添える（サーバー描画と同じ見た目）
        if (punch.is_auto_filled) {
            span.appendChild(document.createTextNode(' '));
            var tag = document.createElement('span');
            tag.className = 'tc-auto-tag';
            tag.textContent = '自動補完';
            span.appendChild(tag);
        }
        cell.appendChild(span);
    }

    // ── 2d：ケースA（休憩中の退勤）ポップアップ ─────────────

    function openCaseAModal(ctx, wrap, buttons, workedTimer) {
        var now = serverEpoch(ctx.offset);
        var maxMinutes = Math.max(0, Math.floor((now - ctx.breakInEpoch) / 60));
        var elapsedSinceClockIn = Math.max(0, now - ctx.clockInEpoch);
        var cfg = laborCfg();

        var m = createModal('休憩を終了して退勤しますか？');

        var info = document.createElement('p');
        info.className = 'tc-modal-info';
        info.textContent = '休憩を始めた時刻：' + formatHm(ctx.breakInEpoch) +
            '　／　出勤からの経過時間：' + formatDuration(elapsedSinceClockIn);
        m.body.appendChild(info);

        m.body.appendChild(buildLawInfoBox(caseANote(elapsedSinceClockIn, cfg)));

        var optionsWrap = document.createElement('div');
        optionsWrap.className = 'tc-break-options';

        var chosenMinutes = null;

        function addRadioOption(value, label, badgeText) {
            var row = document.createElement('label');
            row.className = 'tc-break-option';
            var input = document.createElement('input');
            input.type = 'radio';
            input.name = 'tc-break-minutes-choice';
            input.value = String(value);
            row.appendChild(input);
            var span = document.createElement('span');
            span.textContent = label;
            row.appendChild(span);
            if (badgeText) {
                var badge = document.createElement('span');
                badge.className = 'tc-badge-tag';
                badge.textContent = badgeText;
                row.appendChild(badge);
            }
            optionsWrap.appendChild(row);
            input.addEventListener('change', function () {
                chosenMinutes = value;
                customInput.value = '';
                updateSubmitState();
            });
        }

        if (maxMinutes >= cfg.tier1Minutes) {
            addRadioOption(cfg.tier1Minutes, cfg.tier1Minutes + '分', '6〜8時間勤務の法定最低ライン');
        }
        if (maxMinutes >= cfg.tier2Minutes) {
            var recommend = elapsedSinceClockIn > cfg.tier2Hours * 3600 ? '推奨' : null;
            addRadioOption(cfg.tier2Minutes, cfg.tier2Minutes + '分', recommend);
        }

        var hasPresetOption = maxMinutes >= cfg.tier1Minutes;

        var customRow = document.createElement('label');
        customRow.className = 'tc-break-option';
        var customRadio = document.createElement('input');
        customRadio.type = 'radio';
        customRadio.name = 'tc-break-minutes-choice';
        customRow.appendChild(customRadio);
        var customLabelSpan = document.createElement('span');
        customLabelSpan.textContent = 'その他の時間を入力：';
        customRow.appendChild(customLabelSpan);
        var customInput = document.createElement('input');
        customInput.type = 'number';
        customInput.min = '1';
        customInput.max = String(maxMinutes);
        customInput.className = 'tc-break-custom-input';
        customRow.appendChild(customInput);
        var customUnit = document.createElement('span');
        customUnit.textContent = '分';
        customRow.appendChild(customUnit);
        optionsWrap.appendChild(customRow);

        if (!hasPresetOption) {
            var hint = document.createElement('p');
            hint.className = 'tc-modal-hint';
            hint.textContent = '最大 ' + maxMinutes + ' 分まで入力可能です。';
            optionsWrap.insertBefore(hint, customRow);
        }

        m.body.appendChild(optionsWrap);

        var errorEl = document.createElement('p');
        errorEl.className = 'tc-modal-error';
        errorEl.hidden = true;
        m.body.appendChild(errorEl);

        var actions = document.createElement('div');
        actions.className = 'tc-modal-actions';
        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'tc-modal-btn is-ghost';
        cancelBtn.textContent = 'キャンセル';
        cancelBtn.addEventListener('click', m.close);
        var submitBtn = document.createElement('button');
        submitBtn.type = 'button';
        submitBtn.className = 'tc-modal-btn is-primary';
        submitBtn.textContent = '休憩を登録して退勤する';
        submitBtn.disabled = true;
        actions.appendChild(cancelBtn);
        actions.appendChild(submitBtn);
        m.body.appendChild(actions);

        function currentMinutesValue() {
            if (customRadio.checked) {
                var v = parseInt(customInput.value, 10);
                return isNaN(v) ? null : v;
            }
            return chosenMinutes;
        }

        function updateSubmitState() {
            var v = currentMinutesValue();
            if (v === null) {
                submitBtn.disabled = true;
                errorEl.hidden = true;
                return;
            }
            if (v > maxMinutes) {
                errorEl.textContent = '入力した時間が上限（' + maxMinutes + '分）を超えています。';
                errorEl.hidden = false;
                submitBtn.disabled = true;
                return;
            }
            if (v < 1) {
                errorEl.textContent = '1分以上を入力してください。';
                errorEl.hidden = false;
                submitBtn.disabled = true;
                return;
            }
            errorEl.hidden = true;
            submitBtn.disabled = false;
        }

        customRadio.addEventListener('change', updateSubmitState);
        customInput.addEventListener('input', function () {
            customRadio.checked = true;
            updateSubmitState();
        });
        customInput.addEventListener('focus', function () {
            customRadio.checked = true;
            updateSubmitState();
        });

        submitBtn.addEventListener('click', function () {
            var minutes = currentMinutesValue();
            if (minutes === null || minutes < 1 || minutes > maxMinutes) {
                return;
            }
            setAllDisabled(buttons, true);
            setFeedback('送信中…', 'sending');

            restPost(routes().completeBreak, { minutes: minutes }).then(function (res) {
                m.close();
                handleMultiPunchResponse(res, wrap, buttons, workedTimer, ctx);
            }).catch(function () {
                m.close();
                setFeedback('通信に失敗しました。打刻は記録されていません。もう一度お試しください。', 'error');
                showToast('通信に失敗しました。打刻は記録されていません。');
                restoreButtons(wrap, buttons);
            });
        });
    }

    /** ケースA：労基法インフォボックスの補足メッセージ（「本日の勤務時間は8時間超のため…」）。 */
    function caseANote(elapsedSeconds, cfg) {
        if (elapsedSeconds > cfg.tier2Hours * 3600) {
            return '本日の勤務時間は' + cfg.tier2Hours + '時間超のため、' + cfg.tier2Minutes + '分以上の休憩が推奨されます。';
        }
        if (elapsedSeconds > cfg.tier1Hours * 3600) {
            return '本日の勤務時間は' + cfg.tier1Hours + '時間超のため、' + cfg.tier1Minutes + '分以上の休憩が推奨されます。';
        }
        return '';
    }

    // ── 2d：ケースB（休憩未打刻・6時間超での退勤）ポップアップ ─

    function openCaseBModal(ctx, wrap, buttons, workedTimer) {
        var now = serverEpoch(ctx.offset);
        // ケースBの前提（当日 break_in が0件）より、経過時間＝実勤務時間になる
        var workedSeconds = Math.max(0, now - ctx.clockInEpoch);
        var cfg = laborCfg();

        var m = createModal('休憩を取らずに退勤しますか？');

        var info = document.createElement('p');
        info.className = 'tc-modal-info';
        info.textContent = '出勤時刻：' + formatHm(ctx.clockInEpoch) +
            '　／　現在の勤務時間：' + formatDuration(workedSeconds);
        m.body.appendChild(info);

        m.body.appendChild(buildLawInfoBox(caseBNote(workedSeconds, cfg)));

        var actions = document.createElement('div');
        actions.className = 'tc-modal-actions tc-modal-actions--stacked';

        var registerBtn = document.createElement('button');
        registerBtn.type = 'button';
        registerBtn.className = 'tc-modal-btn is-primary';
        registerBtn.textContent = '休憩を登録して退勤する';
        registerBtn.addEventListener('click', function () {
            m.close();
            openCaseBRangeModal(ctx, wrap, buttons, workedTimer);
        });

        var noBreakBtn = document.createElement('button');
        noBreakBtn.type = 'button';
        noBreakBtn.className = 'tc-modal-btn is-ghost';
        noBreakBtn.textContent = '休憩なしで退勤する';
        noBreakBtn.addEventListener('click', function () {
            m.close();
            performPunch('clock_out', wrap, buttons, workedTimer, ctx);
        });

        actions.appendChild(registerBtn);
        actions.appendChild(noBreakBtn);
        m.body.appendChild(actions);

        var note = document.createElement('p');
        note.className = 'tc-modal-note';
        note.textContent = '休憩なしで退勤を選択した場合も退勤は完了します。休憩の登録はシステムでは強制しません。';
        m.body.appendChild(note);
    }

    /** ケースB：労基法インフォボックスの補足メッセージ（「現在7時間28分のため…」）。 */
    function caseBNote(workedSeconds, cfg) {
        var totalMin = Math.floor(workedSeconds / 60);
        var hm = Math.floor(totalMin / 60) + '時間' + (totalMin % 60) + '分';
        if (workedSeconds > cfg.tier2Hours * 3600) {
            return '現在' + hm + 'のため、' + cfg.tier2Minutes + '分以上の休憩が必要です。';
        }
        if (workedSeconds > cfg.tier1Hours * 3600) {
            return '現在' + hm + 'のため、' + cfg.tier1Minutes + '分以上の休憩が必要です。';
        }
        return '';
    }

    /** ケースB・ボタンA：休憩の開始・終了時刻を直接入力する画面。 */
    function openCaseBRangeModal(ctx, wrap, buttons, workedTimer) {
        var m = createModal('休憩の時刻を入力してください');

        var form = document.createElement('div');
        form.className = 'tc-break-range-form';

        var startLabel = document.createElement('label');
        startLabel.textContent = '休憩開始';
        var startInput = document.createElement('input');
        startInput.type = 'time';
        startLabel.appendChild(startInput);

        var endLabel = document.createElement('label');
        endLabel.textContent = '休憩終了';
        var endInput = document.createElement('input');
        endInput.type = 'time';
        endLabel.appendChild(endInput);

        form.appendChild(startLabel);
        form.appendChild(endLabel);
        m.body.appendChild(form);

        var errorEl = document.createElement('p');
        errorEl.className = 'tc-modal-error';
        errorEl.hidden = true;
        m.body.appendChild(errorEl);

        var actions = document.createElement('div');
        actions.className = 'tc-modal-actions';
        var backBtn = document.createElement('button');
        backBtn.type = 'button';
        backBtn.className = 'tc-modal-btn is-ghost';
        backBtn.textContent = 'キャンセル';
        backBtn.addEventListener('click', m.close);

        var submitBtn = document.createElement('button');
        submitBtn.type = 'button';
        submitBtn.className = 'tc-modal-btn is-primary';
        submitBtn.textContent = '休憩を登録して退勤する';
        submitBtn.disabled = true;

        actions.appendChild(backBtn);
        actions.appendChild(submitBtn);
        m.body.appendChild(actions);

        // 簡易な事前チェックのみ（日をまたぐ入力もあり得るため、厳密な妥当性は
        // サーバー側の parse_time_on_or_after() が判定する）。
        function validate() {
            if (!startInput.value || !endInput.value) {
                return false;
            }
            if (startInput.value === endInput.value) {
                errorEl.textContent = '休憩開始と休憩終了に同じ時刻は指定できません。';
                errorEl.hidden = false;
                return false;
            }
            errorEl.hidden = true;
            return true;
        }

        [startInput, endInput].forEach(function (el) {
            el.addEventListener('input', function () {
                submitBtn.disabled = !validate();
            });
        });

        submitBtn.addEventListener('click', function () {
            if (!validate()) {
                return;
            }
            setAllDisabled(buttons, true);
            setFeedback('送信中…', 'sending');

            restPost(routes().clockOutWithBreak, {
                break_in: startInput.value,
                break_out: endInput.value
            }).then(function (res) {
                m.close();
                handleMultiPunchResponse(res, wrap, buttons, workedTimer, ctx);
            }).catch(function () {
                m.close();
                setFeedback('通信に失敗しました。打刻は記録されていません。もう一度お試しください。', 'error');
                showToast('通信に失敗しました。打刻は記録されていません。');
                restoreButtons(wrap, buttons);
            });
        });
    }

    // ── 2e：打刻修正（§3.4） ──────────────────────────────────

    /**
     * 履歴テーブルの「修正」ボタンに接続する。ボタンは1日1個（表の「操作」列）だが
     * 修正対象は個々の打刻（log_id）単位のため、押下後まず対象日の打刻一覧から
     * 1件を選ばせ、それから新日時・理由の入力へ進む2段階の導線にしてある。
     */
    function setupCorrectionButtons(wrap) {
        var buttons = Array.prototype.slice.call(wrap.querySelectorAll('.tc-correct-btn[data-correct-date]'));
        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                openCorrectionPickerModal(wrap, btn.getAttribute('data-correct-date'));
            });
        });
    }

    /** 指定日の打刻を、履歴テーブルのDOM（data-log-id）から集める。 */
    function collectDayPunches(wrap, date) {
        var row = wrap.querySelector('tr[data-date="' + date + '"]');
        if (!row) {
            return [];
        }
        var result = [];
        Array.prototype.slice.call(row.querySelectorAll('td[data-punch-cell]')).forEach(function (td) {
            var type = td.getAttribute('data-punch-cell');
            Array.prototype.slice.call(td.querySelectorAll('.tc-time[data-log-id]')).forEach(function (span) {
                result.push({
                    logId: span.getAttribute('data-log-id'),
                    punchType: type,
                    punchedAt: span.getAttribute('data-punched-at'),
                    label: PUNCH_LABELS[type] || type
                });
            });
        });
        return result;
    }

    /** サーバー文字列 'Y-m-d H:i:s' から HH:MM だけを切り出す（再パースしない）。 */
    function timeOnly(punchedAt) {
        return punchedAt && punchedAt.length >= 16 ? punchedAt.substring(11, 16) : '--:--';
    }

    /** ステップ1：対象日の打刻一覧から、修正したい1件を選ばせる。 */
    function openCorrectionPickerModal(wrap, date) {
        var punches = collectDayPunches(wrap, date);
        if (!punches.length) {
            return;
        }

        var m = createModal('修正する打刻を選んでください（' + date + '）');

        var list = document.createElement('div');
        list.className = 'tc-break-options';

        punches.forEach(function (p) {
            var row = document.createElement('label');
            row.className = 'tc-break-option';
            var input = document.createElement('input');
            input.type = 'radio';
            input.name = 'tc-correct-pick';
            row.appendChild(input);
            var span = document.createElement('span');
            span.textContent = p.label + '：' + timeOnly(p.punchedAt);
            row.appendChild(span);
            list.appendChild(row);

            input.addEventListener('change', function () {
                m.close();
                openCorrectionFormModal(wrap, date, p);
            });
        });
        m.body.appendChild(list);

        var actions = document.createElement('div');
        actions.className = 'tc-modal-actions';
        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'tc-modal-btn is-ghost';
        cancelBtn.textContent = 'キャンセル';
        cancelBtn.addEventListener('click', m.close);
        actions.appendChild(cancelBtn);
        m.body.appendChild(actions);
    }

    /** ステップ2：新しい日時・修正理由を入力し、保存する。 */
    function openCorrectionFormModal(wrap, date, punch) {
        var m = createModal((PUNCH_LABELS[punch.punchType] || punch.punchType) + 'の修正');

        var info = document.createElement('p');
        info.className = 'tc-modal-info';
        info.textContent = '対象日：' + date + '　／　現在の打刻時刻：' + timeOnly(punch.punchedAt);
        m.body.appendChild(info);

        var dtLabel = document.createElement('label');
        dtLabel.className = 'tc-correct-field';
        var dtSpan = document.createElement('span');
        dtSpan.textContent = '修正後の日時';
        dtLabel.appendChild(dtSpan);
        var dtInput = document.createElement('input');
        dtInput.type = 'datetime-local';
        if (punch.punchedAt) {
            dtInput.value = punch.punchedAt.replace(' ', 'T').substring(0, 16);
        }
        dtLabel.appendChild(dtInput);
        m.body.appendChild(dtLabel);

        var reasonLabel = document.createElement('label');
        reasonLabel.className = 'tc-correct-field';
        var reasonSpan = document.createElement('span');
        reasonSpan.textContent = '修正理由';
        reasonLabel.appendChild(reasonSpan);
        var reasonInput = document.createElement('textarea');
        reasonInput.rows = 3;
        reasonLabel.appendChild(reasonInput);
        m.body.appendChild(reasonLabel);

        var errorEl = document.createElement('p');
        errorEl.className = 'tc-modal-error';
        errorEl.hidden = true;
        m.body.appendChild(errorEl);

        var steps = buildCorrectionSteps();
        steps.el.hidden = true;
        m.body.appendChild(steps.el);

        var actions = document.createElement('div');
        actions.className = 'tc-modal-actions';
        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'tc-modal-btn is-ghost';
        cancelBtn.textContent = 'キャンセル';
        cancelBtn.addEventListener('click', m.close);
        var submitBtn = document.createElement('button');
        submitBtn.type = 'button';
        submitBtn.className = 'tc-modal-btn is-primary';
        submitBtn.textContent = '修正を登録する';
        actions.appendChild(cancelBtn);
        actions.appendChild(submitBtn);
        m.body.appendChild(actions);

        submitBtn.addEventListener('click', function () {
            var reason = reasonInput.value.trim();
            if (!dtInput.value || !reason) {
                errorEl.textContent = '修正後の日時と修正理由は必須です。';
                errorEl.hidden = false;
                return;
            }
            errorEl.hidden = true;
            submitBtn.disabled = true;
            cancelBtn.disabled = true;
            steps.el.hidden = false;
            steps.setStep(1); // ①修正内容を記録

            var correctedDatetime = dtInput.value.replace('T', ' ') + ':00';
            var path = (routes().correctPunch || '').replace('{log_id}', punch.logId);

            steps.setStep(2); // ②データベースを更新
            restPost(path, { corrected_datetime: correctedDatetime, reason: reason }).then(function (res) {
                var data = res.data || {};
                if (res.status >= 200 && res.status < 300 && data.ok) {
                    steps.setStep(3); // ③画面に反映
                    // 「実労働」列は打刻セルの書き替えだけでは再計算されない（サーバー側の
                    // worked_seconds() を二重実装しないため）。ページを再読み込みして
                    // サーバー側の再計算結果をそのまま反映する（実機で発見：修正後も
                    // 実労働列が古い値のまま残る不具合の修正）。
                    setTimeout(function () {
                        window.location.reload();
                    }, 400);
                    return;
                }
                m.close();
                showToast(data.message || '修正できませんでした。画面を再読み込みしてお試しください。');
            }).catch(function () {
                m.close();
                showToast('通信に失敗しました。もう一度お試しください。');
            });
        });
    }

    /** §4.1「①記録→②更新→③反映」の3ステップ表示。 */
    function buildCorrectionSteps() {
        var el = document.createElement('ol');
        el.className = 'tc-correct-steps';
        var items = ['修正内容を記録', 'データベースを更新', '画面に反映'].map(function (text) {
            var li = document.createElement('li');
            li.textContent = text;
            el.appendChild(li);
            return li;
        });
        return {
            el: el,
            setStep: function (n) {
                items.forEach(function (li, idx) {
                    li.classList.toggle('is-done', idx < n);
                    li.classList.toggle('is-active', idx === n - 1);
                });
            }
        };
    }

    // ── 2h：打刻の追加 ──────────────────────────────────────

    /** 履歴テーブルの「追加」ボタンに接続する。 */
    function setupAddButtons(wrap) {
        var buttons = Array.prototype.slice.call(wrap.querySelectorAll('.tc-add-btn[data-add-date]'));
        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                openAddFormModal(wrap, btn.getAttribute('data-add-date'));
            });
        });
    }

    /** 打刻種別・日時・追加理由を入力して保存する。「修正」と違い対象を選ぶ手順が無い。 */
    function openAddFormModal(wrap, date) {
        var existing = collectDayPunches(wrap, date);
        var existingTypes = existing.map(function (p) { return p.punchType; });

        var m = createModal('打刻を追加（' + date + '）');

        var typeLabel = document.createElement('label');
        typeLabel.className = 'tc-correct-field';
        var typeSpan = document.createElement('span');
        typeSpan.textContent = '打刻の種別';
        typeLabel.appendChild(typeSpan);
        var typeSelect = document.createElement('select');
        ['clock_in', 'break_in', 'break_out', 'clock_out'].forEach(function (type) {
            // 出勤・退勤はその日1件のみのため、既にある場合は選択肢から外す（サーバー側でも重複は弾く）。
            if ((type === 'clock_in' || type === 'clock_out') && existingTypes.indexOf(type) !== -1) {
                return;
            }
            var opt = document.createElement('option');
            opt.value = type;
            opt.textContent = PUNCH_LABELS[type] || type;
            typeSelect.appendChild(opt);
        });
        typeLabel.appendChild(typeSelect);
        m.body.appendChild(typeLabel);

        var dtLabel = document.createElement('label');
        dtLabel.className = 'tc-correct-field';
        var dtSpan = document.createElement('span');
        dtSpan.textContent = '打刻の日時';
        dtLabel.appendChild(dtSpan);
        var dtInput = document.createElement('input');
        dtInput.type = 'datetime-local';
        dtInput.value = date + 'T00:00';
        dtLabel.appendChild(dtInput);
        m.body.appendChild(dtLabel);

        var reasonLabel = document.createElement('label');
        reasonLabel.className = 'tc-correct-field';
        var reasonSpan = document.createElement('span');
        reasonSpan.textContent = '追加理由';
        reasonLabel.appendChild(reasonSpan);
        var reasonInput = document.createElement('textarea');
        reasonInput.rows = 3;
        reasonLabel.appendChild(reasonInput);
        m.body.appendChild(reasonLabel);

        var errorEl = document.createElement('p');
        errorEl.className = 'tc-modal-error';
        errorEl.hidden = true;
        m.body.appendChild(errorEl);

        var actions = document.createElement('div');
        actions.className = 'tc-modal-actions';
        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'tc-modal-btn is-ghost';
        cancelBtn.textContent = 'キャンセル';
        cancelBtn.addEventListener('click', m.close);
        var submitBtn = document.createElement('button');
        submitBtn.type = 'button';
        submitBtn.className = 'tc-modal-btn is-primary';
        submitBtn.textContent = '追加を登録する';
        actions.appendChild(cancelBtn);
        actions.appendChild(submitBtn);
        m.body.appendChild(actions);

        submitBtn.addEventListener('click', function () {
            var reason = reasonInput.value.trim();
            if (!dtInput.value || !reason) {
                errorEl.textContent = '打刻の日時と追加理由は必須です。';
                errorEl.hidden = false;
                return;
            }
            errorEl.hidden = true;
            submitBtn.disabled = true;
            cancelBtn.disabled = true;

            var punchedAt = dtInput.value.replace('T', ' ') + ':00';

            restPost(routes().addPunch, {
                work_date: date,
                punch_type: typeSelect.value,
                punched_at: punchedAt,
                reason: reason
            }).then(function (res) {
                var data = res.data || {};
                if (res.status >= 200 && res.status < 300 && data.ok) {
                    m.close();
                    // 「実労働」列は打刻セルの書き替えだけでは再計算されない（サーバー側の
                    // worked_seconds() を二重実装しないため）。ページを再読み込みして
                    // サーバー側の再計算結果をそのまま反映する。
                    window.location.reload();
                    return;
                }
                m.close();
                showToast(data.message || '追加できませんでした。画面を再読み込みしてお試しください。');
            }).catch(function () {
                m.close();
                showToast('通信に失敗しました。もう一度お試しください。');
            });
        });
    }

    // ── 2d：モーダル共通部品 ────────────────────────────────

    /** 労基法の休憩の目安インフォボックス（3段の一覧＋任意の補足メッセージ）。 */
    function buildLawInfoBox(noteText) {
        var cfg = laborCfg();
        var box = document.createElement('div');
        box.className = 'tc-law-box';

        var list = document.createElement('ul');
        [
            '勤務 ' + cfg.tier1Hours + ' 時間以内 → 休憩の付与義務なし',
            '勤務 ' + cfg.tier1Hours + ' 時間超〜' + cfg.tier2Hours + ' 時間以内 → ' + cfg.tier1Minutes + ' 分以上の休憩が必要',
            '勤務 ' + cfg.tier2Hours + ' 時間超 → ' + cfg.tier2Minutes + ' 分以上の休憩が必要'
        ].forEach(function (text) {
            var li = document.createElement('li');
            li.textContent = text;
            list.appendChild(li);
        });
        box.appendChild(list);

        if (noteText) {
            var note = document.createElement('p');
            note.className = 'tc-law-note';
            note.textContent = noteText;
            box.appendChild(note);
        }
        return box;
    }

    /** 簡素なモーダルの土台。オーバーレイクリック・Escで閉じる。 */
    function createModal(titleText) {
        var overlay = document.createElement('div');
        overlay.className = 'tc-modal-overlay';

        var modal = document.createElement('div');
        modal.className = 'tc-modal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');

        var title = document.createElement('h2');
        title.className = 'tc-modal-title';
        title.textContent = titleText;
        modal.appendChild(title);

        var body = document.createElement('div');
        body.className = 'tc-modal-body';
        modal.appendChild(body);

        overlay.appendChild(modal);
        document.body.appendChild(overlay);

        function onKeydown(e) {
            if (e.key === 'Escape') {
                close();
            }
        }
        function close() {
            if (overlay.parentNode) {
                overlay.parentNode.removeChild(overlay);
            }
            document.removeEventListener('keydown', onKeydown);
        }
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) {
                close();
            }
        });
        document.addEventListener('keydown', onKeydown);

        var focusable = modal.querySelector('button, input');
        if (focusable) {
            focusable.focus();
        }

        return { overlay: overlay, modal: modal, body: body, close: close };
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

    /** epoch秒（serverEpoch と同じ「サーバー時刻」系列）を HH:MM 表示にする。 */
    function formatHm(epochSec) {
        if (!epochSec) {
            return '--:--';
        }
        var d = new Date(epochSec * 1000);
        return pad(d.getHours()) + ':' + pad(d.getMinutes());
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
