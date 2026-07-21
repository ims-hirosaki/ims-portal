# IMS Hirosaki 実装 引き継ぎ書（Phase 1c 完了時点）

作成日：2026-07-08 / プラグインバージョン：`0.4.0-phase1c`

---

## 0. このドキュメントの使い方（新しいチャットで最初に読む）

このファイルは、IMS Hirosaki の WordPress プラグイン実装を **別のチャットで継続する**ための引き継ぎ書です。新チャットを始めるときは、以下を用意すると円滑です。

1. このファイル（`引き継ぎ書_phase1c.md`）を貼る/添付する
2. **現在のプラグインコード一式**（`ims-portal-phase1c.zip`）を添付する ← コードを編集するには実物が必要
3. プロジェクトナレッジの要件定義書（`00_portal.md`〜`08_implementation_guidelines.md`）はそのまま参照可能

そのうえで「Phase 1d から続けたい」と伝えれば再開できます。

---

## 1. プロジェクト概要

- **何を作っているか：** 弘前の企業向け社内業務システム（グループウェア）「IMS Hirosaki」。WordPress プラグインとして実装。
- **モジュール構成：** 00ポータル基盤 / 01ユーザー管理 / 02打刻 / 03勤怠 / 04交通費 / 05稟議 / 06 Google Workspace連携。
- **利用者ロール：** `general_staff`（一般社員）/ `approver`（承認者）/ `hr_admin`（人事）/ `administrator`（管理者）。一般社員・承認者は wp-admin 禁止、`/portal/` のみ利用。
- **要件定義書：** プロジェクトナレッジの `00_portal.md`〜`06_google_workspace_integration.md`、`07_design_system.md`（デザイン正本）、`08_implementation_guidelines.md`（実装方針正本）。

---

## 2. 確定済みアーキテクチャ方針（詳細は 08_implementation_guidelines.md）

- **全部プラグイン。** 業務ロジック・認証・データはすべてプラグイン。テーマには置かない（テーマ非依存）。ダッシュボード（00）の描画もプラグイン側。
- **モジュラモノリス。** 単一プラグイン `ims-portal` の内部を `src/Module/` でモジュール分割。各モジュールは core をフック／サービス経由でのみ参照（直接 include しない）。
- **WPコアに最大限乗る。** 認証・ユーザー・ロール・REST・DB抽象・管理画面の足場はコアを使い、業務ドメインのみ自前実装。社員編集は**標準ユーザー編集画面を拡張**する方式。
- **CPTは使わず独自テーブル。** トランザクション/マスタは `wp_ims_*` 等の独自テーブル。ユーザー属性のみ `wp_usermeta`。
- **core 無改修で拡張。** 新モジュールは `src/Module/Xxx/` を足し、以下のフックで自己登録するだけ：
  - `ims_portal_register_page`（URL登録）
  - `ims_portal_register_tile`（ダッシュボードタイル）
  - `ims_portal_summary_contribute`（サマリー/バッジ集計）
  - `ims_register_schema`（テーブルDDL）
  - `ims_seed_initial_data`（初期データ）
