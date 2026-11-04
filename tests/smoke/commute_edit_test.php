<?php
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 2));
// 4f 事業のサジェストと通勤レコードの編集の入力チェック（04 §3.1・§3.3①）
require IMS_SMOKE_ROOT . '/src/Module/Travel/ExpenseCalculator.php';
require IMS_SMOKE_ROOT . '/src/Module/Travel/BusinessSuggester.php';
require IMS_SMOKE_ROOT . '/src/Module/Travel/CommuteEditCalculator.php';
use IMS\Module\Travel\BusinessSuggester as S;
use IMS\Module\Travel\CommuteEditCalculator as E;
$f = 0; function t($n, $a, $e) { global $f; if ($a === $e) { echo "OK  $n\n"; } else { $f++; echo "NG  $n: " . var_export($a, true) . ' != ' . var_export($e, true) . "\n"; } }

t('登録なし', S::suggest([]), ['business_id' => null, 'basis' => 'none']);
t('0分だけは登録なし扱い', S::suggest([1 => 0]), ['business_id' => null, 'basis' => 'none']);
t('1事業だけ → single', S::suggest([2 => 480]), ['business_id' => 2, 'basis' => 'single']);
t('複数で最多が1つ → top', S::suggest([1 => 120, 2 => 360]), ['business_id' => 2, 'basis' => 'top']);
t('複数で最多が並ぶ → tie', S::suggest([1 => 240, 2 => 240]), ['business_id' => null, 'basis' => 'tie']);
t('3事業で上位が並ぶ → tie', S::suggest([1 => 240, 2 => 240, 3 => 10]), ['business_id' => null, 'basis' => 'tie']);
t('0分の事業は数えない', S::suggest([1 => 0, 2 => 300]), ['business_id' => 2, 'basis' => 'single']);

$rec = ['distance_km' => '24.80', 'unit_price' => '27.00', 'is_zero_suppressed' => 0];
$active = [1 => true, 2 => true];
t('事業だけ変更', E::build_update($rec, 2, null, $active, 'floor'), ['update' => ['business_id' => 2]]);
t('片道に変更すると金額を再計算（12km×27）', E::build_update($rec, null, 'one_way', $active, 'floor'), ['update' => ['commute_type' => 'one_way', 'amount' => 324]]);
t('往復に戻す（24km×27）', E::build_update($rec, null, 'round_trip', $active, 'floor'), ['update' => ['commute_type' => 'round_trip', 'amount' => 648]]);
t('両方変更', E::build_update($rec, 1, 'one_way', $active, 'ceil'), ['update' => ['business_id' => 1, 'commute_type' => 'one_way', 'amount' => 351]]);
t('0円処理の日は片道にしても0円', E::build_update(['is_zero_suppressed' => 1] + $rec, null, 'one_way', $active, 'floor'), ['update' => ['commute_type' => 'one_way', 'amount' => 0]]);
t('何も指定しない', E::build_update($rec, null, null, $active, 'floor')['error'][0], 'validation');
t('無効な事業は選べない', E::build_update($rec, 9, null, $active, 'floor')['error'][0], 'validation');
t('往復・片道の不正値', E::build_update($rec, null, 'xx', $active, 'floor')['error'][0], 'validation');
echo $f ? "FAILED $f\n" : "ALL PASS\n"; exit($f ? 1 : 0);
