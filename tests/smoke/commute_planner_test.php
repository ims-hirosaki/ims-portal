<?php
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 2));
// 4d 通勤交通費の自動生成の判定（04 §3.1）
require IMS_SMOKE_ROOT . '/src/Module/Travel/ExpenseCalculator.php';
require IMS_SMOKE_ROOT . '/src/Module/Travel/CommutePlanner.php';
use IMS\Module\Travel\CommutePlanner as P;
$f = 0; function t($n, $a, $e) { global $f; if ($a === $e) { echo "OK  $n\n"; } else { $f++; echo "NG  $n: " . var_export($a, true) . ' != ' . var_export($e, true) . "\n"; } }

t('出勤打刻あり・フラグなし → 全額', P::decide('none', true), 'full');
t('有給 → 0円', P::decide('paid_leave', false), 'zero');
t('法定振替休 → 0円', P::decide('legal_substitute', false), 'zero');
t('所定振替休 → 0円', P::decide('scheduled_substitute', false), 'zero');
t('有給で出勤打刻あり → 0円（フラグ優先）', P::decide('paid_leave', true), 'zero');
t('半日休（午前）出勤 → 全額', P::decide('half_day_am', true), 'full');
t('半日休（午後）出勤 → 全額', P::decide('half_day_pm', true), 'full');
t('時間休 出勤 → 全額', P::decide('hourly_leave', true), 'full');
t('休日出勤 → 全額', P::decide('holiday_work', true), 'full');
t('欠勤（打刻なし・フラグなし）→ 生成しない', P::decide('none', false), 'none');
t('打刻なしの半日休 → 生成しない', P::decide('half_day_am', false), 'none');

t('単価：社員個別を優先', P::resolve_unit_price(25.0, '30.00'), '25.00');
t('単価：個別なしは組織共通', P::resolve_unit_price(0.0, '30'), '30.00');
t('単価：どちらもなし', P::resolve_unit_price(0.0, null), '0.00');
t('単価：組織共通が0', P::resolve_unit_price(0.0, '0.00'), '0.00');

$n = P::new_record(5, '2026-10-01', 'full', 12.4, '27.00', 'floor');
t('新規：往復距離を保存', $n['distance_km'], '24.80');
t('新規：金額（24km×27）', $n['amount'], 648);
t('新規：事業は未設定', $n['business_id'], null);
t('新規：自動生成フラグ・往復', [$n['is_auto_generated'], $n['commute_type'], $n['expense_type'], $n['is_zero_suppressed']], [1, 'round_trip', 'commute', 0]);
$z = P::new_record(5, '2026-10-01', 'zero', 12.4, '27.00', 'floor');
t('新規 0円処理', [$z['amount'], $z['is_zero_suppressed'], $z['distance_km']], [0, 1, '24.80']);
t('新規 切り上げ設定（25km×27）', P::new_record(5, '2026-10-01', 'full', 12.4, '27.00', 'ceil')['amount'], 675);
t('距離未登録は生成しない', P::new_record(5, '2026-10-01', 'full', 0.0, '27.00', 'floor'), null);
t('生成しない判定', P::new_record(5, '2026-10-01', 'none', 12.4, '27.00', 'floor'), null);
t('単価なしは0円で生成', P::new_record(5, '2026-10-01', 'full', 12.4, '0.00', 'floor')['amount'], 0);

$ex = fn($amt, $z, $ct = 'round_trip') => ['amount' => $amt, 'is_zero_suppressed' => $z, 'distance_km' => '24.80', 'commute_type' => $ct, 'unit_price' => '27.00'];
t('既存・通常のまま全額 → 触らない', P::reconcile($ex(648, 0), 'full', 'floor'), null);
t('既存・スタッフが片道に変更済み → 触らない', P::reconcile($ex(324, 0, 'one_way'), 'full', 'floor'), null);
t('既存・欠勤判定になっても触らない', P::reconcile($ex(648, 0), 'none', 'floor'), null);
t('既存・有給になった → 0円', P::reconcile($ex(648, 0), 'zero', 'floor'), ['amount' => 0, 'is_zero_suppressed' => 1]);
t('既存・すでに0円 → 触らない', P::reconcile($ex(0, 1), 'zero', 'floor'), null);
t('既存・有給が取り消された → 計算し直す', P::reconcile($ex(0, 1), 'full', 'floor'), ['amount' => 648, 'is_zero_suppressed' => 0]);
t('既存・0円で片道のまま戻す', P::reconcile($ex(0, 1, 'one_way'), 'full', 'floor'), ['amount' => 324, 'is_zero_suppressed' => 0]);
t('既存・0円で打刻なしに戻った → そのまま', P::reconcile($ex(0, 1), 'none', 'floor'), null);
echo $f ? "FAILED $f\n" : "ALL PASS\n"; exit($f ? 1 : 0);