- **FTP運用のため Composer install は不要。** `ims-portal.php` 内の軽量オートローダー（`IMS\` → `src/`）で解決。`composer.json` はIDE補完用。
- **セキュリティ規約：** DDLは `{$wpdb->prefix}` + `$wpdb->get_charset_collate()`、クエリは `$wpdb->prepare`、出力は `esc_*`、変更系は nonce、権限は capabilityベース（`current_user_can`）、機密は暗号化保存・非コミット、物理削除禁止（論理削除）。

---

## 3. 実装済みの状態

### Phase 0（基盤）✅ 動作確認済み
プラグイン起動・軽量オートローダー・Installer（中央集約マイグレーション、べき等、`maybe_upgrade`でFTP追従）・Roles（4ロール+7 capability）・Router（`/portal/`）・AuthGuard（未認証/退職/ wp-adminブロック）・Assets（条件付きenqueue）・TileRegistry / SummaryAggregator（拡張フック）・EnvironmentCheck（パーマリンク警告）・共通レイアウト・07デザイントークンCSS。

### Phase 1a（マスタ）✅ 動作確認済み
01の全9テーブルDDL（Schema）・MasterRepository（設定駆動CRUD、論理削除、メンバー数チェック、コード一意）・AdminMastersPage（3タブ2カラム、PRG、nonce）・ランチャー初期5件投入・admin.css。

### Phase 1b（社員一覧・編集）✅ 動作確認済み
EmployeeRepository（属性読み書き・検証：社員番号一意/自己承認禁止/承認者権限レベル、基本給・手当の履歴追記）・AdminUserListPage（社員管理メニュー親＝社員一覧、退職者フィルタ）・AdminUserFields（標準ユーザー編集画面へ4カード追加：基本情報・所属情報・承認者・給与手当。給与手当は `ims_view_salary` のみ表示/保存）・admin.js（認証方式でパスワード欄を出し分け）。

### Phase 1c（認証）✅ Google認証まで動作確認済み
Crypto（シークレット暗号化）・OAuthClient（Google OAuth低レベル）・GoogleOAuth（state検証→ドメイン検証→ユーザー突合→`auth_type`検証→セッション発行）・PasswordAuth（`google_sso`のパスワードログイン拒否、フォーム処理）・LoginPage（本番ログイン画面、Phase0のプレースホルダを置換）・AdminAuthSettingsPage（「ポータル設定 > 認証設定」、シークレット暗号化保存、リダイレクトURI表示）。

---

## 4. 現在のファイル構成

```
ims-portal/
├── ims-portal.php              # 起動・軽量オートローダー・定数（VERSION 0.4.0-phase1c / DB_VERSION 2）
├── composer.json               # IDE補完用（install不要）
├── README.md
├── src/
│   ├── Core/
│   │   ├── Installer.php         # 中央集約マイグレーション（ims_register_schema収集→dbDelta）
│   │   ├── Roles.php             # 4ロール＋カスタム権限（capabilityベース）
│   │   ├── Router.php            # /portal/ ルーティング、ims_portal_register_page
│   │   ├── AuthGuard.php         # 認証ガード・退職者/wp-adminブロック
│   │   ├── Assets.php            # /portal/* 条件付きenqueue、REST root/nonce受け渡し
│   │   ├── TileRegistry.php      # ダッシュボードタイル集約（ims_portal_register_tile）
│   │   ├── SummaryAggregator.php # summary/badge-count集約（ims_portal_summary_contribute）
│   │   ├── EnvironmentCheck.php  # パーマリンク未設定の警告
│   │   ├── Layout.php            # header/footer描画ヘルパー
│   │   └── DashboardPage.php     # /portal/ ダッシュボード（タイル並べ）
│   ├── Support/
│   │   ├── UserRepository.php    # usermeta属性の横断読取アクセサ
│   │   ├── Capabilities.php      # 権限判定ヘルパー
│   │   ├── Crypto.php            # AES暗号化（SALT由来鍵）
│   │   └── Google/OAuthClient.php# OAuth低レベル（認証URL/トークン/userinfo）
│   └── Module/User/             # 01_user_management
│       ├── Bootstrap.php         # 自己登録（schema/seed/管理画面/認証）
│       ├── Schema.php            # 01の全9テーブルDDL
│       ├── MasterRepository.php  # 各種マスタ共通CRUD
│       ├── AdminMastersPage.php  # 各種マスタ画面（3タブ）
│       ├── AdminUserListPage.php # 社員一覧（社員管理メニュー親）
│       ├── AdminUserFields.php   # 標準ユーザー編集画面への業務カード
│       ├── EmployeeRepository.php# 社員属性の読み書き・検証・履歴
│       └── Auth/
│           ├── LoginPage.php          # 本番ログイン画面 /portal/login/
│           ├── GoogleOAuth.php        # Google OAuthオーケストレーション
│           ├── PasswordAuth.php       # パスワード認証・auth_type制御
│           └── AdminAuthSettingsPage.php # 認証設定画面
├── templates/                   # header.php / footer.php / 404.php
├── assets/css/                  # ims-tokens.css（07正本）/ portal.css / admin.css
└── assets/js/                   # portal.js / admin.js
```

### 独自テーブル（すべて `{$wpdb->prefix}` 接頭辞）
`ims_affiliations` / `ims_departments` / `ims_positions` / `ims_job_types` / `ims_employment_types` / `allowance_masters` / `ims_launcher_apps` / `user_allowances` / `salary_history`
※ 接頭辞の `ims_` 有無は要件定義書DDLに合わせて意図的に不統一（traceability優先）。

### 主要な usermeta キー
`employee_code` / `auth_type`(`google_sso`|`password`) / `affiliation_id` / `department_id` / `position_id` / `job_type_id` / `employment_type_id` / `employment_status`(在籍/休職/育児休業中/出向/退職) / `scheduled_work_hours` / `first_approver_id` / `final_approver_id` / `base_salary` / `travel_unit_price` / `commute_one_way_km`

### カスタム権限
`ims_use_portal` / `ims_approve` / `ims_view_salary` / `ims_manage_masters` / `ims_manage_users` / `ims_run_closing` / `ims_manage_system`

---

## 5. テスト環境・デプロイ

- **URL：** `https://portal-site.labs-ims.com/`（サブドメインに新規インストールした独立WordPress。マルチサイトではない）
- **バージョン：** WordPress 7.0 / PHP 8.3.30 / パーマリンク「投稿名」
- **デプロイ：** FTPで `wp-content/plugins/ims-portal/` にフォルダごと上書き。有効化時 or 上書き後に管理画面を開くと `maybe_upgrade` がスキーマ差分を追従（DB_VERSION比較）。
- **Google OAuth：** 設定済み・動作確認済み。Google Cloud プロジェクト「ImsPortal」で「内部」アプリとしてクライアント作成、リダイレクトURI `https://portal-site.labs-ims.com/portal/login/` を登録。ID/シークレットは「ポータル設定 > 認証設定」に保存済み（シークレットは暗号化）。
- **脱出口：** カスタムロールを付けない素の `administrator` アカウントを温存。トラブル時はプラグイン無効化 or フォルダ名リネームで復旧。

