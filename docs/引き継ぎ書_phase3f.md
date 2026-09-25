# IMS Hirosaki 実装 引き継ぎ書（Phase 3f 完了 / 月次締め・提出・承認フロー）

- 対象プラグイン：`ims-portal`
- バージョン：**0.8.1-phase2i2**（DB_VERSION = **11**）
- 作成日：2026-09-25
- 前提：前回の引き継ぎ書は `引き継ぎ書_phase2c.md`（Phase 2c時点）。それ以降、**モジュール02（打刻）を2iまで完成**、**モジュール03（勤怠）を3fまで完成**させ、**両方とも実機確認済み**。

---

## 0. このドキュメントの使い方（新しいチャットで最初に読む）

1. 現在地＝**モジュール01・02・03がすべて実機確認済みで完成**（02は2iまで、03は3fまで）。
2. ソースコードは GitHub `ims-hirosaki/ims-portal`。開発は機能ブランチ（例：`claude/phase3a-handoff-review-s2umni`）で行い、
   **PRは外部の自動化によりほぼ即座に `main` へ自動マージされる**（このセッションでは手動マージ操作をしていない）。
   `main` への push（＝マージ）で GitHub Actions が自動デプロイする。
3. 要件定義書（`00`〜`08`）が**常にコードより優先される正本**。ただし**実装時に要件定義書のDDL通りでは動かなかった箇所**が複数あり、
   `CLAUDE.md`（プロジェクトルートのAI向け規約）の「実装済みコードの作法」節に集約されている。**必ず読むこと。**
4. 次にやることは §8 を参照（**3g：月次データスナップショット** が本命候補。他の選択肢もあり）。
5. 打刻管理・勤怠管理の両モジュールとも、**要件定義書に無い追加機能**（打刻の丸め・追加・取り消し、締め日連動の対象期間、デフォルト事業自動割り当て要件）を
   ユーザー確認の上で実装・追記している。§5・§8で詳述。

---

## 1. プロジェクト概要

- 日本の組織向け社内業務グループウェア「IMS Hirosaki」。WordPress プラグイン群として構築。
- 構成モジュール：
  - **00** ポータルダッシュボード ✅
  - **01** 社員管理 ✅ 完成
  - **02** 打刻ツール ✅ **完成（2iまで）**
  - **03** 勤怠管理 ✅ **完成（3fまで）** ← 保留：3g・週次残業集計
  - **04** 交通費・車両借上げ ── 未着手
  - **05** 稟議・承認ワークフロー ── 未着手
  - **06** Google Workspace 連携 ── 未着手（Tier 1のOAuthログインのみ01で実装済み）

---

## 2. 確定済みアーキテクチャ方針（`08_implementation_guidelines.md` 準拠。Phase 2c版からの差分のみ）

Phase 2c版（`引き継ぎ書_phase2c.md` §2）の内容は変更なし。今回のセッションで新たに確立した方針：

- **`ims_register_raw_migrations` フィルタを新設**（`Core\Installer`）。dbDelta は新規テーブル・新規カラムの追加には確実だが、
  **既存カラムの属性変更（型はそのままでNULL制約だけ変える等）を確実に反映しない既知の制限**がある（実機で確認）。
  dbDeltaで対応できない変更は、このフィルタで生の `ALTER TABLE` 文を寄与させ、`Installer::run_migrations()` が直接実行する。
  何度実行しても安全な内容にすること（`Module\Timecard\Schema::contribute_raw_migrations()` が実例）。
- **`Installer::run_migrations()` は個々の `dbDelta()`/クエリの成否を見ずに `ims_db_version` を更新してしまう。**
  つまり **CREATE TABLE が構文エラーで失敗しても `ims_db_version` は上がる**（=「成功したように見える」）。
  DB_VERSION を上げた変更をデプロイしたら、**wp-adminを開いてオプション値を確認するだけでは不十分**。
  実際に対象テーブル・カラムがサーバーログ上でエラー無く作成/変更されたか、可能なら phpMyAdmin 等で確認すること。
