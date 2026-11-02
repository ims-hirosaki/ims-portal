<?php
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 2));
define('ABSPATH', '/'); $OPT = [];
function get_option($k, $d = false) { global $OPT; return array_key_exists($k, $OPT) ? $OPT[$k] : $d; }
function update_option($k, $v, $a = null) { global $OPT; $OPT[$k] = $v; return true; }
require IMS_SMOKE_ROOT . '/src/Module/Travel/ExpenseCalculator.php';
require IMS_SMOKE_ROOT . '/src/Module/Travel/CalcSettings.php';
use IMS\Module\Travel\CalcSettings as C;
$f = 0; function t($n, $a, $e) { global $f; if ($a === $e) { echo "OK  $n\n"; } else { $f++; echo "NG  $n: " . var_export($a, true) . ' != ' . var_export($e, true) . "\n"; } }
t('未保存は切り捨て', C::rounding_mode(), 'floor');
C::update_rounding_mode('ceil'); t('切り上げで保存', C::rounding_mode(), 'ceil');
C::update_rounding_mode('round'); t('四捨五入で保存', C::rounding_mode(), 'round');
C::update_rounding_mode('bogus'); t('不正値は切り捨てで保存', C::rounding_mode(), 'floor');
$OPT[C::OPTION] = 'x'; t('壊れた値は切り捨て', C::rounding_mode(), 'floor');
$OPT[C::OPTION] = ['rounding_mode' => 'zzz']; t('壊れたモードは切り捨て', C::rounding_mode(), 'floor');
echo $f ? "FAILED $f\n" : "ALL PASS\n"; exit($f ? 1 : 0);
