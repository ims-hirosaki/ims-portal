<?php
namespace {
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 2));
}

namespace IMS\Support {
  final class Capabilities { public static $caps=[]; 
    public static function can_manage_users(): bool { return in_array('manage',self::$caps,true); }
    public static function can_approve(): bool { return in_array('approve',self::$caps,true); }
    public static function can_run_closing(): bool { return in_array('closing',self::$caps,true); } }
  final class UserRepository { public static $first=[]; public static function get_first_approver_id(int $u): ?int { return self::$first[$u] ?? null; } }
}
namespace IMS\Module\User {
  final class EmployeeRepository { public static $users=[]; public static function list_employees(bool $r=false): array { return self::$users; } }
  final class AdminUserListPage { public const PARENT_SLUG='ims-employees'; }
  final class MasterRepository { public static function all(string $t, bool $i=true): array { return []; } }
}
namespace IMS\Module\Attendance {
  final class MonthlySummaryRepository { public static $canc=[]; public static function find(int $u,string $ym): ?array { return $u===2 ? ['status'=>'submitted','rejection_comment'=>null] : null; } public static function cancellations(int $u,string $ym): array { return self::$canc; } }
  final class AttendanceGridService { public static function month_data(int $u,string $ym): array {
    $day=['date'=>'2026-09-07','day'=>7,'dow'=>1,'clock_in_minutes'=>540,'clock_out_minutes'=>600,'breaks'=>[],
      'attendance_flag'=>'none','flag_label'=>'フラグなし（通常出勤）','hourly_leave_minutes'=>null,
      'actual_minutes'=>60,'overtime_legal_min'=>0,'overtime_illegal_min'=>0,'late_night_minutes'=>0,'rounded_actual_minutes'=>60,
      'allocations'=>[['business_id'=>5,'start_minutes'=>540,'end_minutes'=>600]],'needs_allocation'=>false];
    return ['year_month'=>$ym,'period'=>['start'=>'2026-09-01','end'=>'2026-09-30','deadline'=>'2026-09-30'],
      'businesses'=>[['id'=>5,'name'=>'本社業務','color'=>'#1E3A5F']],'days'=>['2026-09-07'=>$day],'business_totals'=>[5=>60]]; } }
  final class SalaryCycleSettings { public static function year_month_for_date(string $d): string { return '2026-09'; } }
}
namespace {
define('ABSPATH','/'); define('IMS_PORTAL_URL','/'); define('IMS_PORTAL_VERSION','t');
class WP_User { public $ID; public $display_name; function __construct($id,$n){$this->ID=$id;$this->display_name=$n;} }
$CUR=1; $CAN=true;
function get_current_user_id(){ global $CUR; return $CUR; }
function current_user_can($c){ global $CAN; return $CAN; }
function get_user_meta($i,$k,$s){ return 'E00'.$i; }
function esc_html($s){ return htmlspecialchars((string)$s,ENT_QUOTES); } function esc_attr($s){ return esc_html($s); } function esc_url($s){ return esc_html($s); }
function esc_html_e($s,$d=''){ echo esc_html($s); } function esc_attr_e($s,$d=''){ echo esc_attr($s); } function __($s,$d=''){ return $s; } function esc_html__($s,$d=''){ return $s; }
function add_query_arg($a,$u=''){ return $u.'?'.http_build_query($a); } function admin_url($p=''){ return '/wp-admin/'.$p; }
function selected($a,$b){ if((string)$a===(string)$b) echo ' selected'; } function disabled($c){ if($c) echo ' disabled'; }
function sanitize_text_field($s){ return trim((string)$s); } function wp_unslash($s){ return $s; }
function wp_nonce_url($u,$n){ return $u.'&_wpnonce=x'; }
function sanitize_key($s){ return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s)); }
function get_userdata($i){ return new WP_User($i,'佐藤'); }
function wp_die($m){ throw new Exception('die:'.$m); }
foreach (['AttendanceFlagCalculator','WeeklyOvertimeCalculator','MonthlySummaryCalculator','MonthlySummaryService','ConfirmationCancelCalculator','AttendanceGridPage','AttendancePrintCalculator','MonthlyStatementCalculator','MonthlyStatementView','AttendancePrintPage','AdminAttendanceGridPage'] as $c) require IMS_SMOKE_ROOT."/src/Module/Attendance/$c.php";
use IMS\Module\Attendance as A; use IMS\Support\Capabilities as C; use IMS\Support\UserRepository as U; use IMS\Module\User\EmployeeRepository as E;
$f=0; function t($n,$a,$e){global $f; if($a===$e){echo "OK  $n\n";}else{$f++;echo "NG  $n: ".var_export($a,true)." != ".var_export($e,true)."\n";}}
function page($get){ $_GET=$get; ob_start(); A\AdminAttendanceGridPage::render(); return ob_get_clean(); }
E::$users=[new WP_User(1,'管理者'),new WP_User(2,'佐藤'),new WP_User(3,'鈴木')];
// 閲覧権限
C::$caps=['approve']; U::$first=[2=>1];
t('承認者：担当社員は可', A\MonthlySummaryService::can_view_month(1,2), true);
t('承認者：担当外は不可', A\MonthlySummaryService::can_view_month(1,3), false);
t('本人は可', A\MonthlySummaryService::can_view_month(3,3), true);
C::$caps=['approve','manage','closing'];
t('人事管理者：全員可', A\MonthlySummaryService::can_view_month(1,3), true);
// 描画
C::$caps=['approve']; 
$h=page(['user_id'=>'3','ym'=>'2026-09']);
t('担当外を指定→権限エラー表示', str_contains($h,'表示する権限がありません') && !str_contains($h,'ag-table'), true);
t('担当外は社員一覧にも出ない', str_contains($h,'鈴木'), false);
$h=page(['user_id'=>'2','ym'=>'2026-09']);
t('グリッドが出る', str_contains($h,'class="ag-table"'), true);
t('読み取り専用', str_contains($h,'ag-table-readonly') && str_contains($h,' disabled'), true);
t('事業色で塗る', str_contains($h,'background:#1E3A5F;'), true);
t('事業ブロック先頭に事業名', substr_count($h,'<span class="ag-cell-label">本社業務</span>'), 1);
t('凡例（上部固定の枠内）', (bool)preg_match('/ims-ag-admin-legend.*?ag-legend-name">本社業務.*?1時間00分/s',$h), true);
t('凡例がグリッドより上', strpos($h,'ims-ag-admin-legend') < strpos($h,'class="ag-table"'), true);
t('ステータス表示', str_contains($h,'ag-status-submitted') && str_contains($h,'提出済み（確認待ち）'), true);
t('印刷ボタン（1人分）', str_contains($h,'action=ims_attendance_print') && str_contains($h,'user_id=2'), true);
t('編集モーダルは出さない', str_contains($h,'ag-modal'), false);
t('タブ：勤怠が選択中', str_contains($h,'ag-tab is-current" href="/wp-admin/admin.php?page=ims-attendance-grid&amp;user_id=2&amp;ym=2026-09"'), true);
$h=page(['user_id'=>'2','ym'=>'2026-09','tab'=>'statement']);
t('集計表タブ：集計表を出しグリッドは出さない', str_contains($h,'class="ag-statement"') && !str_contains($h,'class="ag-table"'), true);
t('集計表タブ：月送りでタブを保つ', str_contains($h,'ym=2026-10&amp;tab=statement'), true);
t('取り消し履歴なしなら出さない', str_contains($h,'ims-ag-admin-cancellations'), false);
A\MonthlySummaryRepository::$canc=[['cancelled_at'=>'2026-10-05 10:00:00','cancelled_by'=>1,'returned_status'=>'checked','cancel_reason'=>'誤承認','prev_final_approved_by'=>9,'prev_final_approved_at'=>'2026-10-02 10:00:00']];
$h=page(['user_id'=>'2','ym'=>'2026-09']);
t('取り消し履歴を表示', str_contains($h,'確定を1回取り消しています') && str_contains($h,'誤承認') && str_contains($h,'2026年10月2日 10:00'), true);
A\MonthlySummaryRepository::$canc=[];
$h=page(['ym'=>'2026-09']);
t('未選択時は案内のみ', str_contains($h,'表示する社員を選んでください') && !str_contains($h,'ag-table'), true);
$CAN=false; try { page([]); t('権限なし',false,true);} catch(Exception $e){ t('権限なしは403', $e->getMessage(), 'die:この操作を行う権限がありません。'); }
echo $f? "FAILED $f\n" : "ALL PASS\n"; exit($f?1:0);
}
