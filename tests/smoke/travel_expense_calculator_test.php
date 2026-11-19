<?php
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 2));

// 4a 交通費・車両借り上げの金額計算（04 §2.2・§3.1・§3.2）
require IMS_SMOKE_ROOT . '/src/Module/Travel/ExpenseCalculator.php';
use IMS\Module\Travel\ExpenseCalculator as E;

$f = 0;
function t($n, $a, $e) { global $f; if ($a === $e) { echo "OK  $n\n"; } else { $f++; echo "NG  $n: " . var_export($a, true) . " != " . var_export($e, true) . "\n"; } }

// §2.2 の例（単価27.0円/km・車両借り上げ片道12.4km）
t('切り捨て 12.4km → 324円', E::vehicle_amount(12.4, false, 27.0, 'floor'), 324);
t('四捨五入 12.4km → 324円', E::vehicle_amount(12.4, false, 27.0, 'round'), 324);
t('切り上げ 12.4km → 351円', E::vehicle_amount(12.4, false, 27.0, 'ceil'), 351);
t('距離は先に丸める（13km）', E::vehicle_distance(12.4, false, 'ceil'), 13);
t('往復は2倍してから丸める 6.3km×2', E::vehicle_distance(6.3, true, 'floor'), 12);
t('往復を先に2倍（片道を丸めてから2倍ではない）', E::vehicle_distance(6.3, true, 'ceil'), 13);
t('四捨五入の0.5は切り上げ', E::vehicle_distance(12.5, false, 'round'), 13);

// §3.2 の例：8.5km 往復 × 21円 → 17km → 357円 ／ 片道 → 8km（切り捨て）× 21 = 168円
t('借上げ 往復 8.5km', E::vehicle_amount(8.5, true, 21.0, 'floor'), 357);
t('借上げ 片道 8.5km（切り捨て）', E::vehicle_amount(8.5, false, 21.0, 'floor'), 168);

// 通勤（distance_km は往復距離）
t('通勤 往復 24.8km', E::commute_distance(24.8, 'round_trip', 'floor'), 24);
t('通勤 片道（往復の半分）24.8→12.4', E::commute_distance(24.8, 'one_way', 'floor'), 12);
t('通勤 片道 切り上げ', E::commute_distance(24.8, 'one_way', 'ceil'), 13);
t('通勤 片道 25.0km → 12.5 → 四捨五入13', E::commute_distance(25.0, 'one_way', 'round'), 13);
t('通勤 往復 金額', E::commute_amount(24.8, 'round_trip', 27.0, 'floor'), 648);
t('通勤 片道 金額', E::commute_amount(24.8, 'one_way', 27.0, 'floor'), 324);

// 浮動小数点の誤差が出ない（0.29×100 = 28.999… 型）
t('誤差なし 0.29km × 100円 を切り上げ', E::vehicle_amount(0.29, false, 100.0, 'ceil'), 100);
t('誤差なし 単価 0.29円/km × 100km', E::vehicle_amount(100.0, false, 0.29, 'floor'), 29);
t('円未満は切り捨て（単価27.5×3km）', E::vehicle_amount(3.0, false, 27.5, 'floor'), 82);

// 異常値
t('距離0は0円', E::vehicle_amount(0.0, true, 27.0, 'floor'), 0);
t('単価0は0円', E::commute_amount(10.0, 'round_trip', 0.0, 'floor'), 0);
t('負の距離は0円', E::vehicle_amount(-5.0, false, 27.0, 'floor'), 0);
t('不正な丸めモードは切り捨て', E::normalize_mode('xxx'), 'floor');
t('丸めモード null', E::normalize_mode(null), 'floor');
t('丸めモード ceil', E::normalize_mode('ceil'), 'ceil');

echo $f ? "FAILED $f\n" : "ALL PASS\n"; exit($f ? 1 : 0);
