<?php
namespace {
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 3));
}

namespace IMS\Support { final class Capabilities { public static $closing=true;
  public static function can_run_closing(): bool { return self::$closing; } public static function can_manage_users(): bool { return self::$closing; } public static function can_approve(): bool { return true; } }
  final class UserRepository { public static function get_first_approver_id(int $u): ?int { return null; } } }
namespace {
define('ABSPATH','/'); define('ARRAY_A','ARRAY_A');
require __DIR__.'/miniwpdb.php'; $wpdb=new MiniWpdb;
class WP_Error { public $code; public $msg; function __construct($c,$m,$d=null){$this->code=$c;$this->msg=$m;} function get_error_code(){return $this->code;} function get_error_message(){return $this->msg;} }
$OPT=[]; function get_option($k,$d=false){ global $OPT; return $OPT[$k]??$d; } function update_option($k,$v,$a=null){ global $OPT; $OPT[$k]=$v; }
function current_time($f){ return '2026-10-05 10:00:00'; } function add_filter(...$a){}
foreach (['Schema','MonthlySummaryCalculator','ConfirmationCancelSettings','ConfirmationCancelCalculator','MonthlySummaryRepository','MonthlySummaryService'] as $c) require IMS_SMOKE_ROOT."/src/Module/Attendance/$c.php";
use IMS\Module\Attendance as A;
$f=0; function t($n,$a,$e){global $f; if($a===$e){echo "OK  $n\n";}else{$f++;echo "NG  $n: ".var_export($a,true)." != ".var_export($e,true)."\n";}}
$code=fn($r)=>$r===true?'ok':$r->get_error_code();
$wpdb->query('DELETE FROM wp_monthly_summary'); $wpdb->query('DELETE FROM wp_monthly_confirmation_cancellations');
$seed=function($uid,$status='confirmed') use($wpdb){ $wpdb->insert('wp_monthly_summary',['user_id'=>$uid,'target_year_month'=>'2026-09','status'=>$status,'total_work_days'=>20,
  'snapshot_base_salary'=>215000,'snapshot_allowances'=>'{"1":10000}','first_approved_by'=>7,'first_approved_at'=>'2026-10-01 09:00:00','final_approved_by'=>9,'final_approved_at'=>'2026-10-02 10:00:00','rejection_comment'=>null]); };
$seed(2); $seed(3); $seed(4,'checked');
$logs=fn()=>(int)$wpdb->get_var('SELECT COUNT(*) FROM wp_monthly_confirmation_cancellations');
// 入力チェック（純粋関数）
t('理由なし', A\ConfirmationCancelCalculator::validate(true,'confirmed','checked','  ')[0], 'validation');
t('戻し先不正', A\ConfirmationCancelCalculator::validate(true,'confirmed','draft','x')[0], 'validation');
t('設定オフ', A\ConfirmationCancelCalculator::validate(false,'confirmed','checked','x')[0], 'disabled');
t('確定済みでない', A\ConfirmationCancelCalculator::validate(true,'checked','checked','x')[0], 'invalid_status');
t('OK', A\ConfirmationCancelCalculator::validate(true,'confirmed','checked','x'), null);
// 権限
t('自分の月は不可', $code(A\MonthlySummaryService::cancel_confirmation(2,2,'2026-09','checked','誤り')), 'forbidden_self');
IMS\Support\Capabilities::$closing=false;
t('権限なし', $code(A\MonthlySummaryService::cancel_confirmation(1,2,'2026-09','checked','誤り')), 'forbidden');
IMS\Support\Capabilities::$closing=true;
A\ConfirmationCancelSettings::update(false);
t('設定オフなら不可', $code(A\MonthlySummaryService::cancel_confirmation(1,2,'2026-09','checked','誤り')), 'disabled');
A\ConfirmationCancelSettings::update(true);
t('ここまで記録は0件', $logs(), 0);
// 本人に差し戻す
t('本人に差し戻す', $code(A\MonthlySummaryService::cancel_confirmation(1,2,'2026-09','rejected_by_admin','  手当の登録漏れ  ')), 'ok');
$r=A\MonthlySummaryRepository::find(2,'2026-09');
t('状態', $r['status'], 'rejected_by_admin');
t('最終承認・スナップショットは空', [$r['final_approved_by'],$r['final_approved_at'],$r['snapshot_base_salary'],$r['snapshot_allowances']], [null,null,null,null]);
t('差し戻し理由（前後の空白除去）', [$r['rejection_comment'],$r['last_rejected_by'],$r['last_rejected_at']], ['手当の登録漏れ','1','2026-10-05 10:00:00']);
t('チェック承認の記録は残す', $r['first_approved_by'], '7');
$log=A\MonthlySummaryRepository::cancellations(2,'2026-09');
t('記録1件', count($log), 1);
t('記録の中身', [$log[0]['returned_status'],$log[0]['cancel_reason'],$log[0]['cancelled_by'],$log[0]['prev_final_approved_by'],$log[0]['prev_final_approved_at'],$log[0]['prev_snapshot_base_salary'],json_decode($log[0]['prev_snapshot_allowances'],true)],
  ['rejected_by_admin','手当の登録漏れ','1','9','2026-10-02 10:00:00','215000',['1'=>10000]]);
t('もう一度は不可（確定済みでない）', $code(A\MonthlySummaryService::cancel_confirmation(1,2,'2026-09','checked','再度')), 'invalid_status');
// 最終承認だけやり直す
t('チェック済みに戻す', $code(A\MonthlySummaryService::cancel_confirmation(1,3,'2026-09','checked','承認者の誤り')), 'ok');
$r=A\MonthlySummaryRepository::find(3,'2026-09');
t('状態checked・差し戻し理由は書かない', [$r['status'],$r['rejection_comment'],$r['last_rejected_by'],$r['final_approved_by']], ['checked',null,null,null]);
t('記録は合計2件', $logs(), 2);
t('確定していない月は不可', $code(A\MonthlySummaryService::cancel_confirmation(1,4,'2026-09','checked','x')), 'invalid_status');
t('行が無い月は不可', $code(A\MonthlySummaryService::cancel_confirmation(1,5,'2026-09','checked','x')), 'invalid_status');
// 同時実行：確認後に状態が変わっていたら、記録も含めて保存しない（ロールバック）
$row=A\MonthlySummaryRepository::find(4,'2026-09');
$ok=A\MonthlySummaryRepository::cancel_confirmation((int)$row['id'], A\ConfirmationCancelCalculator::log_row($row,'checked','x',1,'2026-10-05 10:00:00'), A\ConfirmationCancelCalculator::summary_update('checked','x',1,'2026-10-05 10:00:00'));
t('状態が変わっていたら失敗', $ok, false);
t('ロールバックで記録も増えない', $logs(), 2);
echo $f? "FAILED $f\n" : "ALL PASS\n"; exit($f?1:0);
}
