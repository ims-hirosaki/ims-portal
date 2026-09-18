/**
 * 月次勤務表グリッド（/portal/attendance/）の書き込み操作（3e-2）。
 *
 * ・勤怠フラグの変更（.ag-flag-select の change）
 * ・事業別時間割当てモーダル（グリッドセルの dblclick）
 * ・月次提出ボタン（3f-4b。#ag-submit-btn）
 *
 * 保存はいずれも Fetch API で REST（Module\Attendance\RestController）へ送るが、
 * 成功後はページを再読み込みしてサーバー側の描画結果（グリッドの色分け等）を
 * そのまま反映する。グリッドの色分けロジックをJS側で二重実装するコストを避けるための
 * 簡略実装（AttendanceGridPage 冒頭コメント参照）。
 *
 * imsAttendanceGrid.isEditable が false（提出済み以降）のときは、勤怠フラグの
 * プルダウンはサーバー側で disabled 済みだが、念のためJS側でもフラグ変更・
 * モーダルを開く操作をブロックする（サーバー側の month_locked チェックが最終防衛）。
 *
 * imsPortal（restUrl・nonce）は core の Assets が、imsAttendanceGrid
 * （事業一覧・フラグ一覧・日別データ・編集可否）は AttendanceGridPage::enqueue() が localize する。
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var table = document.querySelector('.ag-table');
    if (!table || typeof imsPortal === 'undefined' || typeof imsAttendanceGrid === 'undefined') {
      return;
    }

    initFlagSelects(table);
    initModal(table);
    initSubmit();
  });

  // ── 勤怠フラグの変更 ────────────────────────────────────

  function initFlagSelects(table) {
    table.addEventListener('change', function (e) {
      var select = e.target.closest('.ag-flag-select');
      if (!select) {
        return;
      }
      onFlagChange(select);
    });
  }

  function onFlagChange(select) {
    if (!imsAttendanceGrid.isEditable) {
      return;
    }
    var date = select.dataset.date;
    var newFlag = select.value;
    var oldFlag = select.dataset.current;
    if (newFlag === oldFlag) {
      return;
    }

    var oldLabel = imsAttendanceGrid.flags[oldFlag] || oldFlag;
    var newLabel = imsAttendanceGrid.flags[newFlag] || newFlag;
    if (!window.confirm('勤怠フラグを「' + oldLabel + '」から「' + newLabel + '」に変更します。よろしいですか？')) {
      select.value = oldFlag;
      return;
    }

    var body = { flag: newFlag };

    if (newFlag === 'hourly_leave') {
      var input = window.prompt('時間休の時間数を分で入力してください（例：120）', '');
      var minutes = parseInt(input, 10);
      if (!input || isNaN(minutes) || minutes <= 0) {
        window.alert('正しい分数を入力してください。');
        select.value = oldFlag;
        return;
      }
      body.hourly_leave_minutes = minutes;
    }

    postJson(routeFor('flag', date), body)
      .then(function (res) {
        if (res.ok) {
          window.location.reload();
        } else {
          window.alert(res.data.message || '保存に失敗しました。');
          select.value = oldFlag;
        }
      })
      .catch(function () {
        window.alert('通信に失敗しました。時間をおいて再度お試しください。');
        select.value = oldFlag;
      });
  }

  // ── 事業別時間割当てモーダル ──────────────────────────────

  function initModal(table) {
    var modal = document.getElementById('ag-modal');
    if (!modal) {
      return;
    }

    var rowsBody = document.getElementById('ag-modal-rows');
    var errorEl = document.getElementById('ag-modal-error');
    var dateEl = document.getElementById('ag-modal-date');
    var targetEl = document.getElementById('ag-modal-target');
    var addRowBtn = document.getElementById('ag-modal-add-row');
    var saveBtn = document.getElementById('ag-modal-save');

    table.addEventListener('dblclick', function (e) {
      if (!imsAttendanceGrid.isEditable) {
        return;
      }
      var cell = e.target.closest('td[data-date]');
      if (!cell) {
        return;
      }
      openModal(cell.dataset.date);
    });

    modal.addEventListener('click', function (e) {
      if (e.target.closest('[data-ag-close]')) {
        closeModal();
      }
    });

    addRowBtn.addEventListener('click', function () {
      addRow(null);
    });

    rowsBody.addEventListener('input', function (e) {
      if (e.target.classList.contains('ag-row-start') || e.target.classList.contains('ag-row-end')) {
        updateRowDuration(e.target.closest('tr'));
      }
    });

    rowsBody.addEventListener('click', function (e) {
      var removeBtn = e.target.closest('.ag-row-remove');
      if (removeBtn) {
        removeBtn.closest('tr').remove();
      }
    });

    saveBtn.addEventListener('click', function () {
      saveModal(modal.dataset.date);
    });

    function openModal(date) {
      var day = imsAttendanceGrid.days[date];
      if (!day) {
        return;
      }

      modal.dataset.date = date;
      dateEl.textContent = date;
      targetEl.textContent = day.roundedActualLabel
        ? '実労働時間の目標：' + day.roundedActualLabel + '（出勤 ' + (day.clockIn || '—') + ' ／ 退勤 ' + (day.clockOut || '—') + '）'
        : 'この日はまだ出退勤の打刻が完了していないため、割当てを保存できません。';

      rowsBody.innerHTML = '';
      hideError();

      if (day.allocations.length === 0) {
        addRow(null);
      } else {
        day.allocations.forEach(function (a) {
          addRow(a);
        });
      }

      modal.hidden = false;
    }

    function closeModal() {
      modal.hidden = true;
    }

    function addRow(existing) {
      var tr = document.createElement('tr');

      var businessSelect = document.createElement('select');
      businessSelect.className = 'ag-row-business';
      var blankOption = document.createElement('option');
      blankOption.value = '';
      blankOption.textContent = '選択してください';
      businessSelect.appendChild(blankOption);
      imsAttendanceGrid.businesses.forEach(function (b) {
        var opt = document.createElement('option');
        opt.value = String(b.id);
        opt.textContent = b.name;
        if (existing && existing.businessId === b.id) {
          opt.selected = true;
        }
        businessSelect.appendChild(opt);
      });

      var tdBusiness = document.createElement('td');
      tdBusiness.appendChild(businessSelect);

      var tdStart = document.createElement('td');
      var startInput = document.createElement('input');
      startInput.type = 'time';
      startInput.step = '900';
      startInput.className = 'ag-row-start';
      startInput.value = existing ? existing.start : '';
      tdStart.appendChild(startInput);

      var tdEnd = document.createElement('td');
      var endInput = document.createElement('input');
      endInput.type = 'time';
      endInput.step = '900';
      endInput.className = 'ag-row-end';
      endInput.value = existing ? existing.end : '';
      tdEnd.appendChild(endInput);

      var tdDuration = document.createElement('td');
      tdDuration.className = 'ag-row-duration';
      tdDuration.textContent = '—';

      var tdRemove = document.createElement('td');
      var removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'ag-row-remove';
      removeBtn.textContent = '削除';
      tdRemove.appendChild(removeBtn);

      tr.appendChild(tdBusiness);
      tr.appendChild(tdStart);
      tr.appendChild(tdEnd);
      tr.appendChild(tdDuration);
      tr.appendChild(tdRemove);

      rowsBody.appendChild(tr);
      updateRowDuration(tr);
    }

    function updateRowDuration(tr) {
      var start = tr.querySelector('.ag-row-start').value;
      var end = tr.querySelector('.ag-row-end').value;
      var durationEl = tr.querySelector('.ag-row-duration');
      if (!start || !end) {
        durationEl.textContent = '—';
        return;
      }
      var startMin = toMinutes(start);
      var endMin = toMinutes(end);
      if (endMin <= startMin) {
        durationEl.textContent = '—';
        return;
      }
      var diff = endMin - startMin;
      durationEl.textContent = Math.floor(diff / 60) + '時間' + String(diff % 60).padStart(2, '0') + '分';
    }

    function toMinutes(hhmm) {
      var parts = hhmm.split(':');
      return parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
    }

    function saveModal(date) {
      var rows = [];
      rowsBody.querySelectorAll('tr').forEach(function (tr) {
        var businessId = tr.querySelector('.ag-row-business').value;
        var start = tr.querySelector('.ag-row-start').value;
        var end = tr.querySelector('.ag-row-end').value;
        if (!businessId && !start && !end) {
          return; // 完全な空行はスキップ
        }
        rows.push({
          business_id: parseInt(businessId, 10) || 0,
          start_time: start ? start + ':00' : '',
          end_time: end ? end + ':00' : '',
        });
      });

      hideError();

      postJson(routeFor('projectHours', date), { rows: rows })
        .then(function (res) {
          if (res.ok) {
            window.location.reload();
          } else {
            showError(res.data.message || '保存に失敗しました。');
          }
        })
        .catch(function () {
          showError('通信に失敗しました。時間をおいて再度お試しください。');
        });
    }

    function showError(message) {
      errorEl.textContent = message;
      errorEl.hidden = false;
    }

    function hideError() {
      errorEl.hidden = true;
      errorEl.textContent = '';
    }
  }

  // ── 月次提出 ───────────────────────────────────────────

  function initSubmit() {
    var btn = document.getElementById('ag-submit-btn');
    if (!btn) {
      return;
    }
    btn.addEventListener('click', function () {
      if (!window.confirm('この月の勤怠を提出します。提出後は内容を編集できなくなります。よろしいですか？')) {
        return;
      }
      postJson(routeFor('submit', btn.dataset.yearMonth), {})
        .then(function (res) {
          if (res.ok) {
            window.location.reload();
          } else {
            window.alert(res.data.message || '提出に失敗しました。');
          }
        })
        .catch(function () {
          window.alert('通信に失敗しました。時間をおいて再度お試しください。');
        });
    });
  }

  // ── 共通ヘルパー ────────────────────────────────────────

  function routeFor(key, param) {
    return imsAttendanceGrid.routes[key].replace(/\{[^}]+\}/, param);
  }

  function postJson(route, body) {
    return fetch(imsPortal.restUrl + route, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': imsPortal.nonce,
      },
      body: JSON.stringify(body),
    }).then(function (response) {
      return response.json().then(function (data) {
        return { ok: response.ok && data && data.ok, data: data };
      });
    });
  }
})();
