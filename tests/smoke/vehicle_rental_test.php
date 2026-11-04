<?php
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 2));
// 4h 車両借り上げの入力チェック・金額・タブの表示データ（04 §3.2・§4.1・§7.1）
require IMS_SMOKE_ROOT . '/src/Module/Travel/ExpenseCalculator.php';
require IMS_SMOKE_ROOT . '/src/Module/Travel/VehicleRentalCalculator.php';
require IMS_SMOKE_ROOT . '/src/Module/Travel/VehicleTabCalculator.php';
use IMS\Module\Travel\VehicleRentalCalculator as V;
use IMS\Module\Travel\VehicleTabCalculator as T;
$f = 0; function t($n, $a, $e) { global $f; if ($a === $e) { echo "OK  $n\n"; } else { $f++; echo "NG  $n: " . var_export($a, true) . ' != ' . var_export($e, true) . "\n"; } }

$ok = ['date' => '2026-10-03', 'route_from' => '自宅', 'route_to' => '有限会社安田保険_弘前', 'distance_km' => '8.5', 'is_round_trip' => 1, 'business_id' => 2];
$active = [1 => true, 2 => true];
$r = V::build_row(5, $ok, $active, '21.00', 'floor');
t('往復 8.5km×21円（17km → 357円）', [$r['row']['amount'], $r['row']['distance_km'], $r['row']['is_round_trip']], [357, '8.50', 1]);
t('保存内容', [$r['row']['expense_type'], $r['row']['business_id'], $r['row']['commute_type'], $r['row']['is_auto_generated'], $r['row']['user_id']], ['vehicle_rental', 2, null, 0, 5]);
t('片道（8km → 168円）', V::build_row(5, ['is_round_trip' => 0] + $ok, $active, '21.00', 'floor')['row']['amount'], 168);
t('チェックなしの扱い', V::build_row(5, array_diff_key($ok, ['is_round_trip' => 1]), $active, '21.00', 'floor')['row']['is_round_trip'], 0);
t('丸め設定（切り上げ・片道9km）', V::build_row(5, ['is_round_trip' => 0] + $ok, $active, '21.00', 'ceil')['row']['amount'], 189);
t('全角の距離', V::build_row(5, ['distance_km' => '１２'] + $ok, $active, '21.00', 'floor')['row']['distance_km'], '12.00');
t('前後の空白を除く', V::build_row(5, ['route_from' => '  自宅  '] + $ok, $active, '21.00', 'floor')['row']['route_from'], '自宅');
$err = fn(array $over) => V::build_row(5, $over + $ok, $active, '21.00', 'floor')['error'][0] ?? 'ok';
t('日付が不正', [$err(['date' => '2026-02-30']), $err(['date' => 'abc']), $err(['date' => ''])], ['validation', 'validation', 'validation']);
t('出発地・目的地が空', [$err(['route_from' => ' ']), $err(['route_to' => ''])], ['validation', 'validation']);
t('200文字超', $err(['route_to' => str_repeat('あ', 201)]), 'validation');
t('距離が0・負・文字・上限超', [$err(['distance_km' => '0']), $err(['distance_km' => '-1']), $err(['distance_km' => 'abc']), $err(['distance_km' => '100000'])], ['validation', 'validation', 'validation', 'validation']);
t('事業が未選択・無効（事業は必須）', [$err(['business_id' => 0]), $err(['business_id' => 9]), $err(['business_id' => null])], ['validation', 'validation', 'validation']);
t('単価0なら0円', V::build_row(5, $ok, $active, '0.00', 'floor')['row']['amount'], 0);

$biz = [1 => ['id' => 1, 'name' => '本社業務', 'color' => '#1E3A5F'], 2 => ['id' => 2, 'name' => '氷河期事業', 'color' => '#2E7D32']];
$rec = fn($id, $date, $bid, $km, $rt, $amt) => ['id' => $id, 'work_date' => $date, 'business_id' => $bid, 'route_from' => 'A', 'route_to' => 'B', 'is_round_trip' => $rt, 'distance_km' => $km, 'amount' => $amt];
$recs = [$rec(3, '2026-10-05', 2, '8.50', 1, 357), $rec(1, '2026-10-02', 1, '10.00', 0, 210), $rec(2, '2026-10-02', 2, '8.50', 0, 168), $rec(4, '2026-10-01', 99, '5.00', 0, 105)];
$g = T::groups($recs, $biz, 'floor');
t('グループは事業マスタ順・マスタに無い事業は最後', array_map(fn($x) => $x['business']['id'], $g['groups']), [1, 2, 99]);
t('グループ内は日付・ID順', array_column($g['groups'][1]['rows'], 'id'), [2, 3]);
t('事業別の小計', array_column($g['groups'], 'subtotal'), [210, 525, 105]);
t('合計と件数', [$g['total_amount'], $g['total_count']], [840, 4]);
t('距離（往復は2倍して丸め）', [$g['groups'][1]['rows'][1]['distance'], $g['groups'][1]['rows'][0]['distance']], [17, 8]);
t('削除された事業の表示名', $g['groups'][2]['business']['name'], '（削除された事業）');
$o = T::groups($recs, $biz, 'floor', 2);
t('事業で絞り込み', [count($o['groups']), $o['total_amount'], $o['total_count']], [1, 525, 2]);
t('空', T::groups([], $biz, 'floor'), ['groups' => [], 'total_amount' => 0, 'total_count' => 0]);
echo $f ? "FAILED $f\n" : "ALL PASS\n"; exit($f ? 1 : 0);
