<?php
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 2));

define('ABSPATH', '/');
require IMS_SMOKE_ROOT.'/src/Module/Attendance/SalarySnapshotCalculator.php';
use IMS\Module\Attendance\SalarySnapshotCalculator as C;
$f=0; function t($n,$a,$e){global $f; if($a===$e){echo "OK  $n\n";}else{$f++;echo "NG  $n: ".var_export($a,true)." != ".var_export($e,true)."\n";}}
t('月末 9月', C::month_end_date('2026-09'), '2026-09-30');
t('月末 閏年2月', C::month_end_date('2028-02'), '2028-02-29');
t('月末 平年2月', C::month_end_date('2026-02'), '2026-02-28');
t('月末 12月', C::month_end_date('2026-12'), '2026-12-31');
$s=[
 ['id'=>'1','base_salary'=>'200000','effective_date'=>'2026-04-01'],
 ['id'=>'2','base_salary'=>'210000','effective_date'=>'2026-09-01'],
 ['id'=>'3','base_salary'=>'220000','effective_date'=>'2026-10-01'], // 月末より後＝対象外
 ['id'=>'4','base_salary'=>'215000','effective_date'=>'2026-09-01'], // 同日・後登録が優先
];
t('基本給 最新', C::base_salary($s,'2026-09-30'), 215000);
t('基本給 前月', C::base_salary($s,'2026-08-31'), 200000);
t('基本給 月末当日有効', C::base_salary($s,'2026-10-01'), 220000);
t('基本給 履歴なし', C::base_salary([],'2026-09-30'), null);
t('基本給 全て未来', C::base_salary($s,'2026-03-31'), null);
$a=[
 ['id'=>'10','allowance_master_id'=>'3','amount'=>'5000','effective_date'=>'2026-01-01'],
 ['id'=>'11','allowance_master_id'=>'1','amount'=>'10000','effective_date'=>'2026-01-01'],
 ['id'=>'12','allowance_master_id'=>'1','amount'=>'12000','effective_date'=>'2026-09-15'],
 ['id'=>'13','allowance_master_id'=>'1','amount'=>'15000','effective_date'=>'2026-10-01'],
 ['id'=>'14','allowance_master_id'=>'3','amount'=>'0','effective_date'=>'2026-09-01'], // 0円に変更
 ['id'=>'15','allowance_master_id'=>'7','amount'=>'3000','effective_date'=>'2026-11-01'], // 未来のみ
];
t('手当 9月', C::allowances($a,'2026-09-30'), [1=>12000,3=>0]);
t('手当 8月', C::allowances($a,'2026-08-31'), [1=>10000,3=>5000]);
t('手当 なし', C::allowances([],'2026-09-30'), []);
t('JSON', C::allowances_json([1=>12000,3=>0]), '{"1":12000,"3":0}');
t('JSON 空', C::allowances_json([]), '{}');
echo $f? "FAILED $f\n" : "ALL PASS\n"; exit($f?1:0);