- **打刻の変更系フックが4種になった**（`ims_timecard_clocked_out` / `ims_timecard_punch_corrected` /
  `ims_timecard_punch_added` / `ims_timecard_punch_voided`）。すべて `Module\Attendance\Bootstrap` が
  同じ `DailyAttendanceService::recalculate()` に接続している。新しい打刻変更系機能を追加する際は、
  対応するフックを追加して `Attendance\Bootstrap` に登録するのを忘れないこと（忘れると「打刻は変わったのに
  実労働時間の再計算が走らない」バグになる。実際に2回このバグを踏んだ）。

---

## 3. 実装済みの状態

### モジュール01（社員管理）✅ 完成（変更なし）

### モジュール02（打刻ツール）✅ 完成（2a〜2i）

| スライス | 内容 | Ver |
|---|---|---|
| 2a〜2c | 打刻モジュール骨格・コンソール表示・打刻API（`引き継ぎ書_phase2c.md`参照） | 0.5.0〜0.5.2.1 |
| 2d-1/2d-2 | 休憩補完付き退勤（ケースA：休憩中の退勤／ケースB：休憩未打刻・6時間超） | 0.5.3〜0.5.3.1 |
| 2e-1/2e-2 | 打刻修正機能（`staff_correction_level`・修正履歴・整合性チェック） | 0.5.4〜0.5.4.1 |
| 2f-1/2f-2 | 社員管理＞打刻ログ照会（横断検索・管理者による修正） | 0.5.5〜0.5.5.2 |
| 2g | ダッシュボード連携（タイル・summary・ステータスカード） | 0.5.6〜0.5.6.2 |
| 2h-1/2h-2 | **打刻の追加**（存在しない打刻の新規作成。要件定義書には無い追加仕様） | 0.7.0〜0.7.1 |
| 2i-1/2i-2 | **打刻の取り消し**（誤打刻の取り消し。物理削除はせずvoided_*列で管理） | 0.8.0〜0.8.1 |
| fix | 打刻修正後にwp_daily_attendanceが再計算されない不具合／休憩中のまま修正できない不具合／時刻表示が9時間ずれる不具合／修正・追加後に実労働列が古い値のまま残る不具合 | 各種 |

全機能、実機で動作確認済み（打刻・修正・追加・取り消し・締め後ロック）。

### モジュール03（勤怠管理）✅ 完成（3a〜3f）── **今回、実機で全項目確認済み**

| スライス | 内容 | Ver |
|---|---|---|
| 3a | モジュール骨格・給与計算サイクル設定・事業マスタ | 0.6.0 |
| 3b | 日次勤怠集計（実労働・残業・深夜労働の算出） | 0.6.1 |
| 3c | 勤怠フラグ（有給・時間休等）の労働時間への影響 | 0.6.2 |
| 3d | 事業別時間割当て（4条件バリデーション） | 0.6.3 |
| 3e/3e-2 | 月次勤務表グリッド（表示→書き込み操作） | 0.6.4〜0.6.5.1 |
| （追加仕様） | 打刻の丸め設定（出退勤・休憩を丸めて勤怠管理） | 0.6.6〜0.6.6.1 |
| 3f準備 | 月次勤務表を締め日連動の対象期間に対応（`PayPeriodCalculator`） | 0.6.7 |
| 3f-1 | 月次締め・提出フロー（`wp_monthly_summary`） | 0.6.8 |
| 3f-2 | チェック者承認・差し戻し | 0.6.9 |
| 3f-3 | 最終承認・差し戻し、締めロックを実データに接続 | 0.6.10 |
| 3f-4a | 月次提出後のグリッド編集ロック | 0.6.11 |
| 3f-4b | 提出ボタン・ステータス表示（スタッフ画面） | 0.6.12 |
| 3f-4c | 管理者向け月次提出状況画面（承認・差し戻し） | 0.6.13 |
| fix | `wp_monthly_summary` が予約語（`year_month`→`target_year_month`）で作成失敗していた不具合 | 0.7.2 |

**実機確認済み（ユーザー確認・2026-09-25）：** 提出→ステータスバナー「提出済み」／提出後ロック／チェック承認／差し戻し／最終承認／締め後ロック（打刻修正・追加・取り消しがブロックされる）── **すべてOK**。

---

## 4. 現在のファイル構成（主要ディレクトリのみ）

