# IMS Hirosaki 実装 引き継ぎ書（Phase 2b 完了 / 打刻コンソール表示まで）
 
- 対象プラグイン：`ims-portal`
- バージョン：**0.5.1-phase2b**（DB_VERSION = 3）
- 作成日：2026-08-06
- 前提：Phase 1d（モジュール01 完成 / 0.4.7）の続き。前回の引き継ぎ書は `引き継ぎ書_phase1d.md`
---
 
## 0. このドキュメントの使い方（新しいチャットで最初に読む）
 
1. この引き継ぎ書を読み、**現在地＝モジュール02（打刻ツール）の 2b まで完了**であることを把握する。
   打刻コンソールは「表示のみ」実装済み。**打刻の記録機能（書き込み）はまだ無い**（次は 2c）。
2. ソースコードは GitHub `git-ims-shiroto/ims-portal`（main）から取得する。
   - **デプロイ方式が変わった**：`main` への push で GitHub Actions が自動デプロイする（§5 参照）。
     このため GitHub の main = 本番、で一致しているのが通常状態。
3. プロジェクトナレッジの要件定義書（`00`〜`08`）は**常にコードより優先される正本**。実装前に該当章を必ず読む。
   - モジュール02 の正本は `02_time_tracking.md`。
4. 次にやることは §8 を参照（**2c：打刻API**）。
---
 
## 1. プロジェクト概要
 
- 日本の組織向け社内業務グループウェア「IMS Hirosaki」。WordPress プラグイン群として構築。
- 構成モジュール：
  - **00** ポータルダッシュボード
  - **01** 社員管理 ✅ 完成
  - **02** 打刻ツール ← **いまここ（2b まで完了）**
  - **03** 勤怠管理
  - **04** 交通費・車両借上げ
  - **05** 稟議・承認ワークフロー
  - **06** Google Workspace 連携
- 開発者＝将来の利用者（一般社員）。日常利用は Google SSO の `general_staff`、システム管理は break-glass の `administrator`。
---
 
## 2. 確定済みアーキテクチャ方針（詳細は 08_implementation_guidelines.md）
 
- **モジュラーモノリス**：単一プラグイン内にモジュールディレクトリ。分割しない。
- 業務ロジックは**すべてプラグイン側**。テーマには置かない。
- トランザクションデータは**独自テーブル**。カスタム投稿タイプは使わない。
- **Composer 不要の軽量 PSR-4 オートローダー**。
- コアはモジュールに依存しない。モジュール側が**フック経由で自己登録**する：
  - `ims_portal_register_page` … ポータル画面の登録
  - `ims_portal_register_tile` … ダッシュボードタイルの登録
  - `ims_portal_summary_contribute` … サマリー寄与
  - `ims_register_schema` … テーブル定義の寄与
  - `ims_portal_dashboard_top` … ダッシュボード最上部への寄与（ランチャーが使用）
### 実装で判明したコード規約（08 の記載と実装が食い違う点。**実装に合わせること**）
 
- **`Router::register()` はスラッグ文字列のみ**を取る（例：`'timecard'`）。フックに渡ってくるのは
  インスタンスではなく**クラス名の文字列**（`$router_class::register(...)`）。08 §6.1 のサンプルとは異なる。
- **`TileRegistry::add()` の `caps` は capability 名**（`ims_use_portal` など）。ロール名ではない。
- コアの `Assets` は**モジュール固有CSS/JSを読み込まない**。各モジュールが `wp_enqueue_scripts` で
  自前 enqueue する（打刻コンソールでは `get_query_var('ims_portal_page')` で対象ページを判定して積む）。
- **`ims-portal.php` の `boot()` が各モジュールの `Bootstrap::init()` を直接呼ぶ**。コア無改修の原則の
  唯一の例外。02 も同じ流儀で `\IMS\Module\Timecard\Bootstrap::init();` を1行追加している。
- **REST エンドポイントは 0.5.1 時点でまだゼロ**。`ims/v1` は土台のみ。**2c が初の REST 利用者**になる。
---
 
