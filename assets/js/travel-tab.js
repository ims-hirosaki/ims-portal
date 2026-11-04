/**
 * 「交通費」タブ（04 §4.1。4f）の書き込み操作：事業の選択・往復/片道の変更。
 *
 * 保存は Fetch API で REST（Module\Travel\RestController）へ送り、成功したらページを再読み込みして
 * サーバー側の描画結果（金額・色・集計）をそのまま反映する（03 の勤務表グリッドと同じ方式）。
 * imsPortal（restUrl・nonce）は core の Assets が localize 済み。
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    if (typeof imsPortal === 'undefined') {
      return;
    }
    initVehicle();

    var table = document.querySelector('.tv-table');
    if (!table || !document.querySelector('.tv-select')) {
      return;
    }

    table.addEventListener('change', function (e) {
      var select = e.target.closest('.tv-select');
      if (!select || !select.value) {
        return;
      }
      var body = {};
      body[select.dataset.field] = select.dataset.field === 'business_id' ? parseInt(select.value, 10) : select.value;
      save(select, body);
    });
  });

  // ── 車両借り上げ（4h）：区間の追加・削除 ──────────────────

  function initVehicle() {
    var form = document.getElementById('tv-vehicle-form');
    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var data = new FormData(form);
        var body = {
          date: data.get('date'),
          route_from: data.get('route_from'),
          route_to: data.get('route_to'),
          distance_km: data.get('distance_km'),
          is_round_trip: data.get('is_round_trip') ? 1 : 0,
          business_id: parseInt(data.get('business_id'), 10) || 0,
        };
        var button = form.querySelector('.tv-submit');
        button.disabled = true;
        request('POST', 'travel/vehicle', body, function () { button.disabled = false; });
      });
    }

    document.addEventListener('click', function (e) {
      var button = e.target.closest('.tv-delete');
      if (!button) {
        return;
      }
      if (!window.confirm('この借り上げを削除します。よろしいですか？')) {
        return;
      }
      button.disabled = true;
      request('DELETE', 'travel/vehicle/' + button.dataset.id, null, function () { button.disabled = false; });
    });
  }

  // 成功したらページを再読み込み。失敗したらメッセージを出して onFail を呼ぶ。
  function request(method, route, body, onFail) {
    var options = { method: method, headers: { 'X-WP-Nonce': imsPortal.nonce } };
    if (body !== null) {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(body);
    }
    fetch(imsPortal.restUrl + route, options)
      .then(function (response) {
        return response.json().then(function (data) {
          return { ok: response.ok && data && data.ok, data: data };
        });
      })
      .then(function (res) {
        if (res.ok) {
          window.location.reload();
          return;
        }
        window.alert(res.data.message || '保存に失敗しました。');
        onFail();
      })
      .catch(function () {
        window.alert('通信に失敗しました。時間をおいて再度お試しください。');
        onFail();
      });
  }

  function save(select, body) {
    var previous = select.dataset.current;
    select.disabled = true;
    fetch(imsPortal.restUrl + 'travel/commute/' + select.dataset.id, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': imsPortal.nonce },
      body: JSON.stringify(body),
    })
      .then(function (response) {
        return response.json().then(function (data) {
          return { ok: response.ok && data && data.ok, data: data };
        });
      })
      .then(function (res) {
        if (res.ok) {
          window.location.reload();
          return;
        }
        window.alert(res.data.message || '保存に失敗しました。');
        select.value = previous;
        select.disabled = false;
      })
      .catch(function () {
        window.alert('通信に失敗しました。時間をおいて再度お試しください。');
        select.value = previous;
        select.disabled = false;
      });
  }
})();
