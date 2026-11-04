<?php
namespace {
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IMS_SMOKE_ROOT', dirname(__DIR__, 3));
}
namespace IMS\Support {
  final class Capabilities { public static function can_manage_masters(): bool { return true; } public static function can_run_closing(): bool { return true; } public static function can_manage_users(): bool { return true; } public static function can_approve(): bool { return true; } }
  final class UserRepository {
    public static function get_commute_one_way_km(int $u): float { return 12.4; } public static function get_travel_unit_price(int $u): float { return 27.0; }
    public static function get_first_approver_id(int $u): ?int { return null; } public static function get_scheduled_work_hours(int $u): float { return 8.0; }
  }
}
namespace IMS\Module\Attendance {
  final class AttendanceGridService { public static function month_data(int $u, string $ym): array { return ['days' => ['2026-10-03' => ['attendance_flag' => 'paid_leave']]]; } }
}
namespace {
// 4e 交通費タブの描画（実DB）：行・事業の色・未設定の警告・0円ラベル・集計・他人のデータは出ない
define('ABSPATH','/'); define('ARRAY_A','ARRAY_A');
require __DIR__.'/miniwpdb.php'; $wpdb=new \MiniWpdb;
$OPT=[]; function get_option($k,$d=false){ global $OPT; return $OPT[$k]??$d; } function update_option($k,$v,$a=null){ global $OPT; $OPT[$k]=$v; }
function esc_html($s){ return htmlspecialchars((string)$s,ENT_QUOTES); } function esc_attr($s){ return esc_html($s); }
function current_time($f){ return '2026-10-05 10:00:00'; } function add_filter(...$a){}
spl_autoload_register(function($c){ if(str_starts_with($c,'IMS\\')){ $p=IMS_SMOKE_ROOT.'/src/'.str_replace('\\','/',substr($c,4)).'.php'; if(is_readable($p)) require $p; } });
use IMS\Module\Travel\TravelTabView as V;
$f=0; function t($n,$a,$e){global $f; if($a===$e){echo "OK  $n\n";}else{$f++;echo "NG  $n: ".var_export($a,true)." != ".var_export($e,true)."\n";}}
foreach (['travel_expenses','monthly_summary','daily_attendance','attendance_logs','businesses'] as $t) $wpdb->query("DELETE FROM wp_$t");
$wpdb->insert('wp_businesses',['business_code'=>'a','business_name'=>'本社業務','color_code'=>'#1E3A5F','is_active'=>1,'sort_order'=>1]);
$bid=(int)$wpdb->get_var('SELECT business_id FROM wp_businesses LIMIT 1');
$wpdb->insert('wp_attendance_logs',['user_id'=>1,'work_date'=>'2026-10-01','punch_type'=>'clock_in','punched_at'=>'2026-10-01 09:00:00']);
$wpdb->insert('wp_attendance_logs',['user_id'=>1,'work_date'=>'2026-10-02','punch_type'=>'clock_in','punched_at'=>'2026-10-02 09:00:00']);
$wpdb->insert('wp_daily_attendance',['user_id'=>1,'work_date'=>'2026-10-03','attendance_flag'=>'paid_leave','scheduled_minutes'=>480]);
$wpdb->insert('wp_attendance_logs',['user_id'=>2,'work_date'=>'2026-10-01','punch_type'=>'clock_in','punched_at'=>'2026-10-01 09:00:00']);

ob_start(); V::render(1,'2026-10','2026-10-01','2026-10-05'); $h=ob_get_clean();   // 開くと通勤費が作られる
t('表示と同時に通勤費が作られる（社員1の3日分）', (int)$wpdb->get_var('SELECT COUNT(*) FROM wp_travel_expenses WHERE user_id=1'), 3);
t('他の社員のぶんは作られない', (int)$wpdb->get_var('SELECT COUNT(*) FROM wp_travel_expenses WHERE user_id=2'), 0);
t('期間の5日ぶんの行', substr_count($h,'<tr class="tv-row'), 5);
t('金額（24km×27=648円）', str_contains($h,'648円'), true);
t('有給の0円ラベル', str_contains($h,'※有給（0円処理）'), true);
t('事業未設定の警告（打刻した2日）', [str_contains($h,'事業が未設定の日が3日'), str_contains($h,'⚠ 未設定')], [true,true]);
t('合計（648×2）', str_contains($h,'1,296円'), true);

// 事業を設定すると色と事業名が出る
$wpdb->query("UPDATE wp_travel_expenses SET business_id=$bid WHERE user_id=1 AND work_date='2026-10-01'");
ob_start(); V::render(1,'2026-10','2026-10-01','2026-10-05'); $h=ob_get_clean();
t('事業の色と名前', [str_contains($h,'background:#1E3A5F'), str_contains($h,'本社業務')], [true,true]);
t('未設定が1日減る', str_contains($h,'事業が未設定の日が2日'), true);

// 編集できる月はプルダウンが出る。複数事業の日は最多の事業に「（候補）」が付く
$wpdb->insert('wp_businesses',['business_code'=>'b','business_name'=>'氷河期事業','color_code'=>'#2E7D32','is_active'=>1,'sort_order'=>2]);
$bid2=(int)$wpdb->get_var('SELECT MAX(business_id) FROM wp_businesses');
$wpdb->insert('wp_project_hours',['user_id'=>1,'work_date'=>'2026-10-02','business_id'=>$bid,'start_time'=>'09:00:00','end_time'=>'11:00:00','minutes'=>120]);
$wpdb->insert('wp_project_hours',['user_id'=>1,'work_date'=>'2026-10-02','business_id'=>$bid2,'start_time'=>'11:00:00','end_time'=>'17:00:00','minutes'=>360]);
ob_start(); V::render(1,'2026-10','2026-10-01','2026-10-05'); $h=ob_get_clean();
t('事業のプルダウンがある', str_contains($h,'data-field="business_id"'), true);
t('往復/片道のプルダウンがある（0円の日を除く）', substr_count($h,'data-field="commute_type"'), 2);
t('最多の事業に候補の印', str_contains($h,'氷河期事業（候補）'), true);
t('候補は自動では設定されない', $wpdb->get_var("SELECT business_id FROM wp_travel_expenses WHERE user_id=1 AND work_date='2026-10-02'"), null);
t('未設定の日は「未設定（要選択）」から始まる', str_contains($h,'⚠ 未設定（要選択）'), true);

// 提出済みの月は作り足さない
$wpdb->insert('wp_monthly_summary',['user_id'=>1,'target_year_month'=>'2026-10','status'=>'submitted']);
$wpdb->insert('wp_attendance_logs',['user_id'=>1,'work_date'=>'2026-10-04','punch_type'=>'clock_in','punched_at'=>'2026-10-04 09:00:00']);
ob_start(); V::render(1,'2026-10','2026-10-01','2026-10-05'); ob_get_clean();
ob_start(); V::render(1,'2026-10','2026-10-01','2026-10-05'); $h=ob_get_clean();
t('提出済みの月はプルダウンが出ない', [str_contains($h,'<select'), str_contains($h,'本社業務')], [false,true]);
t('提出済みの月は作り足さない', (int)$wpdb->get_var('SELECT COUNT(*) FROM wp_travel_expenses WHERE user_id=1'), 3);

// HTMLエスケープ
$wpdb->query("UPDATE wp_businesses SET business_name='<b>x</b>'");
ob_start(); V::render(1,'2026-10','2026-10-01','2026-10-05'); $h=ob_get_clean();
t('事業名はエスケープされる', [str_contains($h,'<b>x</b>'), str_contains($h,'&lt;b&gt;x&lt;/b&gt;')], [false,true]);
echo $f?"FAILED $f\n":"ALL PASS\n"; exit($f?1:0);
}
