<?php
namespace {
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 2));
}

namespace IMS\Support { final class UserRepository { public static function is_retired(int $u): bool { return false; } } }
namespace IMS\Module\Attendance {
  final class SalaryCycleSettings { public static function period_for_year_month(string $ym): array { return PayPeriodCalculator::period_for('eom',$ym); }
    public static function year_month_for_date(string $d): string { return PayPeriodCalculator::year_month_for_date('eom',$d); } }
  final class DailyAttendanceRepository { public static function has_any_in_range(int $u,string $s,string $e): bool { return $s <= '2026-09-10' && '2026-09-10' <= $e; } }
  final class MonthlySummaryService { public static function current_status(int $u,string $ym): string { return 'draft'; } }
}
namespace {
define('ABSPATH','/'); function current_time($f){ return '2026-10-02'; } function home_url($p=''){ return 'https://ex.jp/wp'.$p; } function admin_url($p=''){ return 'https://ex.jp/wp/wp-admin/'.$p; } function untrailingslashit($s){ return rtrim($s,'/'); }
foreach (['PayPeriodCalculator','MonthlySummaryCalculator','SubmissionReminderCalculator','AttendancePrintPage','AdminAttendanceGridPage','AdminMonthlySubmissionsPage','AdminBusinessesPage','AdminMenu'] as $c) require IMS_SMOKE_ROOT."/src/Module/Attendance/$c.php";
$ACTIONS=[]; function add_action($h,$cb,$p=10,$a=1){ global $ACTIONS; $ACTIONS[$h][]=$cb; }
function do_action($h,...$args){ global $ACTIONS; foreach($ACTIONS[$h]??[] as $cb) $cb(...$args); }
function __($s,$d=''){ return $s; }
if(!class_exists('U')){ class U { public $caps; function __construct($c){$this->caps=$c;} function has_cap($c){ return in_array($c,$this->caps,true); } } }
$USER=new U(['ims_use_portal']); function get_userdata($i){ global $USER; return $USER; }
require IMS_SMOKE_ROOT.'/src/Core/TileRegistry.php';
require IMS_SMOKE_ROOT.'/src/Module/Attendance/DashboardIntegration.php';
IMS\Module\Attendance\DashboardIntegration::init();
add_action('ims_portal_register_tile', fn($r)=>$r::add(['id'=>'profile','label'=>'マイページ','url'=>'/portal/profile/','priority'=>90]));
add_action('ims_portal_register_tile', fn($r)=>$r::add(['id'=>'timecard','label'=>'打刻','url'=>'/portal/timecard/','priority'=>10]));
$f=0; function t($n,$a,$e){global $f; if($a===$e){echo "OK  $n\n";}else{$f++;echo "NG  $n: ".var_export($a,true)." != ".var_export($e,true)."\n";}}
$tiles=IMS\Core\TileRegistry::get_tiles_for_user(1);
t('一般社員：承認タイルは出ない', array_column($tiles,'id'), ['timecard','attendance','profile']);
$USER=new U(['ims_use_portal','ims_approve']); $m=new ReflectionProperty(IMS\Core\TileRegistry::class,'tiles'); $m->setAccessible(true); $tiles2=IMS\Core\TileRegistry::get_tiles_for_user(1);
t('承認者：月次勤怠表の次に承認タイル', array_column($tiles2,'id'), ['timecard','attendance','attendance_approval','profile']);
t('承認タイルのリンク（home からの相対）', $tiles2[2]['url'], '/wp-admin/admin.php?page=ims-monthly-submissions');
t('承認タイルの名前', $tiles2[2]['label'], '月次勤怠の承認');
t('名前', $tiles[1]['label'], '月次勤怠表');
t('リンク先', $tiles[1]['url'], '/portal/attendance/');
t('未提出バッジ（黄）', [$tiles[1]['resolved_badge'],$tiles[1]['resolved_badge_tone']], ['未提出','warning']);
t('他タイルは影響なし', $tiles[0]['resolved_badge'], null);
echo $f? "FAILED $f\n" : "ALL PASS\n"; exit($f?1:0);
}
