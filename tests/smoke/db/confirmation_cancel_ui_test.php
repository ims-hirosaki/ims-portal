<?php
namespace {
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 3));
}

namespace IMS\Support { final class Capabilities { public static function can_run_closing(): bool { return true; } public static function can_manage_users(): bool { return true; } public static function can_approve(): bool { return true; } }
  final class UserRepository { public static function get_first_approver_id(int $u): ?int { return null; } } }
namespace IMS\Module\User { final class AdminUserListPage { public const PARENT_SLUG='x'; } final class EmployeeRepository {} }
namespace {
define('ABSPATH','/'); define('ARRAY_A','ARRAY_A');
require __DIR__.'/miniwpdb.php'; $wpdb=new MiniWpdb;
class WP_Error { public $code; public $msg; function __construct($c,$m,$d=null){$this->code=$c;$this->msg=$m;} function get_error_code(){return $this->code;} function get_error_message(){return $this->msg;} }
class Redirect extends Exception {}
function is_wp_error($x){ return $x instanceof WP_Error; }
$OPT=[]; function get_option($k,$d=false){ global $OPT; return $OPT[$k]??$d; } function update_option($k,$v,$a=null){ global $OPT; $OPT[$k]=$v; }
function current_time($f){ return '2026-10-05 10:00:00'; } function add_filter(...$a){} function add_action(...$a){}
function current_user_can($c){ return true; } function check_admin_referer($n){ return true; } function get_current_user_id(){ return 1; }
function sanitize_key($s){ return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s)); } function sanitize_text_field($s){ return trim((string)$s); } function sanitize_textarea_field($s){ return trim((string)$s); } function wp_unslash($s){ return $s; }
function add_query_arg($a,$u=''){ return $u.'?'.http_build_query($a); } function admin_url($p=''){ return '/wp-admin/'.$p; }
function wp_safe_redirect($u){ throw new Redirect($u); }
function esc_html($s){ return htmlspecialchars((string)$s,ENT_QUOTES); } function esc_attr($s){ return esc_html($s); } function esc_url($s){ return esc_html($s); } function esc_js($s){ return $s; }
function wp_nonce_field($n){ echo '<input type=hidden name=_wpnonce>'; } function checked($a,$b=true){ if($a==$b) echo ' checked'; }
foreach (['Schema','MonthlySummaryCalculator','ConfirmationCancelSettings','ConfirmationCancelCalculator','MonthlySummaryRepository','MonthlySummaryService','AdminMonthlySubmissionsPage'] as $c) require IMS_SMOKE_ROOT."/src/Module/Attendance/$c.php";
use IMS\Module\Attendance as A;
$f=0; function t($n,$a,$e){global $f; if($a===$e){echo "OK  $n\n";}else{$f++;echo "NG  $n: ".var_export($a,true)." != ".var_export($e,true)."\n";}}
$wpdb->query('DELETE FROM wp_monthly_summary'); $wpdb->query('DELETE FROM wp_monthly_confirmation_cancellations');
$wpdb->insert('wp_monthly_summary',['user_id'=>2,'target_year_month'=>'2026-09','status'=>'confirmed','final_approved_by'=>9,'final_approved_at'=>'2026-10-02 10:00:00']);
$actions=function($actor,$target,$status){ $m=new ReflectionMethod(A\AdminMonthlySubmissionsPage::class,'render_actions'); $m->setAccessible(true); ob_start(); $m->invoke(null,$actor,$target,'2026-09',['status'=>$status,'label'=>'','is_editable'=>false,'rejection_comment'=>null]); return ob_get_clean(); };
$h=$actions(1,2,'confirmed');
t('確定済みの行に取り消しフォーム', str_contains($h,'value="ims_monthly_cancel_confirmation"') && str_contains($h,'<summary>確定を取り消す</summary>'), true);
t('戻し先2つ（先頭が既定）', substr_count($h,'name="returned_status"')===2 && (bool)preg_match('/value="rejected_by_admin"\s+checked/',$h), true);
t('理由は必須', str_contains($h,'name="reason"') && str_contains($h,'required></textarea>'), true);
t('自分の行には出さない', str_contains($actions(2,2,'confirmed'),'ims_monthly_cancel_confirmation'), false);
A\ConfirmationCancelSettings::update(false);
t('設定オフなら出さない', str_contains($actions(1,2,'confirmed'),'ims_monthly_cancel_confirmation'), false);
A\ConfirmationCancelSettings::update(true);
// 送信
$post=function($data){ $_POST=$data; try { A\AdminMonthlySubmissionsPage::handle_cancel_confirmation(); return 'no-redirect'; } catch(Redirect $e){ parse_str(parse_url(html_entity_decode($e->getMessage()),PHP_URL_QUERY),$q); return [$q['ims_notice'],rawurldecode($q['ims_msg'])]; } };
t('理由なし→エラー表示', $post(['user_id'=>'2','year_month'=>'2026-09','returned_status'=>'checked','reason'=>'']), ['error','取り消す理由を入力してください。']);
t('成功→完了表示', $post(['user_id'=>'2','year_month'=>'2026-09','returned_status'=>'rejected_by_admin','reason'=>'テストの誤承認']), ['success','confirmation_cancelled']);
t('DBが差し戻しに', A\MonthlySummaryRepository::find(2,'2026-09')['status'], 'rejected_by_admin');
t('記録1件', count(A\MonthlySummaryRepository::cancellations(2,'2026-09')), 1);
echo $f? "FAILED $f\n" : "ALL PASS\n"; exit($f?1:0);
}
