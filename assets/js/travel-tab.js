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
    var table = document.querySelector('.tv-table');
    if (!table) {
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
