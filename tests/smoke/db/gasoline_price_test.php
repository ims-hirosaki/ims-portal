<?php
namespace {
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 3));
}
namespace IMS\Support { final class Capabilities { public static $ok=true; public static function can_manage_masters(): bool { return self::$ok; } } }
namespace {
// 4c ガソリン単価の一括再計算（実DB）：確定月は不変・履歴の追記・失敗時は全体ロールバック
define('ABSPATH','/'); define('ARRAY_A','ARRAY_A');
require __DIR__.'/miniwpdb.php'; $wpdb=new \MiniWpdb;
class WP_Error { public $code; public $msg; function __construct($c,$m,$d=null){$this->code=$c;$this->msg=$m;} function get_error_code(){return $this->code;} function get_error_message(){return $this->msg;} }
$OPT=['ims_salary_cycle_settings'=>[]]; function get_option($k,$d=false){ global $OPT; return $OPT[$k]??$d; } function update_option($k,$v,$a=null){ global $OPT; $OPT[$k]=$v; }
function current_time($f){ return '2026-10-05 10:00:00'; } function add_filter(...$a){}
foreach (['Schema','PayPeriodCalculator','SalaryCycleSettings'] as $c) require IMS_SMOKE_ROOT."/src/Module/Attendance/$c.php";
foreach (['ExpenseCalculator','CalcSettings','Schema','GasolinePriceCalculator','GasolinePriceService'] as $c) require IMS_SMOKE_ROOT."/src/Module/Travel/$c.php";
use IMS\Module\Travel\GasolinePriceService as S;
$f=0; function t($n,$a,$e){global $f; if($a===$e){echo "OK  $n\n";}else{$f++;echo "NG  $n: ".var_export($a,true)." != ".var_export($e,true)."\n";}}
$code=fn($r)=>$r instanceof \WP_Error?$r->get_error_code():'ok';
foreach (['travel_expenses','gasoline_price_history','monthly_summary'] as $t) $wpdb->query("DELETE FROM wp_$t");
$closing = \IMS\Module\Attendance\SalaryCycleSettings::closing_day();
echo "# 締め日設定: $closing\n";

$exp=function($uid,$date,$type='commute',$extra=[]) use($wpdb){ $wpdb->insert('wp_travel_expenses',array_merge(['user_id'=>$uid,'work_date'=>$date,'expense_type'=>$type,'commute_type'=>$type==='commute'?'round_trip':null,'distance_km'=>24.8,'unit_price'=>27.0,'amount'=>669,'business_id'=>1],$extra)); return (int)$wpdb->get_var('SELECT MAX(id) FROM wp_travel_expenses'); };
$a=$exp(1,'2026-09-10'); $b=$exp(1,'2026-10-10'); $c=$exp(2,'2026-10-10'); $d=$exp(2,'2026-11-10');
$e=$exp(1,'2026-10-11','commute',['is_zero_suppressed'=>1,'amount'=>0]);
$wpdb->insert('wp_monthly_summary',['user_id'=>2,'target_year_month'=>'2026-10','status'=>'confirmed']);
$row=fn($id)=>$wpdb->get_row("SELECT unit_price,amount FROM wp_travel_expenses WHERE id=$id");

// 入力・権限
\IMS\Support\Capabilities::$ok=false;
t('権限なし', $code(S::apply(1,'30','2026-10')), 'forbidden');
\IMS\Support\Capabilities::$ok=true;
t('単価不正', $code(S::apply(1,'abc','2026-10')), 'invalid_price');
t('月不正', $code(S::apply(1,'30','2026-1')), 'invalid_apply_from');
t('失敗した操作は履歴を残さない', (int)$wpdb->get_var('SELECT COUNT(*) FROM wp_gasoline_price_history'), 0);
t('単価未設定', S::current_price(), null);

// 確認（DBは変えない）
$p=S::preview('30','2026-10');
t('確認：件数（b,d,e）', count($p['updates']), 3);
t('確認：確定済みで飛ばす件数（c）', $p['skipped_confirmed'], 1);
t('確認：DBは変わらない', [$row($b)['amount'],(int)$wpdb->get_var('SELECT COUNT(*) FROM wp_gasoline_price_history')], ['669',0]);

// 実行
$r=S::apply(7,'30','2026-10');
t('実行成功', $code($r), 'ok');
t('適用開始月より前は不変', [$row($a)['unit_price'],$row($a)['amount']], ['27.00','669']);
t('対象は更新（24km×30）', [$row($b)['unit_price'],$row($b)['amount']], ['30.00','720']);
t('確定済みの月は不変', [$row($c)['unit_price'],$row($c)['amount']], ['27.00','669']);
t('確定していない別の月は更新', $row($d)['amount'], '720');
t('0円処理は0円のまま（単価は更新）', [$row($e)['unit_price'],$row($e)['amount']], ['30.00','0']);
$h=S::history();
t('履歴1件', count($h), 1);
t('履歴の中身', [$h[0]['unit_price'],$h[0]['apply_from'],$h[0]['affected_count'],$h[0]['changed_by'],$h[0]['prev_unit_price']], ['30.00','2026-10','3','7',null]);
t('現在の単価', S::current_price(), '30.00');

// 2回目：変更前単価が履歴に出る
S::apply(8,'28.5','2026-11');
$h=S::history();
t('履歴2件・新しい順', [count($h),$h[0]['unit_price'],$h[0]['prev_unit_price']], [2,'28.50','30.00']);
t('11月分だけ更新', [$row($b)['amount'],$row($d)['amount']], ['720','684']); // 24km×28.5=684

// 失敗時は全体ロールバック（履歴テーブルを一時的に壊す）
$before=[$row($b)['amount'],$row($d)['amount']];
$wpdb->query('DROP TABLE IF EXISTS wp_gasoline_price_history_x');
$wpdb->query('RENAME TABLE wp_gasoline_price_history TO wp_gasoline_price_history_x');
$wpdb->query('CREATE TABLE wp_gasoline_price_history (id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, unit_price decimal(6,2) NOT NULL)');
mysqli_report(MYSQLI_REPORT_OFF); // 本物の \$wpdb と同じく、SQLエラーを例外にせず false で返す
$res=S::apply(1,'99','2026-10');
t('履歴の追記に失敗したら保存失敗', $code($res), 'save_failed');
t('明細も取り消される', [$row($b)['amount'],$row($d)['amount']], $before);
$wpdb->query('DROP TABLE wp_gasoline_price_history'); $wpdb->query('RENAME TABLE wp_gasoline_price_history_x TO wp_gasoline_price_history');
echo $f?"FAILED $f\n":"ALL PASS\n"; exit($f?1:0);
}
