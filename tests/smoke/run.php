<?php
// コマンドライン専用（プラグインと一緒にサーバーへ配置されても、Webからは何もしない）
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * スモークテストをまとめて実行する（WordPress 無しで動く）。
 *
 *   php tests/smoke/run.php          … DB不要のテストだけ
 *   php tests/smoke/run.php --db     … MariaDB/MySQL を使うテストも実行（先に db/setup.php でテーブルを作る）
 *
 * 各テストは1ファイル＝1プロセスで実行する（テストごとにスタブのクラス定義が違うため）。
 * すべて成功なら終了コード0、1本でも失敗すれば1。
 */
$with_db = in_array('--db', $argv, true);
$files   = glob(__DIR__ . '/*_test.php') ?: [];
if ($with_db) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/db/setup.php'), $code);
    if ($code !== 0) {
        fwrite(STDERR, "DBの準備に失敗しました（db/setup.php）。\n");
        exit(1);
    }
    $files = array_merge($files, glob(__DIR__ . '/db/*_test.php') ?: []);
}
sort($files);

$failed = [];
foreach ($files as $file) {
    $name = substr($file, strlen(__DIR__) + 1);
    $out  = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    $ok = $code === 0 && in_array('ALL PASS', $out, true);
    $count = count(array_filter($out, static fn(string $l): bool => str_starts_with($l, 'OK  ')));
    printf("%s  %-48s %3d件\n", $ok ? 'PASS' : 'FAIL', $name, $count);
    if (!$ok) {
        $failed[] = $name;
        foreach ($out as $line) {
            if (!str_starts_with($line, 'OK  ')) {
                echo '      ', $line, "\n";
            }
        }
    }
}

echo $failed === [] ? "\nすべて成功（" . count($files) . "本）\n" : "\n失敗：" . implode('、', $failed) . "\n";
exit($failed === [] ? 0 : 1);
