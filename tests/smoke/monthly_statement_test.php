<?php
namespace {
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 2));
}

namespace IMS\Module\User { final class MasterRepository { public static $rows=[]; public static function all(string $t, bool $i=true): array { return self::$rows; } } }
namespace IMS\Module\Attendance {
  final class MonthlySummaryRepository { public static $row=null; public static function find(int $u,string $ym): ?array { return self::$row; } }
  final class AttendanceGridService { public static $days=[]; public static function month_data(int $u,string $ym): array { return ['days'=>self::$days]; } }
}
namespace {
define('ABSPATH','/');
class WP_User { public $ID; public $display_name; function __construct($id,$n){$this->ID=$id;$this->display_name=$n;} }
function get_userdata($i){ return new WP_User($i,'佐藤 一郎'); } function get_user_meta($i,$k,$s){ return 'E001'; }
function esc_html($s){ return htmlspecialchars((string)$s,ENT_QUOTES); }
foreach (['AttendanceFlagCalculator','WeeklyOvertimeCalculator','MonthlySummaryCalculator','AttendancePrintCalculator','MonthlyStatementCalculator','MonthlyStatementView'] as $c) require IMS_SMOKE_ROOT."/src/Module/Attendance/$c.php";
use IMS\Module\Attendance as A; use IMS\Module\Attendance\MonthlyStatementCalculator as C;
$f=0; function t($n,$a,$e){global $f; if($a===$e){echo "OK  $n\n";}else{$f++;echo "NG  $n: ".var_export($a,true)." != ".var_export($e,true)."\n";}}
$days=['2026-09-01'=>['attendance_flag'=>'none','clock_in_minutes'=>540,'actual_minutes'=>480,'rounded_actual_minutes'=>480,'overtime_legal_min'=>0,'overtime_illegal_min'=>0,'late_night_minutes'=>0],
       '2026-09-02'=>['attendance_flag'=>'hourly_leave','clock_in_minutes'=>600,'hourly_leave_minutes'=>120,'actual_minutes'=>480,'rounded_actual_minutes'=>360,'overtime_legal_min'=>0,'overtime_illegal_min'=>0,'late_night_minutes'=>0],
       '2026-09-03'=>['attendance_flag'=>'hourly_leave','clock_in_minutes'=>540,'hourly_leave_minutes'=>60,'actual_minutes'=>480,'rounded_actual_minutes'=>420,'overtime_legal_min'=>0,'overtime_illegal_min'=>0,'late_night_minutes'=>0],
       '2026-09-04'=>['attendance_flag'=>'paid_leave','clock_in_minutes'=>null,'actual_minutes'=>480,'rounded_actual_minutes'=>null,'overtime_legal_min'=>0,'overtime_illegal_min'=>0,'late_night_minutes'=>0]];
$stored=['status'=>'submitted','total_work_days'=>99,'total_actual_minutes'=>1,'total_overtime_legal'=>2,'total_overtime_illegal'=>3,'total_late_night_min'=>4,'total_paid_leave_days'=>'1.5'];
// totals
$r=C::totals(null,$days); t('未提出：参考値', [$r['is_final'],$r['total_work_days'],$r['total_actual_minutes'],$r['total_paid_leave_days']], [false,3,1920,1.0]);
$r=C::totals($stored,$days); t('提出済み：確定値', [$r['is_final'],$r['total_work_days'],$r['total_paid_leave_days']], [true,99,1.5]);
$r=C::totals(['status'=>'rejected_by_checker']+$stored,$days); t('差し戻し中：参考値', [$r['is_final'],$r['total_work_days']], [false,3]);
$r=C::totals(['status'=>'draft','total_actual_minutes'=>null],$days); t('draft行：参考値', $r['is_final'], false);
t('時間休', C::hourly_leave($days), ['days'=>2,'minutes'=>180]);
// salary
$masters=[['id'=>3,'allowance_name'=>'皆勤手当'],['id'=>1,'allowance_name'=>'役付き手当'],['id'=>2,'allowance_name'=>'家族手当（無効化）']];
$conf=['status'=>'confirmed','snapshot_base_salary'=>'215000','snapshot_allowances'=>'{"1":10000,"3":5000,"9":700}'];
$r=C::salary($conf,$masters);
t('給与：記録あり', $r['recorded'], true);
t('給与：基本給', $r['base_salary'], 215000);
t('給与：マスタ順・未設定手当は出さない・削除済みは末尾', $r['allowances'], [['name'=>'皆勤手当','amount'=>5000],['name'=>'役付き手当','amount'=>10000],['name'=>'（削除された手当）','amount'=>700]]);
t('給与：小計', $r['subtotal'], 230700);
t('給与：確定前は出さない', C::salary(['status'=>'checked']+$conf,$masters)['recorded'], false);
t('給与：3g以前の確定月（記録なし）', C::salary(['status'=>'confirmed','snapshot_base_salary'=>null,'snapshot_allowances'=>null],$masters)['recorded'], false);
t('給与：手当なし', C::salary(['status'=>'confirmed','snapshot_base_salary'=>'200000','snapshot_allowances'=>'{}'],$masters)['subtotal'], 200000);
t('給与：基本給履歴なし', C::salary(['status'=>'confirmed','snapshot_base_salary'=>null,'snapshot_allowances'=>'{"1":10000}'],$masters)['subtotal'], 10000);
t('給与：未確定', C::salary(null,$masters)['recorded'], false);
t('円', C::yen(215000), '215,000円'); t('日 1.5', C::days_label(1.5), '1.5日'); t('日 2.0', C::days_label(2.0), '2日'); t('日 0', C::days_label(0.0), '0日');
// 描画
IMS\Module\User\MasterRepository::$rows=$masters; A\AttendanceGridService::$days=$days;
A\MonthlySummaryRepository::$row=$conf+$stored; $conf2=$conf+$stored; $conf2['status']='confirmed'; A\MonthlySummaryRepository::$row=$conf2;
ob_start(); A\MonthlyStatementView::render(1,'2026-09'); $h=ob_get_clean();
t('描画：社員番号・氏名', str_contains($h,'E001') && str_contains($h,'佐藤 一郎'), true);
t('描画：基本給', str_contains($h,'215,000円'), true);
t('描画：小計', str_contains($h,'230,700円'), true);
t('描画：確定値（参考値バッジなし）', str_contains($h,'未提出のため参考値'), false);
t('描画：時間休', str_contains($h,'2日（3時間00分）'), true);
t('描画：交通費は準備中', str_contains($h,'準備中'), true);
A\MonthlySummaryRepository::$row=null;
ob_start(); A\MonthlyStatementView::render(1,'2026-09'); $h=ob_get_clean();
t('描画：未提出は参考値・給与は出さない', str_contains($h,'未提出のため参考値') && str_contains($h,'最終承認されると') && !str_contains($h,'円'), true);
echo $f? "FAILED $f\n" : "ALL PASS\n"; exit($f?1:0);
}