---

## 6. この会話で決めた重要事項（メモリに未反映の可能性がある決定）

- **プラグイン構成＝モジュラモノリス**（core+モジュール別プラグインではなく単一プラグイン）。
- **社員編集はWP標準ユーザー編集画面を拡張**する方式（フル自作しない）。社員一覧は 社員管理 メニューにカスタム一覧、編集は `user-edit.php` にカード追加。
- **拡張フックを2本追加**（`ims_portal_register_tile` / `ims_portal_summary_contribute`）＋スキーマ寄与フィルタ `ims_register_schema`。ダッシュボード・テーブル追加も core 無改修で可能に。
- **コード規則：** 内部参照は `id`（整数）。コード（`affiliation_code`等）は主キーでなく **CSV突合キー**。推奨：半角大文字英数＋ハイフン、ゼロ埋め、一度使ったら付け替えない。役職/職種/雇用形態はコード欄なし（名称のみ）。現状の実装は書式強制なし（一意・必須のみ）。
- **利用者本人（一成さん）の本番アカウント設計：** 「一般社員として働きつつ保守も担う」→ **アカウントを2つに分ける**。日常＝`general_staff`（本番`@ims-hirosaki.com` / Google SSO）、保守＝別アドレスの `administrator`（非常用）。テスト段階では現開発者アカウントを administrator のまま温存し、`general_staff`/`approver`/`hr_admin` のテスト社員を別途作成する。
- **軽量オートローダー採用**（Composer install不要。FTP運用のため）。