```
ims-portal/
├── ims-portal.php                    # VERSION 0.8.1-phase2i2 / DB_VERSION 11
├── docs/                             # 要件定義書（正本）・引き継ぎ書
├── src/
│   ├── Core/
│   │   └── Installer.php             # ＋ ims_register_raw_migrations フィルタ処理
│   ├── Module/User/                  # モジュール01（変更なし。§3.3①にdefault_business_id追記のみ）
│   ├── Module/Timecard/              # モジュール02（2a〜2i完成）
│   │   ├── Schema.php                # attendance_logs（＋voided_*列）/ attendance_corrections
│   │   ├── PunchService.php          # punch / clock_out_with_break_* / correct_punch /
│   │   │                             #   add_missing_punch（2h） / void_punch（2i）
│   │   ├── Repository.php            # ＋ add_punch() / void_punch()（物理削除メソッドは置かない方針）
│   │   ├── StatusCalculator.php      # is_chronologically_consistent()を状態機械に書き直し（2i）
│   │   ├── RestController.php        # /timecard/logs/add・/timecard/logs/{id}/void 追加
│   │   ├── TimecardPage.php          # 「追加」「取り消す」ボタンを追加
│   │   └── AdminLogSearchPage.php    # 取消済みバッジ・管理者向け取消ボタン
│   └── Module/Attendance/            # モジュール03（3a〜3f完成）
│       ├── Schema.php                # businesses / daily_attendance / project_hours /
│       │                             #   monthly_summary（target_year_month列）
│       ├── PayPeriodCalculator.php   # 締め日設定に基づく対象期間の算出（純粋関数）
│       ├── WorkTimeCalculator.php    # 打刻の丸め対応版の実労働時間計算
│       ├── TimeRoundingSettings.php  # 打刻丸め設定（分単位）
│       ├── MonthlySummaryService.php # submit/check_approve/check_reject/final_approve/final_reject
│       ├── MonthlySummaryRepository.php
│       ├── AttendanceGridPage.php    # /portal/attendance/（提出ボタン・ステータスバナー）
│       └── AdminMonthlySubmissionsPage.php  # 社員管理＞月次提出状況
└── assets/
    ├── css/timecard.css / attendance-grid.css
    └── js/timecard.js / attendance-grid.js / admin-timecard-logs.js
```

---

## 5. 今回のセッションで判明した重要な落とし穴・設計判断（次の実装者が必ず知っておくべきこと）

### ① MySQLの予約語をカラム名に使ってはいけない（実機で踏んだ）
`year_month` は一見普通の識別子に見えるが、**`YEAR_MONTH` はMySQL/MariaDBの予約語**（`INTERVAL '1-2' YEAR_MONTH` のように使われる）。
`wp_monthly_summary` のCREATE TABLE文がこれで構文エラーになり、**テーブルが一度も作成されないまま3f-1〜3f-4がリリースされていた**
（§2の「dbDeltaはエラーを無視する」問題と組み合わさり、気づくのに時間がかかった）。列名は `target_year_month` に変更済み。
**新しいカラムを追加する際は、MySQL予約語一覧と照らして疑わしい名前（時刻・単位系の複合語）を避けること。**

### ② dbDeltaは既存カラムの属性変更を確実に反映しない
`original_datetime` を `NOT NULL` → `DEFAULT NULL` に変更した際、dbDeltaが検知せず本番では変更されないままだった。
**型そのものは変えず属性だけ変える変更は、dbDeltaに任せず `ims_register_raw_migrations` で明示的にALTERすること。**
逆に新規テーブル・新規カラムの追加はdbDeltaで問題なく反映される（2iの`voided_*`列追加は無事にdbDeltaで反映された）。

### ③ `current_time('timestamp')` の値をそのままJavaScriptのDateに渡してはいけない
`current_time('timestamp')` はサイトのタイムゾーンオフセットを**加算済み**の値を返す（`gmdate()`で正しい現地時刻文字列を作るためのWPの内部トリック）。
これをJSの `new Date(x*1000)` に渡すと、ブラウザ側の `getHours()` 等が**再度**ローカルタイムゾーン変換を行い、二重にずれる。
**JSへ渡す値は必ず `current_time('timestamp', true)`（真のUTCエポック）を使うこと。** DBの `punched_at`（現地時刻文字列）を
エポックに変換してJSへ渡す場合も、`strtotime()` する前に `get_gmt_from_date()` でUTC文字列に変換してから使う
（`TimecardPage::true_epoch_of()` が実装例）。

