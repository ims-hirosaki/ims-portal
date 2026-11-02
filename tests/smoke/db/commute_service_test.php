<?php
namespace {
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 3));
}
namespace IMS\Support {
  final class Capabilities { public static function can_manage_masters(): bool { return true; } public static function can_run_closing(): bool { return true; } public static function can_manage_users(): bool { return true; } public static function can_approve(): bool { return true; } }
  final class UserRepository {
    public static $km = [1 => 12.4, 2 => 0.0]; public static $price = [1 => 0.0, 2 => 0.0];
    public static function get_commute_one_way_km(int $u): float { return self::$km[$u] ?? 0.0; }
    public static function get_travel_unit_price(int $u): float { return self::$price[$u] ?? 0.0; }
    public static function get_first_approver_id(int $u): ?int { return null; }
    public static function get_scheduled_work_hours(int $u): float { return 8.0; }
  }
}
namespace {
// 4d 通勤交通費の自動生成（実DB）：作成・べき等・勤怠フラグ連動・提出後ロック・編集を上書きしない
define('ABSPATH','/'); define('ARRAY_A','ARRAY_A');
require __DIR__.'/miniwpdb.php'; $wpdb=new \MiniWpdb;
class WP_Error { public $code; function __construct($c,$m,$d=null){$this->code=$c;} function get_error_code(){return $this->code;} }
$OPT=[]; function get_option($k,$d=false){ global $OPT; return $OPT[$k]??$d; } function update_option($k,$v,$a=null){ global $OPT; $OPT[$k]=$v; }
function current_time($f){ return '2026-10-05 10:00:00'; } function add_filter(...$a){}
spl_autoload_register(function($c){ if(str_starts_with($c,'IMS\\')){ $p=IMS_SMOKE_ROOT.'/src/'.str_replace('\\','/',substr($c,4)).'.php'; if(is_readable($p)) require $p; } });
use IMS\Module\Travel\CommuteService as C;
use IMS\Module\Travel\GasolinePriceService;
$f=0; function t($n,$a,$e){global $f; if($a===$e){echo "OK  $n\n";}else{$f++;echo "NG  $n: ".var_export($a,true)." != ".var_export($e,true)."\n";}}
foreach (['travel_expenses','gasoline_price_history','monthly_summary','daily_attendance','attendance_logs'] as $t) $wpdb->query("DELETE FROM wp_$t");

$punch=function($uid,$date,$type='clock_in') use($wpdb){ $wpdb->insert('wp_attendance_logs',['user_id'=>$uid,'work_date'=>$date,'punch_type'=>$type,'punched_at'=>"$date 09:00:00"]); };
$flag=function($uid,$date,$fl) use($wpdb){ $wpdb->insert('wp_daily_attendance',['user_id'=>$uid,'work_date'=>$date,'attendance_flag'=>$fl,'scheduled_minutes'=>480]); };
$rows=fn($uid,$date)=>$wpdb->get_results("SELECT * FROM wp_travel_expenses WHERE user_id=$uid AND work_date='$date'",ARRAY_A);
$wpdb->insert('wp_gasoline_price_history',['unit_price'=>'27.00','apply_from'=>'2026-01','affected_count'=>0,'changed_by'=>1,'changed_at'=>'2026-01-01 00:00:00']);

// 出勤打刻 → 作成
$punch(1,'2026-10-01');
t('出勤打刻で作成', C::sync_day(1,'2026-10-01'), 'created');
$r=$rows(1,'2026-10-01'); 
t('作成内容', [count($r),$r[0]['distance_km'],$r[0]['unit_price'],$r[0]['amount'],$r[0]['business_id'],$r[0]['is_auto_generated'],$r[0]['commute_type']], [1,'24.80','27.00','648',null,'1','round_trip']);
t('べき等（2回目は変更なし・1件のまま）', [C::sync_day(1,'2026-10-01'),count($rows(1,'2026-10-01'))], ['unchanged',1]);

// 欠勤（打刻なし・フラグなし）→ 作らない
t('欠勤は作らない', [C::sync_day(1,'2026-10-02'),count($rows(1,'2026-10-02'))], ['unchanged',0]);
// 片道距離が未登録の社員は作らない
$punch(2,'2026-10-01'); t('距離未登録は作らない', [C::sync_day(2,'2026-10-01'),count($rows(2,'2026-10-01'))], ['unchanged',0]);

// 有給（打刻なし）→ 0円で作成
$flag(1,'2026-10-03','paid_leave');
t('有給は0円で作成', C::sync_day(1,'2026-10-03'), 'created');
$r=$rows(1,'2026-10-03'); t('有給の内容', [$r[0]['amount'],$r[0]['is_zero_suppressed']], ['0','1']);
// 半日休 → 全額
$punch(1,'2026-10-04'); $flag(1,'2026-10-04','half_day_am'); C::sync_day(1,'2026-10-04');
t('半日休は全額', $rows(1,'2026-10-04')[0]['amount'], '648');
// 既存の通常日が有給になった → 0円に
$flag(1,'2026-10-05','none'); $punch(1,'2026-10-05'); C::sync_day(1,'2026-10-05');
$wpdb->query("UPDATE wp_daily_attendance SET attendance_flag='paid_leave' WHERE user_id=1 AND work_date='2026-10-05'");
t('通常日が有給に → 更新', C::sync_day(1,'2026-10-05'), 'updated');
t('0円になる', [$rows(1,'2026-10-05')[0]['amount'],$rows(1,'2026-10-05')[0]['is_zero_suppressed']], ['0','1']);
// 有給が戻った（打刻あり）→ 計算し直し
$wpdb->query("UPDATE wp_daily_attendance SET attendance_flag='none' WHERE user_id=1 AND work_date='2026-10-05'");
t('有給が戻ると更新', C::sync_day(1,'2026-10-05'), 'updated');
t('金額が戻る', [$rows(1,'2026-10-05')[0]['amount'],$rows(1,'2026-10-05')[0]['is_zero_suppressed']], ['648','0']);

// スタッフの編集（片道・事業）は上書きしない
$wpdb->query("UPDATE wp_travel_expenses SET commute_type='one_way', amount=324, business_id=1 WHERE user_id=1 AND work_date='2026-10-01'");
t('編集済みは触らない', [C::sync_day(1,'2026-10-01'),$rows(1,'2026-10-01')[0]['amount'],$rows(1,'2026-10-01')[0]['business_id']], ['unchanged','324','1']);

// 社員個別の単価を優先
\IMS\Support\UserRepository::$price[1]=30.0; $punch(1,'2026-10-06'); C::sync_day(1,'2026-10-06');
t('個別単価（24km×30）', [$rows(1,'2026-10-06')[0]['unit_price'],$rows(1,'2026-10-06')[0]['amount']], ['30.00','720']);
\IMS\Support\UserRepository::$price[1]=0.0;

// 提出済み以降は何もしない
$wpdb->insert('wp_monthly_summary',['user_id'=>1,'target_year_month'=>'2026-11','status'=>'submitted']);
$punch(1,'2026-11-02');
t('提出済みの月はロック', [C::sync_day(1,'2026-11-02'),count($rows(1,'2026-11-02'))], ['locked',0]);
$wpdb->query("UPDATE wp_monthly_summary SET status='rejected_by_admin' WHERE user_id=1 AND target_year_month='2026-11'");
t('差し戻し後は作成できる', C::sync_day(1,'2026-11-02'), 'created');

// フック入口：出勤以外の打刻では動かない・例外を外に出さない
$punch(1,'2026-10-07','clock_out'); C::on_punched(1,'clock_out','2026-10-07');
t('退勤打刻では作らない', count($rows(1,'2026-10-07')), 0);
$punch(1,'2026-10-07'); C::on_punched(1,'clock_in','2026-10-07');
t('出勤打刻のフックで作成', count($rows(1,'2026-10-07')), 1);
// 期間まとめて同期（運用開始前の打刻の補完）
foreach (['travel_expenses','daily_attendance','attendance_logs'] as $t) $wpdb->query("DELETE FROM wp_$t");
$punch(1,'2026-12-01'); $punch(1,'2026-12-03'); $punch(1,'2026-12-20'); $flag(1,'2026-12-02','paid_leave'); $flag(1,'2026-12-04','half_day_am');
$wpdb->insert('wp_attendance_logs',['user_id'=>1,'work_date'=>'2026-12-05','punch_type'=>'clock_in','punched_at'=>'2026-12-05 09:00:00','voided_at'=>'2026-12-05 10:00:00']);
t('候補日（打刻のある日＋0円フラグの日。範囲外・取り消し済み・半日休のみは除く）', \IMS\Module\Travel\ExpenseRepository::candidate_dates(1,'2026-12-01','2026-12-15'), ['2026-12-01','2026-12-02','2026-12-03']);
C::sync_period(1,'2026-12-01','2026-12-15');
$in=\IMS\Module\Travel\ExpenseRepository::commutes_in_range(1,'2026-12-01','2026-12-31');
t('期間内の3日ぶんだけ作られる', array_keys($in), ['2026-12-01','2026-12-02','2026-12-03']);
t('有給は0円', [$in['2026-12-02']['amount'],$in['2026-12-02']['is_zero_suppressed']], ['0','1']);
C::sync_period(1,'2026-12-01','2026-12-15');
t('もう一度呼んでも増えない', count(\IMS\Module\Travel\ExpenseRepository::commutes_in_range(1,'2026-12-01','2026-12-31')), 3);
t('他の社員のぶんは見えない', \IMS\Module\Travel\ExpenseRepository::commutes_in_range(2,'2026-12-01','2026-12-31'), []);
echo $f?"FAILED $f\n":"ALL PASS\n"; exit($f?1:0);
}
