<?php
namespace {
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 2));
}

namespace IMS\Support { final class UserRepository { public static $retired=false; public static function is_retired(int $u): bool { return self::$retired; } } }
namespace IMS\Module\Attendance {
  final class SalaryCycleSettings { public static $closing='eom';
    public static function period_for_year_month(string $ym): array { return PayPeriodCalculator::period_for(self::$closing,$ym); }
    public static function year_month_for_date(string $d): string { return PayPeriodCalculator::year_month_for_date(self::$closing,$d); } }
  final class DailyAttendanceRepository { public static $ranges=[]; public static function has_any_in_range(int $u,string $s,string $e): bool { foreach(self::$ranges as $d) if($d>=$s && $d<=$e) return true; return false; } }
  final class MonthlySummaryService { public static $status=[]; public static function current_status(int $u,string $ym): string { return self::$status[$ym] ?? 'draft'; } }
}
namespace {
define('ABSPATH','/');
$TODAY='2026-09-28'; function current_time($f){ global $TODAY; return $TODAY; } function __($s,$d=''){ return $s; } function add_action(...$a){}
foreach (['PayPeriodCalculator','MonthlySummaryCalculator','SubmissionReminderCalculator','DashboardIntegration'] as $c) require IMS_SMOKE_ROOT."/src/Module/Attendance/$c.php";
use IMS\Module\Attendance as A; use IMS\Module\Attendance\SubmissionReminderCalculator as R;
$f=0; function t($n,$a,$e){global $f; if($a===$e){echo "OK  $n\n";}else{$f++;echo "NG  $n: ".var_export($a,true)." != ".var_export($e,true)."\n";}}
// 純粋関数（期限 9/30）
foreach ([['2026-09-26',null],['2026-09-27','neutral'],['2026-09-30','neutral'],['2026-10-01','warning'],['2026-10-03','warning'],['2026-10-04','danger'],['2026-12-31','danger']] as [$d,$e]) t("段階 $d", R::level($d,'2026-09-30','draft'), $e);
t('差し戻し中も対象', R::level('2026-10-01','2026-09-30','rejected_by_admin'), 'warning');
foreach (['submitted','checked','confirmed'] as $s) t("提出済み（{$s}）は出さない", R::level('2026-12-31','2026-09-30',$s), null);
t('年またぎ', R::level('2027-01-02','2026-12-31','draft'), 'warning');
t('worst', R::worst([null,'neutral','danger','warning']), 'danger');
t('worst 全null', R::worst([null,null]), null);
// バッジ（月末締め）
A\DailyAttendanceRepository::$ranges=['2026-08-10','2026-09-10'];
$TODAY='2026-09-28'; A\MonthlySummaryService::$status=['2026-08'=>'confirmed'];
t('月末締め：期限3日前→グレー', A\DashboardIntegration::badge(1), ['text'=>'未提出','tone'=>'neutral']);
$TODAY='2026-09-20'; t('月末締め：まだ早い→なし', A\DashboardIntegration::badge(1), null);
$TODAY='2026-10-02'; t('月末締め：期限後2日→黄（前月を拾う）', A\DashboardIntegration::badge(1), ['text'=>'未提出','tone'=>'warning']);
$TODAY='2026-10-05'; t('月末締め：期限後5日→赤', A\DashboardIntegration::badge(1), ['text'=>'未提出','tone'=>'danger']);
A\MonthlySummaryService::$status['2026-09']='submitted'; t('提出したら消える', A\DashboardIntegration::badge(1), null);
A\MonthlySummaryService::$status=['2026-08'=>'draft','2026-09'=>'submitted']; $TODAY='2026-10-05';
t('2か月前の未提出も赤で拾う', A\DashboardIntegration::badge(1), ['text'=>'未提出','tone'=>'danger']);
A\DailyAttendanceRepository::$ranges=['2026-09-10']; A\MonthlySummaryService::$status=['2026-09'=>'submitted'];
t('勤怠記録の無い月は対象外', A\DashboardIntegration::badge(1), null);
// 当月20日締め（9月分＝8/21〜9/20、期限9/20）
A\SalaryCycleSettings::$closing='day20'; A\DailyAttendanceRepository::$ranges=['2026-09-01']; A\MonthlySummaryService::$status=[];
$TODAY='2026-09-17'; t('20日締め：9/17→グレー', A\DashboardIntegration::badge(1), ['text'=>'未提出','tone'=>'neutral']);
$TODAY='2026-09-23'; t('20日締め：9/23→黄', A\DashboardIntegration::badge(1), ['text'=>'未提出','tone'=>'warning']);
$TODAY='2026-09-24'; t('20日締め：9/24→赤', A\DashboardIntegration::badge(1), ['text'=>'未提出','tone'=>'danger']);
// 翌月20日締め（9月分＝9/1〜9/30、期限10/20）
A\SalaryCycleSettings::$closing='next20';
$TODAY='2026-10-05'; t('翌月20日締め：10/5→なし', A\DashboardIntegration::badge(1), null);
$TODAY='2026-10-17'; t('翌月20日締め：10/17→グレー', A\DashboardIntegration::badge(1), ['text'=>'未提出','tone'=>'neutral']);
$TODAY='2026-10-24'; t('翌月20日締め：10/24→赤', A\DashboardIntegration::badge(1), ['text'=>'未提出','tone'=>'danger']);
IMS\Support\UserRepository::$retired=true; t('退職者は出さない', A\DashboardIntegration::badge(1), null);
echo $f? "FAILED $f\n" : "ALL PASS\n"; exit($f?1:0);
}
