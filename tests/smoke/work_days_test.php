<?php
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 2));

define('ABSPATH', '/');
foreach (['AttendanceFlagCalculator','WeeklyOvertimeCalculator','MonthlySummaryCalculator'] as $c) require IMS_SMOKE_ROOT."/src/Module/Attendance/$c.php";
use IMS\Module\Attendance\MonthlySummaryCalculator as M;
$f=0; function t($n,$a,$e){global $f; if($a===$e){echo "OK  $n\n";}else{$f++;echo "NG  $n: ".var_export($a,true)." != ".var_export($e,true)."\n";}}
$d=fn($flag,$in)=>['attendance_flag'=>$flag,'clock_in_minutes'=>$in,'actual_minutes'=>0,'rounded_actual_minutes'=>0];
t('フラグなし・打刻あり', M::is_work_day('none',$d('none',540)), true);
t('フラグなし・打刻なし（欠勤/休日）', M::is_work_day('none',$d('none',null)), false);
t('休日出勤', M::is_work_day('holiday_work',$d('holiday_work',540)), true);
t('時間休', M::is_work_day('hourly_leave',$d('hourly_leave',null)), true);
t('半日休', M::is_work_day('half_day_am',$d('half_day_am',780)), true);
t('有給', M::is_work_day('paid_leave',$d('paid_leave',null)), false);
t('振替休', M::is_work_day('legal_substitute',$d('legal_substitute',540)), false);
$days=['2026-09-01'=>$d('none',540),'2026-09-02'=>$d('none',null),'2026-09-05'=>$d('none',null),'2026-09-03'=>$d('paid_leave',null),'2026-09-04'=>$d('half_day_pm',540)];
$a=M::aggregate($days);
t('aggregate 出勤日数', $a['total_work_days'], 2);
t('aggregate 有給日数', $a['total_paid_leave_days'], 1.5);
echo $f? "FAILED $f\n" : "ALL PASS\n"; exit($f?1:0);
