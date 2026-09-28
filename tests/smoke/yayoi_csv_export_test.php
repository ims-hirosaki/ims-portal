<?php
namespace {
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 2));
}

namespace IMS\Module\User { final class EmployeeRepository { public static $users=[]; public static function list_employees(bool $r=false): array { return self::$users; } } }
namespace IMS\Module\Attendance {
  final class MonthlySummaryRepository { public static $rows=[]; public static function find(int $u,string $ym): ?array { return self::$rows[$u][$ym] ?? null; } }
  final class AttendanceGridService { public static $calls=0; public static function month_data(int $u,string $ym): array { self::$calls++; return ['days'=>[]]; } }
}
namespace {
define('ABSPATH','/');
$META=[]; function get_user_meta($id,$k,$s){ global $META; return $META[$id][$k] ?? ''; }
foreach (['AttendanceFlagCalculator','WeeklyOvertimeCalculator','MonthlySummaryCalculator','YayoiCsvBuilder','YayoiCsvExportService'] as $c) require IMS_SMOKE_ROOT."/src/Module/Attendance/$c.php";
use IMS\Module\Attendance as A; use IMS\Module\User\EmployeeRepository as E;
$f=0; function t($n,$a,$e){global $f; if($a===$e){echo "OK  $n\n";}else{$f++;echo "NG  $n: ".var_export($a,true)." != ".var_export($e,true)."\n";}}
$u=function($id,$name){ $o=new stdClass; $o->ID=$id; $o->display_name=$name; return $o; };
E::$users=[$u(1,'佐藤'),$u(2,'鈴木'),$u(3,'高橋'),$u(4,'田中'),$u(5,'伊藤')];
$META=[1=>['employee_code'=>'E010'],2=>['employee_code'=>'E002'],3=>['employee_code'=>''],4=>['employee_code'=>'E004'],5=>['employee_code'=>'E005']];
$c=['status'=>'confirmed','total_work_days'=>20,'total_actual_minutes'=>9600];
A\MonthlySummaryRepository::$rows=[1=>['2026-09'=>$c],2=>['2026-09'=>$c],3=>['2026-09'=>$c],4=>['2026-09'=>['status'=>'checked']+$c]];
$r=A\YayoiCsvExportService::collect('2026-09',false);
t('件数のみ：確定済み・社員番号あり', $r['count'], 2);
t('件数のみ：勤務表を読まない', A\AttendanceGridService::$calls, 0);
t('社員番号なし', $r['missing_code'], ['高橋']);
$r=A\YayoiCsvExportService::collect('2026-09');
t('行数', count($r['rows']), 2);
t('社員番号順', [$r['rows'][0][0],$r['rows'][1][0]], ['E002','E010']);
t('他の月は対象外', A\YayoiCsvExportService::collect('2026-08')['count'], 0);
t('ファイル名', A\YayoiCsvExportService::filename('2026-09'), 'yayoi_kintai_202609.csv');
$csv=A\YayoiCsvExportService::csv($r['rows']);
t('SJIS', mb_check_encoding($csv,'SJIS-win') && !mb_check_encoding($csv,'UTF-8'), true);
echo $f? "FAILED $f\n" : "ALL PASS\n"; exit($f?1:0);
}