## 3. 実装済みの状態
 
### モジュール01（社員管理）✅ 完成（0.4.7 / Phase 1d 時点）
Phase 0 基盤／1a マスタ9テーブル／1b 社員一覧・編集（給与保護）／1c 認証二方式並立／
1d マイページ・CSV入出力・ランチャー管理・退職者管理。詳細は前回の引き継ぎ書を参照。
 
### モジュール02（打刻ツール）── 2a・2b 完了
 
| スライス | 内容 | Ver |
|---|---|---|
| **2a** | 打刻モジュール骨格。`attendance_logs` / `attendance_corrections` の2テーブル追加（**DB_VERSION 2→3**）。ポータル設定に「打刻システム設定」画面（深夜勤務の日付境界／スタッフ打刻修正レベル／残業アラート通知先の3セクション）。Google Chat Webhook 通知（`ChatNotifier`、URL暗号化保存・WP-Cronリトライ最大3回・テスト送信） | 0.5.0 |
| **2b** | 打刻コンソール `/portal/timecard/`（**表示のみ・書き込みなし**）。現在ステータス／実勤務時間（経過）／当月打刻履歴／月送り。`StatusCalculator`（状態判定・実労働時間算出の純粋ロジック）、`Repository`（読み取り専用）。**打刻ボタンは配置のみで押下は「準備中」トースト** | 0.5.1 |
 
#### ✅ 実機で動作確認済み
- 2a：打刻システム設定画面（3セクションの保存・表示、テーブル2件の作成）
#### ⚠ 未確認（次チャットの冒頭で確認するとよい）
- **2b の実機表示**（`/portal/timecard/` が開くか、状態バッジ・履歴テーブル・月送り・時計）。
  ※この引き継ぎ書作成時点では 2b をコミット/デプロイした直後で、実機確認は未了。
- **Webhook のテスト送信**（Google Chat ルームへの実送信。2a で実装済みだが疎通は未確認）。
  ここは 2f 以降で乖離アラートを繋ぐ前に一度通しておきたい。
---
 
## 4. 現在のファイル構成（0.5.1）
 
```
ims-portal/
├── ims-portal.php                    # VERSION 0.5.1 / DB_VERSION 3 / Timecard\Bootstrap::init() 追加済み
├── composer.json / README.md
├── .github/workflows/                # ★ 自動デプロイ設定。ZIP に含めない・上書きしない
├── assets/
│   ├── css/ ims-tokens.css / portal.css / admin.css /
│   │        launcher-admin.css? / timecard-admin.css / timecard.css   ← 下2つが 02 で追加
│   └── js/  portal.js / admin.js / launcher-admin.js /
│            timecard-admin.js / timecard.js                            ← 下2つが 02 で追加
├── templates/ header.php / footer.php / 404.php
└── src/
    ├── Core/      Assets / AuthGuard / DashboardPage / EnvironmentCheck / Installer /
    │              Layout / LoginPagePlaceholder(※未使用) / Roles / Router /
    │              SummaryAggregator / TileRegistry
    ├── Support/   Capabilities / Crypto / UserRepository /
    │              Google/OAuthClient / Google/ChatNotifier   ← ChatNotifier が 2a で追加
    ├── Module/User/  … モジュール01（前回引き継ぎ書のとおり。変更なし）
    └── Module/Timecard/                                       ← 02 新規ディレクトリ
        ├── Bootstrap.php             # モジュール02の初期化ハブ
        ├── Schema.php                # attendance_logs / attendance_corrections を ims_register_schema に寄与
        ├── Settings.php              # 打刻システム設定の読み書き・既定値・正規化（2a）
        ├── AdminSettingsPage.php     # ポータル設定＞打刻システム設定（2a）
        ├── StatusCalculator.php      # 状態判定・実労働時間算出の純粋ロジック（2b）
        ├── Repository.php            # 打刻ログの読み取り（2b・参照のみ）
        └── TimecardPage.php          # /portal/timecard/ コンソール画面（2b）
```
 