---

## 7. 動作確認できていること / まだのこと

- ✅ Phase 0〜1a〜1b：確認済み（テーブル作成・マスタCRUD・社員一覧/編集）
- ✅ Google認証：ログイン成功を確認
- ⏳ パスワード認証・`auth_type`による相互ブロックの実地確認（実装済み、要動作確認）
- ⏳ 承認ルート（`approver`/`hr_admin` テスト社員での検証）
- ※ テスト社員（general_staff / approver / hr_admin）をまだ十分に作っていない場合は、1d検証の前に用意すると良い。

---

## 8. 次にやること：Phase 1d（Module 01 の残り）

要件定義書 `01_user_management.md` の残りスコープ：

1. **マイページ（`/portal/profile/`）** … 社員が自分の属性を閲覧（§5.2想定）。給与等の機微は本人閲覧範囲を要確認。
2. **CSV入出力**（§3.1「アカウントの一括管理」/ §190-223）… 取込キー`employee_code`、17列固定フォーマット（A〜Q）、UTF-8/BOM許容、行単位バリデーション、`auth_type=password`新規は仮パスワード自動生成メール、`google_sso`は`@ims-hirosaki.com`検証。ダウンロードは退職者含む全件・同一フォーマット。
3. **外部ツールランチャー管理（`ポータル設定 > 外部ツール設定`）**（§2.2 / §5.1.3）… `ims_launcher_apps` のCRUD＋ドラッグ並べ替え、アイコンはメディアライブラリ、`GET /wp-json/ims/v1/launcher/apps`。ダッシュボードのランチャー表示（00側）もここで結線。
4. **退職者一覧（`社員管理 > 退職者一覧`）**（§3.5 / §5.1.4）… 物理削除禁止、`employment_status=退職`で無効化、退職時のセッション無効化（`user_status=0`）・`password`はパスワードランダムリセット。通常一覧から除外し専用一覧に表示。

1d が完了すれば **Module 01 完了**。その後の順序（08 §12）：**02打刻 → 03勤怠 → 04交通費 → 05稟議**。06 Google連携はTier1（SSO）実装済み、Tier2（Chat通知等）は各モジュール着手時に追加、Tier3は据え置き。

---

## 9. 作業の進め方（この会話で確立した進行様式）

- **スライスして確認：** 1フェーズ/スライスずつ実装 → テストサイトで確認 → 次へ（先回り実装しない）。
- **各モジュールは4点セット：** `Bootstrap`（フック登録）/ `Page`（描画）/ `RestController`（API）/ `Repository`（DB）を踏襲。
- **成果物の渡し方：** FTP用にフォルダ一式を ZIP 化して提供。全PHPは `php -l` で構文チェック済み。
- **デザインは07トークン準拠**（生CSS/JS、和紙・墨：藍`#1E3A5F`・朱・金・苔、Shippori Mincho + Zen Kaku Gothic New）。生HEX/px直書きしない。
- **プラグインを触らず増やせるもの：** ランチャー・各種マスタ・承認者設定は管理画面操作のみで追加可能（データ駆動）。

---

## 10. 既知の申し送り・要確認事項

- 一成さん本人が承認される相手（上長・人事）が実組織で誰になるか（実質トップの場合は承認ルート設計が必要）。
- CSV仕様の細部（列O以降の承認者コード突合、エラー行サマリー表示のUX）は 1d 着手時に要件定義書 §3.1 を再確認。
- 退職処理の Google 側連携（Tier3、`google_sso`ユーザーのGoogleアカウント停止推奨）は運用ルールとして据え置き。
- `MasterRepository::all` 等でテーブル名を内部定義でSQL連結している箇所は仕様上安全（ユーザー入力ではない）だが、PHPCS的には警告対象。
