<?php
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 2));
define('ABSPATH', '/'); $OPT = [];
function get_option($k, $d = false) { global $OPT; return array_key_exists($k, $OPT) ? $OPT[$k] : $d; }
function update_option($k, $v, $a = null) { global $OPT; $OPT[$k] = $v; return true; }
require IMS_SMOKE_ROOT . '/src/Module/Attendance/WorkStartSettings.php';
use IMS\Module\Attendance\WorkStartSettings as W;
$f = 0; function t($n, $a, $e) { global $f; if ($a === $e) { echo "OK  $n\n"; } else { $f++; echo "NG  $n: " . var_export($a, true) . ' != ' . var_export($e, true) . "\n"; } }
t('未保存は 8:30', W::minutes(), 510);
W::update(540); t('9:00 で保存', W::minutes(), 540);
W::update(5000); t('範囲外は 8:30', W::minutes(), 510);
$OPT[W::OPTION] = 'x'; t('壊れた値は 8:30', W::minutes(), 510);
t('parse 8:30', W::parse('8:30'), 510);
t('parse 08:30', W::parse('08:30'), 510);
t('parse 不正', [W::parse('24:00'), W::parse('8:60'), W::parse('abc'), W::parse('')], [null, null, null, null]);
t('format', [W::format(510), W::format(0), W::format(1439)], ['08:30', '00:00', '23:59']);
echo $f ? "FAILED $f\n" : "ALL PASS\n"; exit($f ? 1 : 0);
