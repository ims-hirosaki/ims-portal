<?php
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 2));

define('ABSPATH','/');
$ACTIONS=[]; function add_action($h,$cb,$p=10,$a=1){ global $ACTIONS; $ACTIONS[$h][]=$cb; }
function do_action($h,...$args){ global $ACTIONS; foreach($ACTIONS[$h]??[] as $cb) $cb(...$args); }
function __($s,$d=''){ return $s; }
class U { function has_cap($c){ return true; } } function get_userdata($i){ return new U; }
require IMS_SMOKE_ROOT.'/src/Core/TileRegistry.php';
use IMS\Core\TileRegistry as T;
$f=0; function t($n,$a,$e){global $f; if($a===$e){echo "OK  $n\n";}else{$f++;echo "NG  $n: ".var_export($a,true)." != ".var_export($e,true)."\n";}}
t('文字列（従来）', T::normalize_badge('!'), ['!',null]);
t('null', T::normalize_badge(null), [null,null]);
t('空文字', T::normalize_badge(''), [null,null]);
t('配列：グレー', T::normalize_badge(['text'=>'未提出','tone'=>'neutral']), ['未提出','neutral']);
t('配列：金', T::normalize_badge(['text'=>'未提出','tone'=>'warning']), ['未提出','warning']);
t('配列：朱', T::normalize_badge(['text'=>'未提出','tone'=>'danger']), ['未提出','danger']);
t('配列：未知の色は従来色', T::normalize_badge(['text'=>'x','tone'=>'<b>']), ['x',null]);
t('配列：文字なしは非表示', T::normalize_badge(['tone'=>'danger']), [null,null]);
add_action('ims_portal_register_tile', fn($r)=>$r::add(['id'=>'a','label'=>'A','url'=>'/a/','badge'=>fn($u)=>'!']));
add_action('ims_portal_register_tile', fn($r)=>$r::add(['id'=>'b','label'=>'B','url'=>'/b/','priority'=>5,'badge'=>fn($u)=>['text'=>'未提出','tone'=>'warning']]));
$tiles=T::get_tiles_for_user(1);
t('一覧：色付き', [$tiles[0]['resolved_badge'],$tiles[0]['resolved_badge_tone']], ['未提出','warning']);
t('一覧：従来のバッジは色なし', [$tiles[1]['resolved_badge'],$tiles[1]['resolved_badge_tone']], ['!',null]);
echo $f? "FAILED $f\n" : "ALL PASS\n"; exit($f?1:0);
