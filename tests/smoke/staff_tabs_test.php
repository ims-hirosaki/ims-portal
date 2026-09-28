<?php
namespace {
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 2));
}

namespace IMS\Module\User { final class MasterRepository { public static function all(string $t, bool $i=true): array { return [['id'=>1,'allowance_name'=>'役付き手当'],['id'=>2,'allowance_name'=>'皆勤手当']]; } } }
namespace IMS\Module\Attendance {
  final class MonthlySummaryRepository { public static function find(int $u,string $ym): ?array { return ['status'=>'confirmed','total_work_days'=>20,'total_actual_minutes'=>9780,'total_overtime_legal'=>0,'total_overtime_illegal'=>300,'total_late_night_min'=>30,'total_paid_leave_days'=>1.5,'snapshot_base_salary'=>215000,'snapshot_allowances'=>'{"1":10000,"2":5000}']; } }
  final class AttendanceGridService { public static function month_data(int $u,string $ym): array { return ['days'=>['2026-09-02'=>['attendance_flag'=>'hourly_leave','hourly_leave_minutes'=>120]]]; } }
}
namespace {
define('ABSPATH','/');
class WP_User { public $ID; public $display_name; function __construct($id,$n){$this->ID=$id;$this->display_name=$n;} }
function get_userdata($i){ return new WP_User($i,'佐藤 一郎'); } function get_user_meta($i,$k,$s){ return 'E001'; }
function esc_html($s){ return htmlspecialchars((string)$s,ENT_QUOTES); } function esc_attr($s){ return esc_html($s); } function esc_url($s){ return esc_html($s); }
function esc_attr_e($s,$d=''){ echo esc_attr($s); } function __($s,$d=''){ return $s; }
function add_query_arg($a,$u=''){ return $u.'?'.http_build_query($a); } function home_url($p){ return 'https://x'.$p; }
function sanitize_key($s){ return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s)); } function wp_unslash($s){ return $s; }
foreach (['AttendanceFlagCalculator','WeeklyOvertimeCalculator','MonthlySummaryCalculator','AttendanceGridPage','AttendancePrintCalculator','MonthlyStatementCalculator','MonthlyStatementView'] as $c) require IMS_SMOKE_ROOT."/src/Module/Attendance/$c.php";
use IMS\Module\Attendance as A;
$f=0; function t($n,$a,$e){global $f; if($a===$e){echo "OK  $n\n";}else{$f++;echo "NG  $n: ".var_export($a,true)." != ".var_export($e,true)."\n";}}
$call=function($m,...$a){ $r=new ReflectionMethod(A\AttendanceGridPage::class,$m); $r->setAccessible(true); return $r->invoke(null,...$a); };
$_GET=[]; t('既定は勤怠タブ', $call('resolve_tab'), 'grid');
$_GET=['tab'=>'statement']; t('集計表タブ', $call('resolve_tab'), 'statement');
$_GET=['tab'=>'<script>']; t('不正値は勤怠', $call('resolve_tab'), 'grid');
t('月URL（勤怠）', $call('month_url','2026-09'), 'https://x/portal/attendance/?ym=2026-09');
t('月URL（集計表）', $call('month_url','2026-09','statement'), 'https://x/portal/attendance/?ym=2026-09&tab=statement');
$_GET=['tab'=>'statement']; ob_start(); $call('render_tabs','2026-09','statement'); $h=ob_get_clean();
t('タブ：集計表が選択中', (bool)preg_match('/ag-tab is-current"\s+href="[^"]*tab=statement"\s+aria-current="page"/',$h), true);
t('タブ：勤怠リンクはtabなし', str_contains($h,'href="https://x/portal/attendance/?ym=2026-09"'), true);
echo $f? "FAILED $f\n" : "ALL PASS\n"; exit($f?1:0);
}