### 独自テーブル（02 で追加した2つ。すべて `{$wpdb->prefix}` 接頭辞）
 
- **`attendance_logs`**（11列）：打刻ログ本体。
  `log_id / user_id / work_date / punch_type(enum: clock_in,break_in,break_out,clock_out) /
  punched_at / is_auto_filled / ip_address / gps_latitude / gps_longitude / note / created_at`
  - `work_date` は「帰属日付」。退勤打刻のみ深夜境界（Settings の date_boundary_hour）で前日になり得る。
  - clock_in / clock_out の「1日1件」制約は**アプリ層で担保**（DBのUNIQUEは張っていない）。break は複数可。
  - **物理削除しない**（監査要件 §7.5）。
  - INDEX：`(user_id, work_date)` と `(punched_at)`。
- **`attendance_corrections`**（7列）：打刻修正の監査証跡。削除不可・修正のたびに追記。
  `id / log_id / original_datetime / corrected_datetime / reason / corrected_by / corrected_at`
> DDL は `Module/Timecard/Schema.php` が唯一の正本。カラム名・型は要件定義書 §5 と一致確認済み。
> テーブル物理名は `Schema::logs_table()` / `Schema::corrections_table()` で取得する。
 
### 設定値（wp_options `ims_timecard_settings` に単一配列で保存。`Settings.php` が正本）
 
- `date_boundary_hour`（0〜23・既定0）… 深夜勤務の日付境界。**退勤打刻の work_date 判定にのみ**使う。
- `staff_correction_level`（`disabled` / `today_only` / `pre_closing`・既定 pre_closing）… スタッフ自身の打刻修正の許可範囲。
- `chat_webhook_enc`（暗号化して格納）… Google Chat Webhook URL。`Crypto` で暗号化。空欄保存＝既存維持、明示削除は `chat_webhook_clear`。
- `alert_threshold_min`（分・既定30）… 残業乖離アラートの閾値。
### カスタム権限（02 での追加・変更なし。既存を利用）
- 打刻システム設定 → `ims_manage_system`（administrator のみ）
- 打刻コンソール `/portal/timecard/` の利用 → `ims_use_portal`（一般社員含む全員）
---
 
## 5. テスト環境・デプロイ（★方式が変わった）
 
- テストサイト：`portal-site.labs-ims.com`（Xserver、WordPress / PHP 8.3）
- リポジトリ：`git-ims-shiroto/ims-portal`（**Private**。1d 時点で公開→非公開に変更済み）
### ★ 新しいデプロイ方式：GitHub Actions（push で自動デプロイ）
 
- **`main` への push で自動デプロイ**される（`.github/workflows/` に設定あり）。
  → 従来の「FTP 手動アップロード＋停止/有効化」は**もう不要**。
- コミット規約：`feat(timecard): 日本語説明 (Phase 2x)` 形式。2スライス配信時は2コミットに分ける。
- **`.github/` はリポジトリ管理下**。ZIP や差分に**絶対に含めない／上書きしない**こと。
  （このプロジェクトでは「フォルダの中身だけをマージ、フォルダごと Replace はしない」方針なので、通常は巻き込まれない。）
### ⚠ デプロイに関する注意（DB変更がある場合）
 
- 「停止→有効化」を挟まなくなったので、**テーブル作成・スキーマ変更は `Installer::maybe_upgrade()`
  （`admin_init` で `保存 ims_db_version < 定数` のとき実行）に依存**する。
  → push 後、**管理者が一度 wp-admin を開けば自動でマイグレーションが走る**設計。
  → DB_VERSION を上げたスライスをデプロイしたら、**wp-admin を開いて `ims_options` の
    `ims_db_version` が上がっているか確認する**この1ステップだけ残す。
- 新規URL（ページ）を追加したスライスでは、**パーマリンク再保存**（設定＞パーマリンクで変更せず保存1回）で
  rewrite rule を確実に反映する。2b の `/portal/timecard/` 追加時はこれが必要だった。
