<?php
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 2));
define('ABSPATH', '/');
require IMS_SMOKE_ROOT . '/src/Module/Attendance/AutoAllocationCalculator.php';
use IMS\Module\Attendance\AutoAllocationCalculator as C;
$f = 0; function t($n, $a, $e) { global $f; if ($a === $e) { echo "OK  $n\n"; } else { $f++; echo "NG  $n: " . var_export($a, true) . ' != ' . var_export($e, true) . "\n"; } }
$r = fn(int $b, int $s, int $e) => ['business_id' => $b, 'start_minutes' => $s, 'end_minutes' => $e, 'is_auto_assigned' => true];

// 有給日（3q-2）
t('有給：8:30から8時間', C::paid_leave_rows(1, 510, 480), [$r(1, 510, 990)]);
t('有給：所定7.5時間', C::paid_leave_rows(2, 510, 450), [$r(2, 510, 960)]);
t('有給：割当先の事業なし', C::paid_leave_rows(null, 510, 480), []);
t('有給：所定0時間', C::paid_leave_rows(1, 510, 0), []);

// 通常勤務日（3q-4）
t('通常：休憩なし', C::default_business_rows(3, 510, 1050, []), [$r(3, 510, 1050)]);
t('通常：休憩1回で2行', C::default_business_rows(3, 510, 1050, [[720, 780]]), [$r(3, 510, 720), $r(3, 780, 1050)]);
t('通常：休憩2回で3行（順不同でも並べ替え）', C::default_business_rows(3, 510, 1080, [[900, 915], [720, 780]]), [$r(3, 510, 720), $r(3, 780, 900), $r(3, 915, 1080)]);
t('通常：出勤直後の休憩', C::default_business_rows(3, 510, 1050, [[510, 540]]), [$r(3, 540, 1050)]);
t('通常：退勤直前の休憩', C::default_business_rows(3, 510, 1050, [[1020, 1050]]), [$r(3, 510, 1020)]);
t('通常：出退勤の外にはみ出した休憩は切り詰める', C::default_business_rows(3, 510, 1050, [[480, 540], [1040, 1100]]), [$r(3, 540, 1040)]);
t('通常：重なった休憩', C::default_business_rows(3, 510, 1050, [[720, 780], [750, 800]]), [$r(3, 510, 720), $r(3, 800, 1050)]);
t('通常：日をまたぐ勤務（26:00）', C::default_business_rows(3, 1320, 1560, [[1440, 1470]]), [$r(3, 1320, 1440), $r(3, 1470, 1560)]);
t('通常：デフォルト事業なし', C::default_business_rows(null, 510, 1050, []), []);
t('通常：出退勤が逆', C::default_business_rows(3, 1050, 510, []), []);

// 上書きしてよいか
t('割り当てなし → 置き換え可', C::can_replace([]), true);
t('自動の行だけ → 置き換え可', C::can_replace([['is_auto_assigned' => '1'], ['is_auto_assigned' => 1]]), true);
t('手入力の行あり → 不可', C::can_replace([['is_auto_assigned' => '1'], ['is_auto_assigned' => '0']]), false);
t('有給を外した：自動の行だけ → 取り除く', C::should_clear_on_flag_off([['is_auto_assigned' => '1']]), true);
t('有給を外した：手入力あり → 残す', C::should_clear_on_flag_off([['is_auto_assigned' => '0']]), false);
t('有給を外した：行なし → 何もしない', C::should_clear_on_flag_off([]), false);
echo $f ? "FAILED $f\n" : "ALL PASS\n"; exit($f ? 1 : 0);
