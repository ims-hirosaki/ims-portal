<?php
namespace {
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 3));
}
namespace IMS\Support {
  final class Capabilities { public static function can_manage_masters(): bool { return true; } public static function can_run_closing(): bool { return true; } public static function can_manage_users(): bool { return false; } public static function can_approve(): bool { return true; } }
  final class UserRepository {
    public static function get_commute_one_way_km(int $u): float { return 12.4; } public static function get_travel_unit_price(int $u): float { return 27.0; }
    public static function get_first_approver_id(int $u): ?int { return null; } public static function get_scheduled_work_hours(int $u): float { return 8.0; }
  }
}
namespace IMS\Module\Attendance {
  final class AttendanceGridService { public static function month_data(int $u, string $ym): array { return ['days' => []]; } }
}
namespace {
// 4g 月次提出時の交通費チェック（実DB）。03の提出処理（MonthlySummaryService::submit）まで通して確認する
define('ABSPATH','/'); define('ARRAY_A','ARRAY_A');
require __DIR__.'/miniwpdb.php'; $wpdb=new \MiniWpdb;
class WP_Error { public $code; public $msg; public $data; function __construct($c,$m,$d=null){$this->code=$c;$this->msg=$m;$this->data=$d;} function get_error_code(){return $this->code;} function get_error_message(){return $this->msg;} }
function is_wp_error($x){ return $x instanceof \WP_Error; }
$OPT=[]; function get_option($k,$d=false){ global $OPT; return $OPT[$k]??$d; } function update_option($k,$v,$a=null){ global $OPT; $OPT[$k]=$v; }
function current_time($f){ return '2026-10-05 10:00:00'; }
$FILTERS=[]; function add_filter($n,$cb,$p=10,$a=1){ global $FILTERS; $FILTERS[$n][]=[$cb,$a]; }
function apply_filters($n,$v,...$args){ global $FILTERS; foreach($FILTERS[$n]??[] as [$cb,$a]) $v=$cb($v,...array_slice($args,0,$a-1)); return $v; }
spl_autoload_register(function($c){ if(str_starts_with($c,'IMS\\')){ $p=IMS_SMOKE_ROOT.'/src/'.str_replace('\\','/',substr($c,4)).'.php'; if(is_readable($p)) require $p; } });
use IMS\Module\Travel\SubmitCheck as C;
use IMS\Module\Attendance\MonthlySummaryService as M;
$f=0; function t($n,$a,$e){global $f; if($a===$e){echo "OK  $n\n";}else{$f++;echo "NG  $n: ".var_export($a,true)." != ".var_export($e,true)."\n";}}

// 純粋部分
t('未設定の日（昇順）', C::unset_dates(['2026-10-03'=>['business_id'=>null,'is_zero_suppressed'=>0],'2026-10-01'=>['business_id'=>null,'is_zero_suppressed'=>0]]), ['2026-10-01','2026-10-03']);
t('設定済みは対象外', C::unset_dates(['2026-10-01'=>['business_id'=>'2','is_zero_suppressed'=>0]]), []);
t('0円処理の日は対象外', C::unset_dates(['2026-10-01'=>['business_id'=>null,'is_zero_suppressed'=>1]]), []);
t('レコードなし', C::unset_dates([]), []);

foreach (['travel_expenses','monthly_summary','project_hours','businesses','daily_attendance','attendance_logs'] as $t) $wpdb->query("DELETE FROM wp_$t");
$wpdb->insert('wp_businesses',['business_code'=>'a','business_name'=>'a','color_code'=>'#112233','is_active'=>1,'sort_order'=>1]);
$b1=(int)$wpdb->get_var('SELECT MAX(business_id) FROM wp_businesses');
$punch=function($uid,$date) use($wpdb){ $wpdb->insert('wp_attendance_logs',['user_id'=>$uid,'work_date'=>$date,'punch_type'=>'clock_in','punched_at'=>"$date 09:00:00"]); };
$n=fn($uid)=>(int)$wpdb->get_var("SELECT COUNT(*) FROM wp_travel_expenses WHERE user_id=$uid");

add_filter('ims_monthly_summary_submit_check',[C::class,'filter'],10,3);
$code=fn($r)=>is_wp_error($r)?$r->get_error_code():'ok';

// 画面を開いていなくても、打刻した日の通勤費が作られ、事業未設定なので提出できない
$punch(1,'2026-10-01'); $punch(1,'2026-10-02'); $punch(1,'2026-10-03');
t('事業未設定があると提出できない', $code(M::submit(1,1,'2026-10')), 'unset_travel_business');
t('チェックの前に通勤費が作られる', $n(1), 3);
t('提出されていない', M::current_status(1,'2026-10'), 'draft');
$e=M::submit(1,1,'2026-10');
t('メッセージに日数と日付', [str_contains($e->get_error_message(),'3日'),str_contains($e->get_error_message(),'2026-10-02')], [true,true]);

// 1事業だけの日は自動設定される。残りを手で設定すると提出できる
$wpdb->insert('wp_project_hours',['user_id'=>1,'work_date'=>'2026-10-01','business_id'=>$b1,'start_time'=>'09:00:00','end_time'=>'17:00:00','minutes'=>480]);
$e=M::submit(1,1,'2026-10');
t('自動設定されて残りは2日', [$code($e),str_contains($e->get_error_message(),'2日')], ['unset_travel_business',true]);
$wpdb->query("UPDATE wp_travel_expenses SET business_id=$b1 WHERE user_id=1");
t('すべて設定済みなら提出できる', $code(M::submit(1,1,'2026-10')), 'ok');
t('提出済みになる', M::current_status(1,'2026-10'), 'submitted');

// 提出後は通勤費が増えない（ロック）
$punch(1,'2026-10-04'); C::filter(null,1,'2026-10');
t('提出済みの月は通勤費を作り足さない', $n(1), 3);

// 他人の未設定は関係しない・0円処理だけなら提出できる
$wpdb->insert('wp_daily_attendance',['user_id'=>3,'work_date'=>'2026-10-05','attendance_flag'=>'paid_leave','scheduled_minutes'=>480]);
t('有給（0円処理）だけなら提出できる', $code(M::submit(3,3,'2026-10')), 'ok');
t('他人の事業未設定の影響を受けない', $code(M::submit(4,4,'2026-10')), 'ok');

// 他のチェックのエラーを優先する
t('先に別のエラーがあればそれを返す', C::filter(new \WP_Error('x','y'),1,'2026-10')->get_error_code(), 'x');
echo $f?"FAILED $f\n":"ALL PASS\n"; exit($f?1:0);
}