### コミット作者について
- コミット author は `git-ims-admin` のまま（GitHub 表示もこの名前になる）。動作に支障なし。
  気になる場合のみ、リポジトリで `git config user.name/user.email` を `git-ims-shiroto` に変更する。
---
 
## 6. この会話で決めた重要事項（モジュール02）
 
- **打刻ボタンは 2b で「配置＋準備中トースト」**、状態に応じた活性/非活性の出し分けは実装済み。
  2c で `fetch` の中身を差すだけで完成する状態にしてある。
- **状態判定と実労働時間は `StatusCalculator` に純粋関数として集約**。DB/WPに触れずテスト可能。
  打刻API（2c）側の「その打刻は今の状態で許されるか」の検証も**この同じロジックを再利用**し、二重実装を避ける。
- **残業乖離アラートの検知本体は 2a では未実装**。比較対象の `overtime_end_time` が **05_稟議モジュール側の
  テーブルにあり、まだ存在しない**ため。2a では設定欄と `ChatNotifier`（テスト送信可）まで作り、
  検知ロジックは 05 実装時に接続する。2c で `clock_out` 成功時に**アクションフックを撃っておけば**
  05 側から無改修で拾える設計にする予定。
- **締め後ロック（§3.4）も同様に保留**。判定対象の `wp_monthly_summary` が **03_勤怠モジュール側**で未作成。
  判定を1メソッドに閉じ込め、テーブル不在時は「ロックなし」を返す実装にする方針。
- **Webhook URL は暗号化保存**（`Crypto`）。ルームへ投稿できる実質的な認証情報のため。
  形式検証は `https://chat.googleapis.com` に固定し、取り違えで社内情報が外部へ飛ぶ事故を防ぐ。
- **リトライは `sleep()` せず WP-Cron の単発イベント**（30秒→60秒、最大3回）。打刻APIのレスポンス内で待たせない。
- **主キーは仕様の INT ではなく `bigint(20) unsigned`** を採用（5年保持・全社展開の余裕、既存カラムとの型統一）。
- 打刻システム設定のメニューは要件の「設定＞」ではなく**「ポータル設定＞」配下**に置いた
  （既存の認証設定・外部ツール設定と揃えるため）。
- 共有CSS（`admin.css` / `portal.css`）は直接変更せず、**画面スコープ付きCSS**で対処（`.ims-tc-settings` / `.tc-wrap`）。
---
 
## 7. 動作確認できていること / まだのこと
 
### ✅ 実機で動作確認済み
- 2a：打刻システム設定（3セクションの保存・再表示、テーブル2件の作成）
### ⚠ 未確認・要確認
- **2b の打刻コンソール表示**（`/portal/timecard/`）。次チャット冒頭で確認するとよい。
  データ空だと「出勤前」表示になる。状態遷移を見たい場合は phpMyAdmin で `attendance_logs` に手動INSERT
  → 再読込 → 確認後に削除（2b 手順書 ⑤ 参照）。
- **Webhook テスト送信**の実疎通（Google Chat ルームへの到達）。
- （モジュール01 から継続）復職ボタンの nonce 修正の実機確認、退職者ログイン遮断、仮パスワードメール到達（SMTP）、
  ランチャーの `msteams://` 起動・ドラッグ並べ替え永続化・スマホ幅非表示。
---
 
## 8. 次にやること
 
### 推奨する次の着手先：**モジュール02 の 2c（打刻API）**
 
