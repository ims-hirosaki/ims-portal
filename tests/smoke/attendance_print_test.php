<?php
namespace {
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 2));
}

namespace IMS\Support { final class Capabilities { public static $caps=[];
    public static function can_manage_users(): bool { return in_array('manage',self::$caps,true); }
    public static function can_approve(): bool { return in_array('approve',self::$caps,true); }
    public static function can_run_closing(): bool { return in_array('closing',self::$caps,true); } }
  final class UserRepository { public static $first=[]; public static function get_first_approver_id(int $u): ?int { return self::$first[$u] ?? null; } } }
namespace IMS\Module\User { final class EmployeeRepository { public static $users=[]; public static function list_employees(bool $r=false): array { return self::$users; } } }
namespace IMS\Module\Attendance {
  final class MonthlySummaryRepository { public static $rows=[]; public static function find(int $u,string $ym): ?array { return self::$rows[$u] ?? null; } }
  final class AttendanceGridService { public static function month_data(int $u,string $ym): array { return ['period'=>['start'=>'2026-09-01','end'=>'2026-09-30'],'businesses'=>[],'days'=>[],'business_totals'=>[]]; } }
}
namespace {
define('ABSPATH','/'); define('IMS_PORTAL_URL','/p/'); define('IMS_PORTAL_VERSION','t');
class WP_User { public $ID; public $display_name; function __construct($id,$n){$this->ID=$id;$this->display_name=$n;} }
class Done extends Exception {}
$CUR=1; $CAN=true;
function get_current_user_id(){ global $CUR; return $CUR; } function current_user_can($c){ global $CAN; return $CAN; }
function check_admin_referer($n){ return true; } function get_userdata($i){ return $i<=5 ? new WP_User($i,'U'.$i) : false; }
function get_user_meta($i,$k,$s){ return ['1'=>'E9','2'=>'E1','3'=>'E5'][$i] ?? ''; }
function esc_html($s){ return htmlspecialchars((string)$s,ENT_QUOTES); } function esc_attr($s){ return esc_html($s); } function esc_url($s){ return esc_html($s); }
function esc_html_e($s,$d=''){ echo esc_html($s); } function esc_attr_e($s,$d=''){ echo esc_attr($s); } function __($s,$d=''){ return $s; } function esc_html__($s,$d=''){ return $s; }
function selected($a,$b){} function disabled($c){} function current_time($f){ return date($f); }
function sanitize_text_field($s){ return trim((string)$s); } function wp_unslash($s){ return $s; } function nocache_headers(){}
function add_query_arg($a,$u=''){ return $u.'?'.http_build_query($a); } function admin_url($p=''){ return '/wp-admin/'.$p; } function wp_nonce_url($u,$n){ return $u.'&_wpnonce=x'; }
function wp_die($m,$t='',$a=[]){ throw new Done(($a['response'] ?? 0).':'.$m); }
foreach (['AttendanceFlagCalculator','WeeklyOvertimeCalculator','MonthlySummaryCalculator','MonthlySummaryService','AttendanceGridPage','AttendancePrintCalculator','MonthlyStatementCalculator','AttendancePrintPage'] as $c) require IMS_SMOKE_ROOT."/src/Module/Attendance/$c.php";
use IMS\Module\Attendance as A; use IMS\Support\Capabilities as C; use IMS\Support\UserRepository as U; use IMS\Module\User\EmployeeRepository as E;
$f=0; function t($n,$a,$e){global $f; if($a===$e){echo "OK  $n\n";}else{$f++;echo "NG  $n: ".var_export($a,true)." != ".var_export($e,true)."\n";}}
// 事業別明細
$biz=[['id'=>1,'name'=>'本社','color'=>'#111'],['id'=>2,'name'=>'氷河','color'=>'#222'],['id'=>3,'name'=>'未使用','color'=>'#333']];
$days=['2026-09-01'=>['allocations'=>[['business_id'=>2,'start_minutes'=>540,'end_minutes'=>600],['business_id'=>1,'start_minutes'=>600,'end_minutes'=>720],['business_id'=>1,'start_minutes'=>780,'end_minutes'=>840]]],
  '2026-09-02'=>['allocations'=>[['business_id'=>1,'start_minutes'=>540,'end_minutes'=>600],['business_id'=>9,'start_minutes'=>600,'end_minutes'=>630]]],
  '2026-09-03'=>['allocations'=>[]]];
$r=A\AttendancePrintCalculator::business_breakdown($days,$biz);
t('事業マスタ順・割当てのある事業のみ', array_column($r,'name'), ['本社','氷河','（現在は使われていない事業）']);
t('時間', array_column($r,'minutes'), [240,60,30]);
t('日数（同日複数ブロックは1日）', array_column($r,'days'), [2,1,1]);
t('空', A\AttendancePrintCalculator::business_breakdown([],$biz), []);
t('時間表記', A\AttendancePrintCalculator::format_hours(605), '10時間05分');
t('URL（1人）', str_contains(A\AttendancePrintPage::url('2026-09',3),'user_id=3'), true);
t('URL（全員）', str_contains(A\AttendancePrintPage::url('2026-09'),'user_id'), false);
// 権限
function run($get){ $_GET=$get; ob_start(); try { A\AttendancePrintPage::handle(); $o=ob_get_clean(); return 'ok'; } catch(Done $e){ $o=ob_get_clean(); if($e->getMessage()==='0:exit') return $o; return $e->getMessage(); } }
E::$users=[new WP_User(1,'管理'),new WP_User(2,'佐藤'),new WP_User(3,'鈴木')];
A\MonthlySummaryRepository::$rows=[2=>['status'=>'confirmed','total_actual_minutes'=>0],3=>['status'=>'checked']];
$CAN=false; t('画面権限なし→403', run(['ym'=>'2026-09','user_id'=>'2']), '403:この操作を行う権限がありません。');
$CAN=true; C::$caps=['approve']; U::$first=[2=>1];
t('年月不正→400', run(['ym'=>'2026-9']), '400:対象年月の形式が正しくありません。');
t('承認者：担当外→403', run(['ym'=>'2026-09','user_id'=>'3']), '403:この操作を行う権限がありません。');
t('承認者：全員分→403', run(['ym'=>'2026-09']), '403:この操作を行う権限がありません。');
t('存在しない社員→403', run(['ym'=>'2026-09','user_id'=>'99']), '403:この操作を行う権限がありません。');
A\MonthlySummaryRepository::$rows=[1=>['status'=>'confirmed'],2=>['status'=>'confirmed'],3=>['status'=>'checked']];
$m=new ReflectionMethod(A\AttendancePrintPage::class,'confirmed_employees'); $m->setAccessible(true);
t('全員分は確定済みのみ・社員番号順', array_map(fn($u)=>$u->ID, $m->invoke(null,'2026-09')), [2,1]);
echo $f? "FAILED $f\n" : "ALL PASS\n"; exit($f?1:0);
}
