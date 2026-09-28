# スモークテスト

WordPress 無しで動く、判定ロジック・画面描画のスモークテスト（CLAUDE.md「完了前に必ずやること」2.）。

```bash
php tests/smoke/run.php          # DB不要のテストだけ
php tests/smoke/run.php --db     # MariaDB/MySQL を使うテストも（db/setup.php がテーブルを作り直す）
php tests/smoke/weekly_overtime_test.php   # 1本だけ実行
```

- 1ファイル＝1テスト。WordPress の関数・他クラスはファイルの中でスタブにしている。成功すると最後に `ALL PASS` を出し、終了コード0。
- `db/` のテストは本物の MariaDB/MySQL に接続する（`db/miniwpdb.php` が最小限の `$wpdb` 互換）。
  接続先は環境変数 `IMS_TEST_DB_HOST` / `IMS_TEST_DB_USER` / `IMS_TEST_DB_PASS` / `IMS_TEST_DB_NAME`（既定 `localhost` / `wp` / `wp` / `t`）。
  **テーブルを DROP して作り直すので、本番や共有のDBを指定しないこと。**
- `tests/` はデプロイ（FTP）でサーバーにも置かれる（`.github/` の除外設定は変えられないため）。
  そのため、すべてのファイルは先頭で「コマンドライン以外なら何もせず終了」し、`tests/.htaccess` で Web からのアクセスを拒否している。
  **新しいテストを足すときも、必ず先頭にこの2行を入れること。**
- MariaDB と本物の WordPress で確認する手順は `docs/引き継ぎ書_phase3p.md` §6。

## テスト一覧
| ファイル | 対象 |
|---|---|
| `salary_snapshot_test.php` | 3g 給与スナップショット（`SalarySnapshotCalculator`） |
| `weekly_overtime_test.php` | 3h 週次法定外残業（`WeeklyOvertimeCalculator`・`MonthlySummaryCalculator::aggregate()`） |
| `work_days_test.php` | 出勤日数（`MonthlySummaryCalculator::is_work_day()`） |
| `yayoi_csv_builder_test.php` / `yayoi_csv_export_test.php` | 3i 弥生CSV |
| `admin_attendance_grid_test.php` | 3j 勤務表の確認（権限・色分け・タブ・取り消し履歴） |
| `attendance_print_test.php` | 3k 印刷用勤務表（事業別明細・権限） |
| `monthly_statement_test.php` / `staff_tabs_test.php` | 3l 集計表タブ |
| `submission_reminder_test.php` / `dashboard_tile_test.php` / `tile_badge_test.php` | 3m・3p-3 タイルと未提出バッジ |
| `confirmation_cancel_settings_test.php` / `db/confirmation_cancel_test.php` / `db/confirmation_cancel_ui_test.php` | 3n 確定の取り消し |
| `admin_access_policy_test.php` | 3p-1 wp-admin の限定開放（`AdminAccessPolicy`） |
