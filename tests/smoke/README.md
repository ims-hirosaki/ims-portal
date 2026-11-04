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
| `travel_expense_calculator_test.php` | 4a 交通費・車両借り上げの金額計算（`ExpenseCalculator`） |
| `travel_calc_settings_test.php` | 4b 距離の丸め設定の保存（`CalcSettings`） |
| `gasoline_price_calculator_test.php` | 4c ガソリン単価変更の入力チェックと再計算の計画（`GasolinePriceCalculator`） |
| `db/gasoline_price_test.php` | 4c 一括再計算（実DB）：確定月は不変・履歴の追記・失敗時は全体ロールバック |
| `commute_planner_test.php` | 4d 通勤費の自動生成の判定（`CommutePlanner`） |
| `db/commute_service_test.php` | 4d 通勤費の自動生成（実DB）：作成・べき等・勤怠フラグ連動・提出後ロック・編集を上書きしない |
| `travel_tab_calculator_test.php` | 4e 交通費タブの表示データ（`TravelTabCalculator`） |
| `db/travel_tab_view_test.php` | 4e 交通費タブの描画（実DB）：行・事業の色・未設定の警告・0円ラベル・集計・提出済みは作り足さない |
| `commute_edit_test.php` | 4f 事業のサジェストと通勤レコード編集の入力チェック（`BusinessSuggester`・`CommuteEditCalculator`） |
| `db/commute_edit_service_test.php` | 4f 通勤レコードの編集（実DB）：保存・他人/提出済みの拒否・事業の自動設定 |
| `db/submit_check_test.php` | 4g 月次提出時の交通費チェック（実DB。03の提出処理まで通す）：未設定でブロック・自動設定・0円処理は対象外・提出後はロック |
| `vehicle_rental_test.php` | 4h 車両借り上げの入力チェック・金額・タブの表示データ（`VehicleRentalCalculator`・`VehicleTabCalculator`） |
| `db/vehicle_rental_service_test.php` | 4h 車両借り上げ（実DB）：追加・削除・ロック・他人の分は触れない・タブの描画 |
