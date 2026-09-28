<?php
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * DBを使うスモークテスト用に、03（勤怠）モジュールのテーブルを作り直す。
 * DDLは Module\Attendance\Schema::contribute() が返すものをそのまま流す
 * （要件定義書・実装どおりのDDLが実際のMariaDB/MySQLで通ることの確認も兼ねる）。
 *
 * 接続先は環境変数で変えられる（既定はテスト専用の localhost / wp / wp / t）：
 *   IMS_TEST_DB_HOST / IMS_TEST_DB_USER / IMS_TEST_DB_PASS / IMS_TEST_DB_NAME
 * **本番や共有のDBを指定しないこと**（テーブルを DROP して作り直す）。
 */
define('ABSPATH', '/');
class IMS_Smoke_Wpdb_For_Ddl { public $prefix = 'wp_'; public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'; } }
$wpdb = new IMS_Smoke_Wpdb_For_Ddl();
function add_filter(...$args) {}
require dirname(__DIR__, 3) . '/src/Module/Attendance/Schema.php';

$db = new mysqli(getenv('IMS_TEST_DB_HOST') ?: 'localhost', getenv('IMS_TEST_DB_USER') ?: 'wp', getenv('IMS_TEST_DB_PASS') ?: 'wp', getenv('IMS_TEST_DB_NAME') ?: 't');
$db->set_charset('utf8mb4');

foreach (IMS\Module\Attendance\Schema::contribute([]) as $ddl) {
    if (!preg_match('/CREATE TABLE (\S+)/', $ddl, $m)) {
        continue;
    }
    $db->query('DROP TABLE IF EXISTS ' . $m[1]);
    if (!$db->query($ddl)) {
        fwrite(STDERR, "テーブル作成に失敗：{$m[1]}：{$db->error}\n");
        exit(1);
    }
    echo "作成：{$m[1]}\n";
}
