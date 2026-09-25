/**
 * 社員管理＞打刻ログ照会（02_time_tracking.md §4.2）のJS。
 *
 * ・行クリック相当の「履歴」ボタンで修正履歴行の表示/非表示を切り替える
 *   （履歴自体はPHPが最初から描画済み。ここではhidden属性の切り替えのみ）。
 * ・「修正」ボタンで打刻修正モーダルを開く。既存の打刻修正REST
 *   （POST ims/v1/timecard/logs/{log_id}/correct・2eで追加）をそのまま使う。
 *   権限（hr_admin/administratorは常に直接修正可）はサーバー側
 *   （PunchService::correct_punch）が判定する。
 * ・「取消」ボタンで打刻取り消しモーダルを開く。既存の打刻取り消しREST
 *   （POST ims/v1/timecard/logs/{log_id}/void・2iで追加）をそのまま使う。
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var table = document.querySelector('.ims-tc-log-table');
        if (!table) {
            return;
        }
        setupHistoryToggles(table);
        setupCorrectionButtons(table);
        setupVoidButtons(table);
    });

    function setupHistoryToggles(table) {
        Array.prototype.slice.call(table.querySelectorAll('.ims-tc-history-toggle')).forEach(function (btn) {
            btn.addEventListener('click', function () {
                var logId = btn.getAttribute('data-log-id');
                var row = table.querySelector('.ims-tc-history-row[data-log-id="' + logId + '"]');
                if (row) {
                    row.hidden = !row.hidden;
                }
            });
        });
    }

    function setupCorrectionButtons(table) {
        Array.prototype.slice.call(table.querySelectorAll('.ims-tc-correct-btn')).forEach(function (btn) {
            btn.addEventListener('click', function () {
                openCorrectionModal({
                    logId: btn.getAttribute('data-log-id'),
                    punchType: btn.getAttribute('data-punch-type'),
                    punchedAt: btn.getAttribute('data-punched-at'),
                    employee: btn.getAttribute('data-employee'),
                    workDate: btn.getAttribute('data-work-date')
                });
            });
        });
    }

    function setupVoidButtons(table) {
        Array.prototype.slice.call(table.querySelectorAll('.ims-tc-void-btn')).forEach(function (btn) {
            btn.addEventListener('click', function () {
                openVoidModal({
                    logId: btn.getAttribute('data-log-id'),
                    punchType: btn.getAttribute('data-punch-type'),
                    punchedAt: btn.getAttribute('data-punched-at'),
                    employee: btn.getAttribute('data-employee'),
                    workDate: btn.getAttribute('data-work-date')
                });
            });
        });
    }

    function restPost(path, body) {
        var cfg = window.imsTimecardAdmin || {};
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

    function openCorrectionModal(punch) {
        var overlay = document.createElement('div');
        overlay.className = 'ims-tc-modal-overlay';
        var modal = document.createElement('div');
        modal.className = 'ims-tc-modal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');

        var title = document.createElement('h2');
        title.textContent = punch.employee + '（' + punch.workDate + '）の' + punch.punchType + 'を修正';
        modal.appendChild(title);

        var body = document.createElement('div');
        body.className = 'ims-tc-modal-body';
        modal.appendChild(body);

        var info = document.createElement('p');
        info.className = 'ims-tc-modal-info';
        info.textContent = '現在の打刻時刻：' + timeOnly(punch.punchedAt);
        body.appendChild(info);

        var dtLabel = document.createElement('label');
        dtLabel.className = 'ims-tc-modal-field';
        var dtSpan = document.createElement('span');
        dtSpan.textContent = '修正後の日時';
        dtLabel.appendChild(dtSpan);
        var dtInput = document.createElement('input');
        dtInput.type = 'datetime-local';
        if (punch.punchedAt) {
            dtInput.value = punch.punchedAt.replace(' ', 'T').substring(0, 16);
        }
        dtLabel.appendChild(dtInput);
        body.appendChild(dtLabel);

        var reasonLabel = document.createElement('label');
        reasonLabel.className = 'ims-tc-modal-field';
        var reasonSpan = document.createElement('span');
        reasonSpan.textContent = '修正理由';
        reasonLabel.appendChild(reasonSpan);
        var reasonInput = document.createElement('textarea');
        reasonInput.rows = 3;
        reasonLabel.appendChild(reasonInput);
        body.appendChild(reasonLabel);

        var errorEl = document.createElement('p');
        errorEl.className = 'ims-tc-modal-error';
        errorEl.hidden = true;
        body.appendChild(errorEl);

        var steps = buildSteps();
        steps.el.hidden = true;
        body.appendChild(steps.el);

        var actions = document.createElement('div');
        actions.className = 'ims-tc-modal-actions';
        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'button';
        cancelBtn.textContent = 'キャンセル';
        var submitBtn = document.createElement('button');
        submitBtn.type = 'button';
        submitBtn.className = 'button button-primary';
        submitBtn.textContent = '修正を登録する';
        actions.appendChild(cancelBtn);
        actions.appendChild(submitBtn);
        body.appendChild(actions);

        function close() {
            if (overlay.parentNode) {
                overlay.parentNode.removeChild(overlay);
            }
            document.removeEventListener('keydown', onKeydown);
        }
        function onKeydown(e) {
            if (e.key === 'Escape') {
                close();
            }
        }
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) {
                close();
            }
        });
        document.addEventListener('keydown', onKeydown);
        cancelBtn.addEventListener('click', close);

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
            steps.setStep(1);

            var correctedDatetime = dtInput.value.replace('T', ' ') + ':00';
            var cfg = window.imsTimecardAdmin || {};
            var path = (cfg.correctRoute || '').replace('{log_id}', punch.logId);

            steps.setStep(2);
            restPost(path, { corrected_datetime: correctedDatetime, reason: reason }).then(function (res) {
                var data = res.data || {};
                if (res.status >= 200 && res.status < 300 && data.ok) {
                    steps.setStep(3);
                    setTimeout(function () {
                        close();
                        window.location.reload();
                    }, 500);
                    return;
                }
                errorEl.textContent = data.message || '修正できませんでした。';
                errorEl.hidden = false;
                submitBtn.disabled = false;
                cancelBtn.disabled = false;
                steps.el.hidden = true;
            }).catch(function () {
                errorEl.textContent = '通信に失敗しました。もう一度お試しください。';
                errorEl.hidden = false;
                submitBtn.disabled = false;
                cancelBtn.disabled = false;
                steps.el.hidden = true;
            });
        });

        overlay.appendChild(modal);
        document.body.appendChild(overlay);
        dtInput.focus();
    }

    function openVoidModal(punch) {
        var overlay = document.createElement('div');
        overlay.className = 'ims-tc-modal-overlay';
        var modal = document.createElement('div');
        modal.className = 'ims-tc-modal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');

        var title = document.createElement('h2');
        title.textContent = punch.employee + '（' + punch.workDate + '）の' + punch.punchType + 'を取り消し';
        modal.appendChild(title);

        var body = document.createElement('div');
        body.className = 'ims-tc-modal-body';
        modal.appendChild(body);

        var info = document.createElement('p');
        info.className = 'ims-tc-modal-info';
        info.textContent = '打刻時刻：' + timeOnly(punch.punchedAt) + '（削除ではなく取消済みとして記録されます）';
        body.appendChild(info);

        var reasonLabel = document.createElement('label');
        reasonLabel.className = 'ims-tc-modal-field';
        var reasonSpan = document.createElement('span');
        reasonSpan.textContent = '取り消し理由';
        reasonLabel.appendChild(reasonSpan);
        var reasonInput = document.createElement('textarea');
        reasonInput.rows = 3;
        reasonLabel.appendChild(reasonInput);
        body.appendChild(reasonLabel);

        var errorEl = document.createElement('p');
        errorEl.className = 'ims-tc-modal-error';
        errorEl.hidden = true;
        body.appendChild(errorEl);

        var actions = document.createElement('div');
        actions.className = 'ims-tc-modal-actions';
        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'button';
        cancelBtn.textContent = 'キャンセル';
        var submitBtn = document.createElement('button');
        submitBtn.type = 'button';
        submitBtn.className = 'button button-primary';
        submitBtn.textContent = 'この打刻を取り消す';
        actions.appendChild(cancelBtn);
        actions.appendChild(submitBtn);
        body.appendChild(actions);

        function close() {
            if (overlay.parentNode) {
                overlay.parentNode.removeChild(overlay);
            }
            document.removeEventListener('keydown', onKeydown);
        }
        function onKeydown(e) {
            if (e.key === 'Escape') {
                close();
            }
        }
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) {
                close();
            }
        });
        document.addEventListener('keydown', onKeydown);
        cancelBtn.addEventListener('click', close);

        submitBtn.addEventListener('click', function () {
            var reason = reasonInput.value.trim();
            if (!reason) {
                errorEl.textContent = '取り消し理由は必須です。';
                errorEl.hidden = false;
                return;
            }
            errorEl.hidden = true;
            submitBtn.disabled = true;
            cancelBtn.disabled = true;

            var cfg = window.imsTimecardAdmin || {};
            var path = (cfg.voidRoute || '').replace('{log_id}', punch.logId);

            restPost(path, { reason: reason }).then(function (res) {
                var data = res.data || {};
                if (res.status >= 200 && res.status < 300 && data.ok) {
                    close();
                    window.location.reload();
                    return;
                }
                errorEl.textContent = data.message || '取り消せませんでした。';
                errorEl.hidden = false;
                submitBtn.disabled = false;
                cancelBtn.disabled = false;
            }).catch(function () {
                errorEl.textContent = '通信に失敗しました。もう一度お試しください。';
                errorEl.hidden = false;
                submitBtn.disabled = false;
                cancelBtn.disabled = false;
            });
        });

        overlay.appendChild(modal);
        document.body.appendChild(overlay);
    }

    function buildSteps() {
        var el = document.createElement('ol');
        el.className = 'ims-tc-modal-steps';
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

    /** サーバー文字列 'Y-m-d H:i:s' から HH:MM だけを切り出す（再パースしない）。 */
    function timeOnly(punchedAt) {
        return punchedAt && punchedAt.length >= 16 ? punchedAt.substring(11, 16) : '--:--';
    }
})();
