<?php
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 2));

define('ABSPATH', '/');
date_default_timezone_set('Asia/Tokyo');
foreach (['AttendanceFlagCalculator','WeeklyOvertimeCalculator','MonthlySummaryCalculator'] as $c) require IMS_SMOKE_ROOT."/src/Module/Attendance/$c.php";
use IMS\Module\Attendance\WeeklyOvertimeCalculator as W;
use IMS\Module\Attendance\MonthlySummaryCalculator as M;
$f=0; function t($n,$a,$e){global $f; if($a===$e){echo "OK  $n\n";}else{$f++;echo "NG  $n: ".var_export($a,true)." != ".var_export($e,true)."\n";}}
// 日データ生成：打刻実働 $w 分・所定 $s 分（日次の残業は3bの式で算出）
function d($w,$s=480,$flag='none',$extra=0){
  $legal=max(0,min($w,480)-$s); $ill=max(0,$w-480);
  if(in_array($flag,['paid_leave','legal_substitute','scheduled_substitute'],true)){ return ['attendance_flag'=>$flag,'rounded_actual_minutes'=>$w,'actual_minutes'=>$s,'overtime_legal_min'=>0,'overtime_illegal_min'=>0]; }
  return ['attendance_flag'=>$flag,'rounded_actual_minutes'=>$w,'actual_minutes'=>$w+$extra,'overtime_legal_min'=>$legal,'overtime_illegal_min'=>$ill];
}
// 2026-09-06 は日曜
function wk($start,$list){ $o=[]; $ts=strtotime($start); foreach($list as $i=>$d){ if($d!==null) $o[date('Y-m-d',$ts+86400*$i)]=$d; } return $o; }
t('週の開始（日曜）', W::week_start('2026-09-06'), '2026-09-06');
t('週の開始（土曜）', W::week_start('2026-09-12'), '2026-09-06');
t('週の開始（月またぎ）', W::week_start('2026-10-01'), '2026-09-27');
t('週の開始（年またぎ）', W::week_start('2027-01-01'), '2026-12-27');
$r=fn($days)=>W::calculate($days);
t('月〜金 8h：40hちょうど', $r(wk('2026-09-06',[null,d(480),d(480),d(480),d(480),d(480),null])), ['weekly_illegal_min'=>0,'legal_reduction_min'=>0]);
t('月〜土 8h（所定8h）', $r(wk('2026-09-06',[null,d(480),d(480),d(480),d(480),d(480),d(480)])), ['weekly_illegal_min'=>480,'legal_reduction_min'=>0]);
t('月〜土 8h（所定7h）二重計上を差し引く', $r(wk('2026-09-06',[null,d(480,420),d(480,420),d(480,420),d(480,420),d(480,420),d(480,420)])), ['weekly_illegal_min'=>480,'legal_reduction_min'=>60]);
t('月〜金 9h：日次で計上済み', $r(wk('2026-09-06',[null,d(540),d(540),d(540),d(540),d(540),null])), ['weekly_illegal_min'=>0,'legal_reduction_min'=>0]);
t('有給は数えない', $r(wk('2026-09-06',[null,d(0,480,'paid_leave'),d(480),d(480),d(480),d(480),d(480)])), ['weekly_illegal_min'=>0,'legal_reduction_min'=>0]);
t('振替休は打刻があっても数えない', $r(wk('2026-09-06',[d(480,480,'legal_substitute'),d(480),d(480),d(480),d(480),d(480),null])), ['weekly_illegal_min'=>0,'legal_reduction_min'=>0]);
t('時間休の加算分は数えない', $r(wk('2026-09-06',[null,d(420,480,'hourly_leave',60),d(420,480,'hourly_leave',60),d(420),d(420),d(420),d(420)])), ['weekly_illegal_min'=>120,'legal_reduction_min'=>0]);
t('休日出勤は数えない', $r(wk('2026-09-06',[d(480,480,'holiday_work'),d(480),d(480),d(480),d(480),d(480),null])), ['weekly_illegal_min'=>0,'legal_reduction_min'=>0]);
t('休日出勤の日次残業は残る', M::aggregate(wk('2026-09-06',[d(600,480,'holiday_work'),d(480),d(480),d(480),d(480),d(480),null]))['total_overtime_illegal'], 120);
t('日の途中で40h超（差引は超過分まで）', $r(wk('2026-09-06',[d(60,360),d(480,360),d(480,360),d(480,360),d(480,360),d(480,360),null])), ['weekly_illegal_min'=>60,'legal_reduction_min'=>60]);
t('日の途中で40h超（法定内より超過が大きい）', $r(wk('2026-09-06',[null,d(300,420),d(480,420),d(480,420),d(480,420),d(480,420),d(480,420)])), ['weekly_illegal_min'=>300,'legal_reduction_min'=>60]);
// 期間が水曜始まり（前週の日曜〜火曜は対象外）→ 水〜土 8h×4 と翌週 日〜土
$p = wk('2026-09-09', [d(480),d(480),d(480),d(480), d(480),d(480),d(480),d(480),d(480),d(480),null]);
t('期間またぎの週は期間内の日だけ／週ごとに独立', $r($p), ['weekly_illegal_min'=>480,'legal_reduction_min'=>0]);
t('日付順不同でも同じ結果', $r(array_reverse($p,true)), ['weekly_illegal_min'=>480,'legal_reduction_min'=>0]);
t('空', $r([]), ['weekly_illegal_min'=>0,'legal_reduction_min'=>0]);
// aggregate 統合
$a = M::aggregate(wk('2026-09-06',[null,d(480,420),d(480,420),d(480,420),d(480,420),d(480,420),d(540,420)]));
t('aggregate 法定外=日次60+週次480', $a['total_overtime_illegal'], 540);
t('aggregate 法定内=360-60', $a['total_overtime_legal'], 300);
t('aggregate 総労働は変えない', $a['total_actual_minutes'], 480*5+540);
echo $f? "FAILED $f\n" : "ALL PASS\n"; exit($f?1:0);
