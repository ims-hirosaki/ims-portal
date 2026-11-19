<?php
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 2));
// 4e 交通費タブの表示データ（04 §4.1）
require IMS_SMOKE_ROOT . '/src/Module/Travel/ExpenseCalculator.php';
require IMS_SMOKE_ROOT . '/src/Module/Travel/TravelTabCalculator.php';
use IMS\Module\Travel\TravelTabCalculator as T;
$f = 0; function t($n, $a, $e) { global $f; if ($a === $e) { echo "OK  $n\n"; } else { $f++; echo "NG  $n: " . var_export($a, true) . ' != ' . var_export($e, true) . "\n"; } }

$biz = [1 => ['id' => 1, 'name' => '本社業務', 'color' => '#1E3A5F'], 2 => ['id' => 2, 'name' => '氷河期事業', 'color' => '#2E7D32']];
$rec = fn($ct, $km, $amt, $bid, $z = 0) => ['commute_type' => $ct, 'distance_km' => $km, 'amount' => $amt, 'business_id' => $bid, 'is_zero_suppressed' => $z];
$commutes = [
  '2026-10-01' => $rec('round_trip', '24.80', 648, 1),
  '2026-10-02' => $rec('one_way', '24.80', 324, 2),
  '2026-10-05' => $rec('round_trip', '24.80', 0, null, 1),   // 有給
  '2026-10-06' => $rec('round_trip', '24.80', 648, null),    // 事業未設定
  '2026-10-07' => $rec('round_trip', '24.80', 0, 1, 1),      // 理由不明の0円
  '2026-10-08' => $rec('round_trip', '24.80', 648, 99),      // マスタに無い事業
];
$flags = ['2026-10-05' => 'paid_leave'];
$rows = T::rows('2026-10-01', '2026-10-09', $commutes, $biz, $flags, 'floor');
t('期間の日数ぶん行がある', count($rows), 9);
t('土日の判定（10/3 土・10/4 日）', [$rows[2]['is_weekend'], $rows[3]['is_weekend'], $rows[0]['is_weekend']], [true, true, false]);
t('曜日', [$rows[0]['weekday'], $rows[2]['weekday']], [4, 6]);
t('レコードのない日', [$rows[2]['record'], $rows[2]['business_unset']], [null, false]);
t('往復の内容と距離', [$rows[0]['content'], $rows[0]['distance'], $rows[0]['amount']], ['通勤（往復）', 24, 648]);
t('片道の内容と距離（往復距離の半分）', [$rows[1]['content'], $rows[1]['distance']], ['通勤（片道）', 12]);
t('事業のひも付け', $rows[1]['business']['name'], '氷河期事業');
t('有給の0円処理ラベル', [$rows[4]['is_zero'], $rows[4]['zero_label']], [true, '※有給（0円処理）']);
t('事業未設定', [$rows[5]['business'], $rows[5]['business_unset']], [null, true]);
t('理由不明の0円', $rows[6]['zero_label'], '※0円処理');
t('マスタに無い事業', $rows[7]['business']['name'], '（削除された事業）');
t('丸め設定が距離に効く（切り上げ）', T::rows('2026-10-01', '2026-10-01', $commutes, $biz, [], 'ceil')[0]['distance'], 25);

$s = T::summary($rows, $biz);
t('月合計', $s['total_amount'], 648 + 324 + 0 + 648 + 0 + 648);
t('件数', $s['total_days'], 6);
t('未設定の日数（10/5 と 10/6）', $s['unset_days'], 2);
t('事業別の並び（マスタ順→削除済み→未設定）', array_keys($s['by_business']), [1, 2, 99, 0]);
t('本社業務：10/1 と 10/7 の2日・648円', [$s['by_business'][1]['days'], $s['by_business'][1]['amount']], [2, 648]);
t('氷河期：1日・324円', [$s['by_business'][2]['days'], $s['by_business'][2]['amount']], [1, 324]);
t('未設定：2日・648円・色なし', [$s['by_business'][0]['days'], $s['by_business'][0]['amount'], $s['by_business'][0]['color']], [2, 648, null]);
t('空の月', T::summary(T::rows('2026-10-01', '2026-10-03', [], $biz, [], 'floor'), $biz), ['by_business' => [], 'total_amount' => 0, 'total_days' => 0, 'unset_days' => 0]);
t('曜日ラベル', [T::weekday_label(0), T::weekday_label(6)], ['日', '土']);
echo $f ? "FAILED $f\n" : "ALL PASS\n"; exit($f ? 1 : 0);