### ④ 打刻の修正・追加・取り消し後は「実労働」列を再計算せずページを再読み込みする
`worked_seconds()` の計算をJS側で二重実装したくないため、DOMの打刻セルだけを書き換える方式（当初実装）では
「実労働」列が古い値のまま残ってしまうバグを2回作った。**修正・追加・取り消しの成功後は、必ず `window.location.reload()`
でサーバー側の再計算結果をそのまま反映する**（`Module\Attendance\AttendanceGridPage` が保存後に採っている方式と統一）。

### ⑤ 打刻の追加・取り消しの権限モデルは「修正」と完全に同一
`PunchService::can_correct_punch()`（修正許可レベル×役割×対象日）をそのまま流用する。締めロック
（`MonthlyClosing::is_locked()`）も同様。「追加」「取り消し」は現状**本人のみ**（管理者が他人の分を追加・取り消す機能は未実装。
「修正」だけ管理者が他人の分を操作できる）。

### ⑥ 打刻は物理削除しない。取り消しは「voided_*列を立てるだけ」
労基法上の保持義務（§3.5・§7.5）のため、`Repository` に物理削除するメソッドは意図的に置かない方針を貫いている。
「取り消し」も `wp_attendance_logs` に `voided_at`/`voided_by`/`void_reason` を追加しただけで、行自体は残る。
本人の打刻コンソール（`logs_for_date()`/`logs_for_month()`）は取り消し済みを除外して「―」表示にするが、
管理者向け監査画面（`search_logs()`）は除外せず、取り消し済みバッジと理由を表示し続ける。

### ⑦ 締め日連動・打刻の丸めは要件定義書に無い追加仕様（ユーザー確認済み）
- `PayPeriodCalculator`：給与計算サイクル設定（5種類の締め日）に基づき、月次勤務表グリッドの対象期間を算出する純粋関数。
- `TimeRoundingSettings`/`WorkTimeCalculator`：出退勤・休憩の打刻を設定分数で丸めた上で勤怠管理の基準にする。
  打刻データ自体（丸め前）は別途保持する。

### ⑧ 「通常勤務日のデフォルト事業自動割り当て」は要件定義済み・未実装
社員ごとのデフォルト事業（`default_business_id`）を登録しておき、有給以外の通常勤務日も事業別時間を自動割り当てする機能。
**着手前に必ず `03_attendance_management.md` §3.3 の当該セクションを再確認すること**（保留理由・未決事項が書かれている）。

---

## 6. APIリファレンス（Phase 2c以降の追加分のみ。詳細は各RestController.phpのdocblock参照）

### Module\Timecard\RestController（`ims/v1/timecard/...`）
| ルート | 内容 |
|---|---|
| `POST timecard/punch/complete-break` | ケースA：休憩補完付き退勤 |
| `POST timecard/punch/clock-out-with-break` | ケースB：休憩範囲指定付き退勤 |
| `POST timecard/logs/{log_id}/correct` | 打刻修正 |
| `POST timecard/logs/add` | 打刻の追加（対象は必ずログインセッション本人） |
| `POST timecard/logs/{log_id}/void` | 打刻の取り消し |

### Module\Attendance\RestController（`ims/v1/attendance/...`）
| ルート | 内容 |
|---|---|
| `POST attendance/day/{date}/flag` | 勤怠フラグの設定 |
| `POST attendance/day/{date}/project-hours` | 事業別時間割当ての保存 |
| `POST attendance/month/{year_month}/submit` | 月次勤務表の提出（本人分のみ） |

管理画面（`社員管理＞月次提出状況`・`社員管理＞打刻ログ照会`）はREST APIを経由せず `admin-post.php` で処理する。

---

## 7. 動作確認状況