初の書き込み処理・初の REST エンドポイント。重点：
- REST `ims/v1/timecard/punch`（4アクション：clock_in / break_in / break_out / clock_out）
- **サーバー時刻の強制**（クライアントの時刻は信用しない。§3.1「サーバーサイド時刻の強制」）
- **5秒重複ガード**（連打・二重送信の抑止）
- **退職者拒否**（`employment_status = 退職` はサーバー側でも打刻を拒否。§6.1）
- **状態遷移の妥当性チェック**（`StatusCalculator` を再利用。例：出勤前に休憩開始は不可）
- IP アドレス／GPS（オプトイン）の記録
- `clock_out` 成功時に**アクションフック**（例：`ims_timecard_clocked_out`）を撃つ（05 の乖離検知の受け口）
### 2c 以降のスライス計画（2a 着手時に立てた分割）
| # | 内容 | リスク | 想定Ver |
|---|---|---|---|
| 2c | 打刻API（4アクション・サーバー時刻・重複ガード・退職者拒否） | **高** | 0.5.2 |
| 2d | 退勤補完ロジック（ケースA 休憩中退勤／ケースB 6時間超）。2レコードのトランザクション保存 | **高** | 0.5.3 |
| 2e | 打刻修正（`staff_correction_level` の3レベル制御・修正履歴記録・整合性チェック） | **高** | 0.5.4 |
| 2f | wp-admin 社員管理＞打刻ログ照会（検索・展開・管理者修正） | 中 | 0.5.5 |
| 2g | ダッシュボード連携（タイル登録・summary 寄与・ステータスカード）。**打刻コンソールへの導線タイルはここで追加** | 低 | 0.5.6 |
 
### 他モジュール依存で保留中（再掲）
- 残業乖離アラートの検知本体 → **05 実装後**に接続
- 締め後ロック → **03 の `wp_monthly_summary` 作成後**
- モジュール01 の保留項目（ダッシュボードのステータスカード等）→ 02/03/05 のデータが揃い次第
---
 
## 9. 作業の進め方（この会話で確立した進行様式）
 
1. **要件定義書を先に精読**してから実装する（コードより要件が正本）。
2. **1機能を細かくスライス**。特に書き込み・外部送信を伴う処理は単独スライスにする。
3. **配布は「新規ファイルのみの ZIP ＋既存ファイルは行単位の差分パッチ」**。既存ファイルを ZIP で丸ごと
   上書きしないことで、他所の変更（例：0.4.7 の復職修正）を巻き戻す事故を防ぐ。
   - 実際 2a/2b とも、既存で触るのは `ims-portal.php`（3行/2行）と `Bootstrap.php`（1行追記）のみ。
4. 実装後は必ず **PHP 8.3 で全ファイル `php -l`**、ロジックは**スモークテスト**を書いて通す。
   - 2a：Settings 30項目 / DDL 突合 OK。2b：StatusCalculator 21項目 OK。
5. デプロイは **commit → `main` に push（自動デプロイ）**。DB_VERSION を上げたら wp-admin で `ims_db_version` を確認。
   新規URL追加時はパーマリンク再保存。
6. 既存実装を触るときは**依頼された箇所だけ**。共有CSSは画面スコープで回避。
---
 
## 10. 既知の申し送り・要確認事項
 
- **デプロイは push 自動化済み**（§5）。GitHub main = 本番、が通常状態。
  `.github/workflows/` を配布物に含めない・上書きしないこと。
- **2c は初の REST エンドポイント**。`ims/v1` の土台に初めて実処理を載せる。認証（nonce `wp_rest`）・
  権限・退職者拒否を server 側で必ず二重に固めること（クライアント表示制御は信用しない）。
- `src/Core/LoginPagePlaceholder.php` が**未使用のまま残存**。整理して削除してよい（01 からの持ち越し）。
- `00_portal.md` 第6章に**古い青系デザイントークン**が残り、`07_design_system.md`（和紙・墨）と矛盾。未解決。
- テストユーザーに **`hr_admin` ロールが不在**（administrator×2 / approver×1）。hr_admin 固有挙動の検証時は要作成。
- テストサーバーの**メール送達（SMTP）未検証**。CSV仮パスワードメール等に影響。
- 打刻データはまだ手動INSERT/実打刻ともほぼ空。2c 実装後に初めて実データが積まれる。
  それまで打刻コンソールは「出勤前」表示が既定。
 