<?php
namespace {
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 3));
}
namespace IMS\Support {
  final class Capabilities { public static function can_manage_masters(): bool { return true; } public static function can_run_closing(): bool { return true; } public static function can_manage_users(): bool { return true; } public static function can_approve(): bool { return true; } }
  final class UserRepository {
    public static $price = [1 => 0.0, 2 => 30.0];
    public static function get_travel_unit_price(int $u): float { return self::$price[$u] ?? 0.0; }
    public static function get_first_approver_id(int $u): ?int { return null; }
  }
}
namespace IMS\Module\Attendance { }
namespace {
// 4h 車両借り上げ（実DB）：追加・削除・ロック・他人の分は触れない・タブの描画
define('ABSPATH','/'); define('ARRAY_A','ARRAY_A');
require __DIR__.'/miniwpdb.php'; $wpdb=new \MiniWpdb;
class WP_Error { public $code; public $msg; function __construct($c,$m,$d=null){$this->code=$c;$this->msg=$m;} function get_error_code(){return $this->code;} function get_error_message(){return $this->msg;} }
function is_wp_error($x){ return $x instanceof \WP_Error; }
$OPT=[]; function get_option($k,$d=false){ global $OPT; return $OPT[$k]??$d; } function update_option($k,$v,$a=null){ global $OPT; $OPT[$k]=$v; }
function current_time($f){ return '2026-10-05 10:00:00'; } function add_filter(...$a){}
function esc_html($s){ return htmlspecialchars((string)$s,ENT_QUOTES); } function esc_attr($s){ return esc_html($s); } function esc_url($s){ return esc_html($s); }
function add_query_arg($a,$u=''){ return $u.'?'.http_build_query($a); } function home_url($p){ return 'https://x'.$p; }
spl_autoload_register(function($c){ if(str_starts_with($c,'IMS\\')){ $p=IMS_SMOKE_ROOT.'/src/'.str_replace('\\','/',substr($c,4)).'.php'; if(is_readable($p)) require $p; } });
use IMS\Module\Travel\VehicleRentalService as S;
use IMS\Module\Travel\VehicleTabView as V;
$f=0; function t($n,$a,$e){global $f; if($a===$e){echo "OK  $n\n";}else{$f++;echo "NG  $n: ".var_export($a,true)." != ".var_export($e,true)."\n";}}
foreach (['travel_expenses','monthly_summary','businesses','gasoline_price_history'] as $t) $wpdb->query("DELETE FROM wp_$t");
$mk=function($n,$c,$s,$active=1) use($wpdb){ $wpdb->insert('wp_businesses',['business_code'=>$n,'business_name'=>$n,'color_code'=>$c,'is_active'=>$active,'sort_order'=>$s]); return (int)$wpdb->get_var('SELECT MAX(business_id) FROM wp_businesses'); };
$b1=$mk('本社業務','#1E3A5F',1); $b2=$mk('氷河期事業','#2E7D32',2); $bx=$mk('停止中','#999999',3,0);
$wpdb->insert('wp_gasoline_price_history',['unit_price'=>'21.00','apply_from'=>'2026-01','affected_count'=>0,'changed_by'=>1,'changed_at'=>'2026-01-01 00:00:00']);
$in=fn($o=[])=>$o+['date'=>'2026-10-03','route_from'=>'自宅','route_to'=>'取引先','distance_km'=>'8.5','is_round_trip'=>1,'business_id'=>$b2];
$code=fn($r)=>is_wp_error($r)?$r->get_error_code():'ok';
$cnt=fn($uid)=>(int)$wpdb->get_var("SELECT COUNT(*) FROM wp_travel_expenses WHERE user_id=$uid AND expense_type='vehicle_rental'");

t('追加できる', $code(S::create(1,$in())), 'ok');
$row=$wpdb->get_row("SELECT * FROM wp_travel_expenses WHERE user_id=1");
t('保存内容（組織共通単価21円・往復17km）', [$row['expense_type'],$row['unit_price'],$row['amount'],$row['distance_km'],$row['is_round_trip'],$row['business_id']], ['vehicle_rental','21.00','357','8.50','1',(string)$b2]);
S::create(2,$in());
t('社員個別の単価を優先', $wpdb->get_var("SELECT unit_price FROM wp_travel_expenses WHERE user_id=2"), '30.00');
t('事業なしは保存できない（§7.1）', [$code(S::create(1,$in(['business_id'=>0]))),$cnt(1)], ['validation',1]);
t('停止中の事業は選べない', $code(S::create(1,$in(['business_id'=>$bx]))), 'validation');
t('距離0は不可', $code(S::create(1,$in(['distance_km'=>'0']))), 'validation');

$id=(int)$wpdb->get_var("SELECT id FROM wp_travel_expenses WHERE user_id=1");
$other=(int)$wpdb->get_var("SELECT id FROM wp_travel_expenses WHERE user_id=2");
t('他人の借り上げは削除できない', [$code(S::delete(1,$other)),$cnt(2)], ['not_found',1]);
$wpdb->insert('wp_travel_expenses',['user_id'=>1,'work_date'=>'2026-10-04','expense_type'=>'commute','commute_type'=>'round_trip','distance_km'=>24.8,'unit_price'=>27,'amount'=>648]);
$cid=(int)$wpdb->get_var("SELECT MAX(id) FROM wp_travel_expenses");
t('通勤レコードは借り上げ削除では消せない', [$code(S::delete(1,$cid)),(int)$wpdb->get_var("SELECT COUNT(*) FROM wp_travel_expenses WHERE id=$cid")], ['not_found',1]);

// タブ描画
$render=function() { ob_start(); V::render(1,'2026-10','2026-10-01','2026-10-31'); return ob_get_clean(); };
S::create(1,$in(['business_id'=>$b1,'distance_km'=>'10','is_round_trip'=>0,'route_to'=>'<b>x</b>']));
$h=$render();
t('追加フォームと削除ボタンがある', [str_contains($h,'id="tv-vehicle-form"'),substr_count($h,'class="tv-delete"')], [true,2]);
t('事業ごとの見出しと小計', [str_contains($h,'本社業務 の小計（1件）'),str_contains($h,'氷河期事業 の小計（1件）'),str_contains($h,'357円'),str_contains($h,'210円')], [true,true,true,true]);
t('月の合計 567円', str_contains($h,'567円'), true);
t('事業のチップ', [str_contains($h,'すべての事業'),preg_match_all('/class="tv-chip[" ]/',$h)], [true,3]);
t('停止中の事業はフォームの選択肢にない', str_contains($h,'停止中'), false);
t('エスケープ', [str_contains($h,'<b>x</b>'),str_contains($h,'&lt;b&gt;x&lt;/b&gt;')], [false,true]);
$_GET['biz']=(string)$b2; $h=$render();
t('絞り込み：氷河期のみ', [str_contains($h,'氷河期事業 の小計'),str_contains($h,'本社業務 の小計')], [true,false]);
unset($_GET['biz']);

// 削除
t('削除できる', [$code(S::delete(1,$id)),$cnt(1)], ['ok',1]);

// 提出済みはロック（追加・削除・フォーム非表示）
$wpdb->insert('wp_monthly_summary',['user_id'=>1,'target_year_month'=>'2026-10','status'=>'submitted']);
$left=(int)$wpdb->get_var("SELECT id FROM wp_travel_expenses WHERE user_id=1 AND expense_type='vehicle_rental'");
t('提出済みは追加できない', [$code(S::create(1,$in())),$cnt(1)], ['month_locked',1]);
t('提出済みは削除できない', [$code(S::delete(1,$left)),$cnt(1)], ['month_locked',1]);
$h=$render();
t('提出済みはフォーム・削除ボタンが出ない', [str_contains($h,'tv-vehicle-form'),str_contains($h,'class="tv-delete"'),str_contains($h,'210円')], [false,false,true]);
$wpdb->query("UPDATE wp_monthly_summary SET status='confirmed' WHERE user_id=1");
t('確定後も削除できない', $code(S::delete(1,$left)), 'month_locked');
$wpdb->query("UPDATE wp_monthly_summary SET status='rejected_by_admin' WHERE user_id=1");
t('差し戻し後は削除できる', $code(S::delete(1,$left)), 'ok');
echo $f?"FAILED $f\n":"ALL PASS\n"; exit($f?1:0);
}