### 実機確認済み（2026-09-25、ユーザーによる確認）
- モジュール03の月次締め・提出・承認フロー：提出／提出後ロック／チェック承認／差し戻し／最終承認／締め後ロック、**全項目OK**。
- モジュール02の打刻追加・取り消し機能：実機で発見された不具合（DBマイグレーション2件・タイムゾーン・実労働列の再計算漏れ）はすべて修正・再確認済み。

### 未確認（今後の実機確認で見るとよい）
- 打刻の「追加」「取り消し」を**管理者側**（打刻ログ照会画面）から操作するケース（バックエンドの権限モデルは実装済みだが、
  管理者による実機確認はまだ）。
- 打刻の丸め設定を変更した際の実際の挙動（設定画面自体の保存確認のみで、丸め後の集計を実機で目視確認した記録が薄い）。
- 3f-2/3f-3の差し戻し→再提出のフルリスタート（`status = submitted` に戻ること）は今回の確認項目に含まれるが、念のため複数回差し戻しても壊れないかは要継続確認。

---

## 8. 次にやること

### 候補①：モジュール03の残り（3g・週次残業集計）
- **3g（月次データスナップショット）**：最終承認（`confirmed`）時に `snapshot_base_salary`／`snapshot_allowances` を
  `wp_salary_history`／`wp_user_allowances` の有効値から書き込む。§3.5参照。
- **週次法定外残業の合算**：複数月をまたいで日曜始まりの週で合算する必要があり、3b実装時から意図的に先送りしてきた
  （`total_overtime_illegal` への加算。`MonthlySummaryCalculator::aggregate()` のdocblockに経緯あり）。

### 候補②：モジュール04（交通費・車両借上げ）の新規着手
モジュール03が一区切りついたため、次のモジュールへ進む選択肢もある。着手前に `docs/04_travel_expenses.md` を精読すること。

### 他モジュール依存で保留中（CLAUDE.mdにも記載・変更禁止）
- 残業乖離アラートの検知本体 … 05モジュール実装後
- （月次締め後のロックは3f-3で実データ接続済み。解消済み）

### 新規要件のみ・未実装
- 通常勤務日のデフォルト事業自動割り当て（§5⑧参照。`03_attendance_management.md` §3.3・`01_user_management.md` §3.3①に仕様記載済み）

---

## 9. 作業の進め方（`引き継ぎ書_phase2c.md` §9を踏襲。変更なし）

1. **要件定義書を先に精読**してから実装する（コードより要件が正本）。
2. **1機能を細かくスライス**。特に書き込み・外部送信を伴う処理は単独スライスにする。バックエンド／UIも分けることが多い（例：2h-1/2h-2）。
3. 実装後は必ず **全ファイル `php -l`**、静的参照チェック（`self::`/クロスクラス呼び出し）、ロジックは**スモークテスト**を書いて通す。
4. デプロイは **commit → push（PRは自動マージされる）**。DB_VERSIONを上げたら、
   **wp-adminでオプション値を見るだけでなく、実際にテーブル/カラムが変更されたか確認する**（§2・§5①②参照。ここでの見落としが今回2つの不具合を生んだ）。
   新規URL追加時はパーマリンク再保存。
5. 既存実装を触るときは**依頼された箇所だけ**。共有CSSは画面スコープで回避。
6. 実機で不具合が見つかったら、**推測で直さず、ログ（PHPエラーログ・生の打刻ログ画面等）を見せてもらってから原因を特定する。**

---

## 10. 既知の申し送り・要確認事項

- `引き継ぎ書_phase3a.md` というファイル名がこれまでの会話で言及されたことがあるが、**Gitの履歴上は一度も存在しない**
  （`引き継ぎ書_phase2c.md` が直近の引き継ぎ書だった）。混乱の元なので、以後この `引き継ぎ書_phase3f.md` を正とする。
- `00_portal.md` 第6章に**古い青系デザイントークン**が残り、`07_design_system.md`（和紙・墨）と矛盾。未解決（Phase 2c時点から持ち越し）。
- 打刻ログ照会画面（管理者向け）の**見た目改善**はユーザーから一度指摘があったが「後回しでよい」と明示的に保留された。
- モジュール04・05・06の要件定義書（`04_travel_expenses.md`／`05_approval_workflow.md`／`06_google_workspace_integration.md`）は存在するが、実装は未着手。
