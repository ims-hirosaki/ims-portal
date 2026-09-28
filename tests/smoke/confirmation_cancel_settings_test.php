<?php
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 2));

define('ABSPATH','/'); $OPT=[];
function get_option($k,$d=false){ global $OPT; return array_key_exists($k,$OPT)?$OPT[$k]:$d; } function update_option($k,$v,$a=null){ global $OPT; $OPT[$k]=$v; return true; }
require IMS_SMOKE_ROOT.'/src/Module/Attendance/ConfirmationCancelSettings.php';
use IMS\Module\Attendance\ConfirmationCancelSettings as C;
$f=0; function t($n,$a,$e){global $f; if($a===$e){echo "OK  $n\n";}else{$f++;echo "NG  $n: ".var_export($a,true)." != ".var_export($e,true)."\n";}}
t('未保存は許可（テスト運用の既定）', C::is_allowed(), true);
C::update(false); t('オフで保存', C::is_allowed(), false);
C::update(true); t('オンで保存', C::is_allowed(), true);
$OPT[C::OPTION]='壊れた値'; t('壊れた値は既定（許可）', C::is_allowed(), true);
echo $f? "FAILED $f\n" : "ALL PASS\n"; exit($f?1:0);
