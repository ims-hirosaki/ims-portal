<?php
namespace {
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 3));
}
namespace IMS\Support {
  final class Capabilities { public static function can_manage_masters(): bool { return true; } public static function can_run_closing(): bool { return true; } public static function can_manage_users(): bool { return true; } public static function can_approve(): bool { return true; } }
  final class UserRepository { public static function get_first_approver_id(int $u): ?int { return null; } }
}
namespace {
// 4f 通勤レコードの編集（実DB）：事業・往復/片道の保存、他人・提出済みの拒否、事業の自動設定
define('ABSPATH','/'); define('ARRAY_A','ARRAY_A');
require __DIR__.'/miniwpdb.php'; $wpdb=new \MiniWpdb;
class WP_Error { public $code; public $msg; function __construct($c,$m,$d=null){$this->code=$c;$this->msg=$m;} function get_error_code(){return $this->code;} function get_error_message(){return $this->msg;} }
function is_wp_error($x){ return $x instanceof \WP_Error; }
$OPT=[]; function get_option($k,$d=false){ global $OPT; return $OPT[$k]??$d; } function update_option($k,$v,$a=null){ global $OPT; $OPT[$k]=$v; }
function current_time($f){ return '2026-10-05 10:00:00'; } function add_filter(...$a){}
spl_autoload_register(function($c){ if(str_starts_with($c,'IMS\\')){ $p=IMS_SMOKE_ROOT.'/src/'.str_replace('\\','/',substr($c,4)).'.php'; if(is_readable($p)) require $p; } });
use IMS\Module\Travel\CommuteEditService as S;
$f=0; function t($n,$a,$e){global $f; if($a===$e){echo "OK  $n\n";}else{$f++;echo "NG  $n: ".var_export($a,true)." != ".var_export($e,true)."\n";}}
foreach (['travel_expenses','monthly_summary','project_hours','businesses'] as $t) $wpdb->query("DELETE FROM wp_$t");
$mk=function($code,$active=1) use($wpdb){ $wpdb->insert('wp_businesses',['business_code'=>$code,'business_name'=>$code,'color_code'=>'#112233','is_active'=>$active,'sort_order'=>1]); return (int)$wpdb->get_var('SELECT MAX(business_id) FROM wp_businesses'); };
$b1=$mk('a'); $b2=$mk('b'); $bx=$mk('x',0);
$exp=function($uid,$date,$z=0) use($wpdb){ $wpdb->insert('wp_travel_expenses',['user_id'=>$uid,'work_date'=>$date,'expense_type'=>'commute','commute_type'=>'round_trip','distance_km'=>24.8,'unit_price'=>27.0,'amount'=>$z?0:648,'is_zero_suppressed'=>$z,'is_auto_generated'=>1]); return (int)$wpdb->get_var('SELECT MAX(id) FROM wp_travel_expenses'); };
$code=fn($r)=>is_wp_error($r)?$r->get_error_code():'ok';
$row=fn($id)=>$wpdb->get_row("SELECT business_id,commute_type,amount FROM wp_travel_expenses WHERE id=$id");

$a=$exp(1,'2026-10-01'); $z=$exp(1,'2026-10-02',1); $o=$exp(2,'2026-10-01');
t('事業を選ぶ', [$code(S::update(1,$a,$b1,null)),$row($a)['business_id']], ['ok',(string)$b1]);
t('片道にする（金額が12km分）', [$code(S::update(1,$a,null,'one_way')),$row($a)['commute_type'],$row($a)['amount']], ['ok','one_way','324']);
t('往復に戻す', [$code(S::update(1,$a,null,'round_trip')),$row($a)['amount']], ['ok','648']);
t('無効な事業は選べない', $code(S::update(1,$a,$bx,null)), 'validation');
t('存在しない事業', $code(S::update(1,$a,99999,null)), 'validation');
t('他人のレコードは見つからない扱い', [$code(S::update(1,$o,$b1,null)),$row($o)['business_id']], ['not_found',null]);
t('存在しないID', $code(S::update(1,99999,$b1,null)), 'not_found');
t('0円の日は片道でも0円', [$code(S::update(1,$z,null,'one_way')),$row($z)['amount']], ['ok','0']);

// 提出済み以降は編集できない
$wpdb->insert('wp_monthly_summary',['user_id'=>1,'target_year_month'=>'2026-10','status'=>'submitted']);
t('提出済みはロック', [$code(S::update(1,$a,$b2,null)),$row($a)['business_id']], ['month_locked',(string)$b1]);
$wpdb->query("UPDATE wp_monthly_summary SET status='confirmed' WHERE user_id=1");
t('確定後もロック', $code(S::update(1,$a,$b2,null)), 'month_locked');
$wpdb->query("DELETE FROM wp_monthly_summary");

// 事業の自動設定：1事業だけの日だけ。設定済み・複数事業の日は触らない
$wpdb->query('DELETE FROM wp_travel_expenses');
$d1=$exp(1,'2026-10-10'); $d2=$exp(1,'2026-10-11'); $d3=$exp(1,'2026-10-12'); $d4=$exp(1,'2026-10-13'); $d5=$exp(1,'2026-10-14');
$wpdb->query("UPDATE wp_travel_expenses SET business_id=$b2 WHERE id=$d4");
$ph=function($d,$b,$m) use($wpdb){ $wpdb->insert('wp_project_hours',['user_id'=>1,'work_date'=>$d,'business_id'=>$b,'start_time'=>'09:00:00','end_time'=>'10:00:00','minutes'=>$m]); };
$ph('2026-10-10',$b1,480);                       // 1事業 → 自動設定
$ph('2026-10-11',$b1,120); $ph('2026-10-11',$b2,360); // 複数 → 自動設定しない（候補のみ）
$ph('2026-10-13',$b1,480);                       // 設定済み → 触らない
$ph('2026-10-14',$bx,480);                       // 無効な事業 → 自動設定しない
S::auto_assign_business(1,'2026-10','2026-10-01','2026-10-31');
t('1事業の日は自動設定', $row($d1)['business_id'], (string)$b1);
t('複数事業の日は設定しない', $row($d2)['business_id'], null);
t('事業別時間なしの日は設定しない', $row($d3)['business_id'], null);
t('設定済みは変えない', $row($d4)['business_id'], (string)$b2);
t('無効な事業は設定しない', $row($d5)['business_id'], null);
t('事業別時間の集計（日→事業→分）', \IMS\Module\Travel\ExpenseRepository::project_minutes_by_date(1,'2026-10-10','2026-10-11'), ['2026-10-10'=>[$b1=>480],'2026-10-11'=>[$b1=>120,$b2=>360]]);
$wpdb->insert('wp_monthly_summary',['user_id'=>1,'target_year_month'=>'2026-10','status'=>'submitted']);
$wpdb->query("UPDATE wp_travel_expenses SET business_id=NULL WHERE id=$d1");
S::auto_assign_business(1,'2026-10','2026-10-01','2026-10-31');
t('提出済みの月は自動設定しない', $row($d1)['business_id'], null);
echo $f?"FAILED $f\n":"ALL PASS\n"; exit($f?1:0);
}
