<?php
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 2));
// 4c ガソリン単価変更の入力チェックと再計算の計画（04 §2.1）
require IMS_SMOKE_ROOT . '/src/Module/Travel/ExpenseCalculator.php';
require IMS_SMOKE_ROOT . '/src/Module/Travel/GasolinePriceCalculator.php';
use IMS\Module\Travel\GasolinePriceCalculator as G;
$f = 0; function t($n, $a, $e) { global $f; if ($a === $e) { echo "OK  $n\n"; } else { $f++; echo "NG  $n: " . var_export($a, true) . ' != ' . var_export($e, true) . "\n"; } }

t('単価 27', G::normalize_price('27'), '27.00');
t('単価 27.5', G::normalize_price('27.5'), '27.50');
t('単価 全角', G::normalize_price('２７．５'), null); // 小数点が全角のものは受け付けない
t('単価 全角数字のみ', G::normalize_price('２７'), '27.00');
t('単価 0', G::normalize_price('0'), null);
t('単価 負', G::normalize_price('-3'), null);
t('単価 桁あふれ', G::normalize_price('10000'), null);
t('単価 小数3桁', G::normalize_price('27.555'), null);
t('単価 文字', G::normalize_price('abc'), null);
t('単価 空', G::normalize_price(''), null);
t('適用開始月 OK', G::normalize_apply_from('2026-10'), '2026-10');
t('適用開始月 13月', G::normalize_apply_from('2026-13'), null);
t('適用開始月 形式違い', G::normalize_apply_from('2026/10'), null);
t('読み込み開始日', G::scan_start_date('2026-01'), '2025-12-01');

// カレンダー月＝月度（末日締め相当）
$label = fn(string $d) => substr($d, 0, 7);
$r = fn($id, $uid, $date, $type = 'commute', $ct = 'round_trip', $km = 24.8, $rt = 0, $z = 0) => ['id' => $id, 'user_id' => $uid, 'work_date' => $date, 'expense_type' => $type, 'commute_type' => $ct, 'distance_km' => $km, 'is_round_trip' => $rt, 'is_zero_suppressed' => $z];
$rows = [
  $r(1, 10, '2026-09-30'),                                   // 適用開始月より前 → 対象外
  $r(2, 10, '2026-10-01'),                                   // 通勤 往復 24.8km
  $r(3, 10, '2026-10-02', 'commute', 'one_way'),             // 通勤 片道
  $r(4, 10, '2026-10-03', 'vehicle_rental', null, 8.5, 1),   // 借上げ 往復
  $r(5, 10, '2026-10-04', 'commute', 'round_trip', 24.8, 0, 1), // 有給（0円処理）
  $r(6, 20, '2026-10-01'),                                   // 確定済みの社員 → 変えない
  $r(7, 20, '2026-11-01'),                                   // 同じ社員でも確定していない月は対象
];
$p = G::plan($rows, ['20|2026-10' => true], '2026-10', '30.00', 'floor', $label);
$by = []; foreach ($p['updates'] as $u) { $by[$u['id']] = $u; }
t('対象件数', count($p['updates']), 5);
t('適用開始月より前は対象外', isset($by[1]), false);
t('確定済みの月は対象外', isset($by[6]), false);
t('確定していない別の月は対象', isset($by[7]), true);
t('確定済みで飛ばした件数', $p['skipped_confirmed'], 1);
t('通勤 往復の再計算（24km×30）', $by[2]['amount'], 720);
t('通勤 片道の再計算（12km×30）', $by[3]['amount'], 360);
t('借上げ 往復の再計算（17km×30）', $by[4]['amount'], 510);
t('0円処理は0円のまま', $by[5]['amount'], 0);
t('単価が更新される', $by[2]['unit_price'], '30.00');
t('影響月', $p['months'], ['2026-10', '2026-11']);
t('影響人数', $p['users'], 2);
// 丸めモードを使う
$p2 = G::plan([$r(1, 10, '2026-10-01', 'commute', 'round_trip', 24.8)], [], '2026-10', '30.00', 'ceil', $label);
t('切り上げ設定（25km×30）', $p2['updates'][0]['amount'], 750);
// 月度が前月にずれる締め日（20日締め）：ラベルは終了日の属する月
$label20 = fn(string $d) => (int) substr($d, 8, 2) <= 20 ? substr($d, 0, 7) : gmdate('Y-m', strtotime(substr($d, 0, 7) . '-01 +1 month'));
$p3 = G::plan([$r(1, 10, '2026-09-25'), $r(2, 10, '2026-09-15')], [], '2026-10', '30.00', 'floor', $label20);
t('20日締め：9/25は10月度で対象、9/15は9月度で対象外', array_column($p3['updates'], 'id'), [1]);
t('対象なし', G::plan([], [], '2026-10', '30.00', 'floor', $label)['updates'], []);
echo $f ? "FAILED $f\n" : "ALL PASS\n"; exit($f ? 1 : 0);
