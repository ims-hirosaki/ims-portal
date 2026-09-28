<?php
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 2));

define('ABSPATH', '/');
foreach (['AttendanceFlagCalculator','YayoiCsvBuilder'] as $c) require IMS_SMOKE_ROOT."/src/Module/Attendance/$c.php";
use IMS\Module\Attendance\YayoiCsvBuilder as Y;
$f=0; function t($n,$a,$e){global $f; if($a===$e){echo "OK  $n\n";}else{$f++;echo "NG  $n: ".var_export($a,true)." != ".var_export($e,true)."\n";}}
t('時間 90分', Y::hours(90), '1.50');
t('時間 0分', Y::hours(0), '0.00');
t('時間 1分→0.02', Y::hours(1), '0.02');
t('時間 10000分', Y::hours(10000), '166.67');
$sum=['total_work_days'=>20,'total_actual_minutes'=>10200,'total_overtime_legal'=>300,'total_overtime_illegal'=>540,'total_late_night_min'=>45,'total_paid_leave_days'=>1.5];
$days=[
 '2026-09-06'=>['attendance_flag'=>'holiday_work','actual_minutes'=>540,'overtime_legal_min'=>0,'overtime_illegal_min'=>60],
 '2026-09-07'=>['attendance_flag'=>'none','actual_minutes'=>480,'overtime_legal_min'=>0,'overtime_illegal_min'=>0],
];
t('休日出勤集計', Y::holiday_work_minutes($days), ['total'=>540,'overtime'=>60]);
// 所定内 = 10200-300-540-(540-60) = 8880 → 148.00
t('行', Y::row('E001','2026-09',$sum,$days), ['E001','2026','9','20','0','1.5','170.00','148.00','5.00','9.00','0.75','9.00']);
t('行 有給整数', Y::row('E002','2026-10',['total_paid_leave_days'=>'2.0'],[])[5], '2.0');
t('行 所定内は負にならない', Y::row('E3','2026-09',['total_actual_minutes'=>100,'total_overtime_legal'=>200],[])[7], '0.00');
$csv=Y::to_csv([Y::row('E001','2026-09',$sum,$days), ['A,"B"','x']]);
t('CRLF・見出し', substr($csv,0,strlen('従業員コード,年,月')), '従業員コード,年,月');
t('CRLFで終わる', substr($csv,-2), "\r\n");
t('LF単独なし', preg_match('/(?<!\r)\n/',$csv), 0);
t('クォート', explode("\r\n",$csv)[2], '"A,""B""",x');
$sj=Y::to_sjis($csv);
t('SJIS変換', bin2hex(substr($sj,0,2)), bin2hex("\x8f\x5d")); // 「従」= 0x8F5D
t('SJIS往復', mb_convert_encoding($sj,'UTF-8','SJIS-win'), $csv);
echo $f? "FAILED $f\n" : "ALL PASS\n"; exit($f?1:0);
