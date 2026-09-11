# 実装ガイドライン：08_実装方針・コーディング規約 (08_implementation_guidelines.md)
 
## 1. 目的・位置づけ
 
本書は、IMS Hirosaki 社内業務システム（00〜06 の全モジュール）を WordPress プラグインとして実装する際の **アーキテクチャ方針・コーディング規約・拡張ルールの正本（Single Source of Truth）** を定義する。
 
各モジュールの要件定義書（`00_portal.md` 〜 `06_google_workspace_integration.md`）が「何を作るか（What）」を定めるのに対し、本書は「どう作るか（How）」を定める。要件定義書と本書に技術的方針の差異がある場合、**本書を正とする**。
 
### 設計の基本原則
 
- **業務ロジックはすべてプラグインに置く。** テーマには業務機能・認証・データを一切持たせない（テーマ切替で壊れない）。
- **モジュラモノリス。** 単一プラグインの内部をモジュール別ディレクトリで分割し、各モジュールはフック／サービス経由でのみ core と会話する（直接 include しない）。
- **core 無改修で機能追加。** 新モジュールは `src/Module/` にフォルダを1つ足し、フックで自己登録するだけで載る。`ims-portal.php` 本体・他モジュールは無改修。
- **WPコアに最大限乗る。** 認証・ユーザー・権限・REST・DB抽象・管理画面の足場はコアを使い倒し、業務ドメインのみ自前実装する。
---
 
## 2. アーキテクチャ方針
 
### 2.1. テーマではなくプラグイン
 
| 項目 | 方針 |
|---|---|
| 業務機能・認証・ロール・REST・独自テーブル・Google連携 | **プラグインに実装**（テーマ非依存） |
| `/portal/` 配下の画面描画（00ダッシュボード含む） | プラグインのテンプレートで描画（`template_redirect` で丸ごと差し替え） |
| テーマ | 原則ノータッチ。サイトルート `/` に何か出す場合のみ最小限の `front-page.php` を使う |
 
> ダッシュボード（00）の画面もデータと密結合した業務アプリUIであるため、テーマではなくプラグイン側のテンプレートで持ち、管理画面（01）と同じ 07 デザイントークンで一貫させる。
 
### 2.2. モジュラモノリス（単一プラグイン・内部モジュール分割）
 
- 単一プラグイン `ims-portal` として梱包する。運用・バージョン・マイグレーションを1本化する。
- 内部は `src/Module/User/`・`src/Module/Timecard/` … とモジュール単位で分割し、コード上の分離感は「モジュール別プラグイン」と同等に保つ。
- **規律：** 各モジュールは core を直接 include せず、フック（`ims_portal_*`）と共通サービス層（`src/Support/`）経由でのみ連携する。これにより、将来いずれかのモジュールを独立プラグインに切り出す場合もほぼそのまま分離できる。
- 別プラグイン分割が妥当になるのは「別組織へ・別リリース周期で・別チームが配布する」場合のみ。本案件では該当しないため採用しない。
---
 
## 3. WPコア利用とドメイン自前実装の境界
 
**「認証・ユーザー・権限・基盤系はコア丸ごと」「業務ドメインは全部プラグイン＋独自テーブル」** を線引きとする。
 
| 領域 | WPコアを利用 | 自前実装 |
|---|---|---|
| セッション・ログイン状態 | ◎ `wp_set_auth_cookie` / `wp_logout` / Cookie・セッション検証 | — |
| パスワード認証 | ◎ 標準認証・ハッシュ・リセットメール・強度メーター | — |
| Google SSO | ○ 認証成功後のセッション発行は WP | OAuthフロー本体（リダイレクト・state・コールバック・メール突合・`auth_type` 照合） |
| ロール・権限 | ◎ `add_role` / `current_user_can` / capability | カスタムロール4種＋カスタム権限の定義 |
| ユーザー属性 | ◎ `wp_usermeta`（`get/update_user_meta`） | `UserRepository` で薄くラップ |
| REST API | ◎ `register_rest_route` / `permission_callback` / nonce | `ims/v1` 各エンドポイントの中身 |
| CSRF対策 | ◎ nonce（`wp_nonce_field` / `wp_verify_nonce`） | — |
| DBアクセス | ◎ `$wpdb`（`prepare` / `dbDelta` / `get_charset_collate`） | 業務テーブルのDDLとクエリ |
| 管理画面の足場 | ◎ `add_menu_page` / Settings API / admin notice | マスタ・社員編集・ランチャー設定の画面本体 |
| 設定保存 | ◎ `wp_options`（`get/update_option`） | OAuthシークレットは暗号化して格納 |
| メディア（アイコン） | ◎ メディアライブラリ | アップロードUIの組み込み |
| バッチ処理 | ◎ WP-Cron（`wp_schedule_event`） | 締め処理・打刻漏れ検知・Tier3同期 |
| 外部HTTP通信 | ◎ `wp_remote_post` / `wp_remote_get` | Chat Webhook・Distance Matrix・OAuthトークン交換のラッパ |
| メール送信 | ◎ `wp_mail` | 通知文面 |
| 国際化 | ◎ `__()` / テキストドメイン `ims-portal` | 文言（日本語） |
| アセット読み込み | ◎ `wp_enqueue_*` / `wp_localize_script`（nonce・REST root 受け渡し） | 07トークンCSS・ワイヤーフレームJS |
| `/portal/` ルーティング | △ `add_rewrite_rule` の仕組みのみ借用 | ルーター・テンプレート振り分け・認証ガード |
| 業務ロジック・画面 | — | 勤怠計算・交通費計算・承認フロー・締め/スナップショット・ダッシュボード描画 |
 
### 3.1. 意図的に使わないWP機能
 
- ポータル側ではテーマのフロント描画・Gutenberg/ブロック・投稿/固定ページ・コメントを使わない（`/portal/` は `template_redirect` で差し替え）。
- `wp-login.php` は `administrator` / `hr_admin` 用に残すが、`general_staff` / `approver` は wp-admin ごとブロックして `/portal/login/` のみに誘導する。
### 3.2. データ格納方式：CPT を使わず独自テーブル
 
打刻・日次勤怠・交通費明細・申請は **件数が多く・関係が複雑で・集計や締め処理が必要** なため、カスタム投稿タイプ（`wp_posts` / `wp_postmeta`）ではなく **独自テーブル** を使用する（CPTはメタ検索・集計が重く、給与計算系に不向き）。ユーザー属性のみコアの `wp_usermeta` を使い、給与・手当は履歴テーブルで管理する。
 
---
 
## 4. プラグイン構成・ディレクトリ
 
```
ims-portal/
├── ims-portal.php              # ブートストラップ・有効化/無効化フック
├── composer.json               # PSR-4: "IMS\\": "src/"
├── src/
│   ├── Core/                   # 00 基盤（モジュールではなく土台）
│   │   ├── Installer.php        # 全テーブルDDL収集・初期データ・版管理
│   │   ├── Roles.php            # カスタムロール/権限の登録
│   │   ├── Router.php           # /portal/ ルーティング・テンプレート振り分け
│   │   ├── AuthGuard.php        # 認証ガード・退職者ブロック・wp-admin遮断
│   │   ├── Assets.php           # /portal/* 判定時のみの条件付きenqueue
│   │   ├── TileRegistry.php     # ダッシュボードタイルの登録受付（→ §6.2）
│   │   └── SummaryAggregator.php# サマリーAPIの寄与集約（→ §6.2）
│   ├── Support/                # 横断サービス層（モジュールから利用）
│   │   ├── UserRepository.php   # usermeta属性アクセサ
│   │   ├── Capabilities.php     # 権限判定ヘルパ
│   │   └── Google/             # 06連携（Tier別）
│   │       ├── OAuthClient.php  # Tier1（SSO）
│   │       ├── ChatNotifier.php # Tier2（Chat Webhook）
│   │       └── ...              # Drive / Calendar / Distance Matrix 等
│   └── Module/                 # 業務モジュール（標準3点セットで統一）
│       ├── User/   ├── Portal/  ├── Timecard/
│       ├── Attendance/  ├── Expense/  └── Approval/
│       │   ├── Bootstrap.php    # フック登録（page/tile/summary/schema）
│       │   ├── Page.php         # 画面描画
│       │   ├── RestController.php
│       │   └── Repository.php   # DBアクセス
├── templates/                  # layout.php・各ページPHPテンプレート
└── assets/
    ├── css/ims-tokens.css       # 07デザイントークン（正本）
    ├── css/{module}.css
    └── js/{module}.js
```
 
各モジュールを **`Bootstrap` / `Page` / `RestController` / `Repository`** の定型に揃えることで、同じ型を反復生成でき、横断ルールを徹底しやすくする。
 
---
 
## 5. データ層・マイグレーション方針
 
### 5.1. 命名・接頭辞
 
| 対象 | 規約 |
|---|---|
| テーブル | 仕様のDDLは `wp_` 直書きだが、実装では必ず `{$wpdb->prefix}` を使用（マルチサイト・接頭辞変更に対応） |
| 文字セット | `$wpdb->get_charset_collate()` を必ず付与 |
| 関数 | 接頭辞 `ims_`（例：`ims_current_user_attr()`） |
| クラス | 名前空間 `IMS\`（例：`IMS\Module\Timecard\Page`） |
| フック | 接頭辞 `ims_portal_`（例：`ims_portal_register_page`） |
| REST 名前空間 | `ims/v1` |
| テキストドメイン | `ims-portal` |
 
### 5.2. マイグレーション（中央集約＋モジュール寄与）
 
- スキーマ版を `wp_options` の `ims_db_version` で管理し、`admin_init` で版差を検知して `dbDelta()` を実行する。
- **新モジュールが core を改修せずにテーブルを足せるよう**、各モジュールは `ims_register_schema` フィルタでDDLを寄与する。`Installer` は全DDLを収集して一括適用する。
```php
// 各モジュールの Bootstrap でDDLを寄与（core無改修でテーブル追加可能）
add_filter('ims_register_schema', function (array $ddls): array {
    $ddls[] = "CREATE TABLE {prefix}attendance_logs ( ... ) {charset};";
    return $ddls;
});
```
 
- スキーマ変更時は `ims_db_version` 定数をインクリメントする。`dbDelta()` の制約（カラム定義は2スペース区切り、`PRIMARY KEY` の書式等）を遵守する。
---
 
## 6. ルーティングと自己登録フック
 
### 6.1. ページ登録（既存・00_portal §5.2）
 
`/portal/` 配下はカスタムエンドポイント（`add_rewrite_rule` + `template_redirect`）で制御し、固定ページに依存しない。各モジュールは自分のページを名乗り出る。
 
```php
add_action('ims_portal_register_page', function ($router) {
    $router->register('/portal/timecard/', \IMS\Module\Timecard\Page::class);
});
```
 
### 6.2. 拡張フック（本書で新規定義）★
 
ダッシュボードのタイルとサマリーAPIを **中央直書きにせず**、各モジュールが寄与する方式とする。これにより新機能追加が「`Module/` にフォルダを置くだけ」で完結し、00 を無改修に保てる。
 
**① タイル登録 `ims_portal_register_tile`**
 
```php
add_action('ims_portal_register_tile', function ($registry) {
    $registry->add([
        'id'       => 'timecard',
        'label'    => '打刻',
        'icon'     => 'clock',
        'url'      => '/portal/timecard/',
        'priority' => 10,   // 表示順（小さいほど先）。順序は固定・パーソナライズ不可
        'caps'     => ['general_staff', 'approver'],          // 表示対象権限
        'badge'    => fn($user_id) => ims_timecard_unpunched($user_id) ? '!' : null,
    ]);
});
```
 
- タイルの **表示順は `priority` で固定**（00_portal §3.2.4 の「固定・パーソナライズ不可」を維持）。
- `badge` は遅延評価のコールバックとし、描画をブロックしない。
**② サマリー寄与 `ims_portal_summary_contribute`**
 
`GET /wp-json/ims/v1/portal/summary` および `badge-count` の集計に各モジュールが値を差し込む。
 
```php
add_filter('ims_portal_summary_contribute', function (array $summary, int $user_id): array {
    $summary['attendance_status'] = ims_timecard_today_status($user_id);
    $summary['missing_timecard']  = ims_timecard_is_missing($user_id);
    return $summary;
}, 10, 2);
```
 
- 各寄与は **自分のユーザーのデータのみ** を返す（`get_current_user_id()` で絞り込み）。
- 集計はモジュール側で完結させ、`SummaryAggregator` は寄与をマージするだけにとどめる。
---
 
## 7. 認証・ロール・権限
 
- **二方式並立**：`auth_type`（`google_sso` / `password`）で分岐。Google SSO は OAuth フロー本体のみ自前実装し、セッション発行は `wp_set_auth_cookie` を使う。ドメイン制限（`@ims-hirosaki.com`）・`auth_type` 照合・`state` 検証をサーバーサイドで必須化する。
- **ロール**：`general_staff` / `approver` / `hr_admin` / `administrator` を有効化時に `add_role` で登録。
- **権限は capability ベースで判定**：ロール名直参照ではなくカスタム権限を定義し、`current_user_can()` で判定する。
| カスタム権限（例） | 付与ロール | 用途 |
|---|---|---|
| `ims_view_salary` | `hr_admin`・`administrator` | 給与・手当の閲覧/編集 |
| `ims_manage_masters` | `hr_admin`・`administrator` | 各種マスタ・ランチャー管理 |
| `ims_approve` | `approver` 以上 | 承認・差し戻し |
| `ims_run_closing` | `hr_admin` 以上 | 月次締め処理 |
 
> ロール再編に強く、機微データのゲートを堅牢化できるため、`approver` ロール名で直接判定せず権限で判定する。
 
---
 
## 8. REST API 規約
 
- 名前空間は `ims/v1`。全エンドポイントに `permission_callback` を実装し、未認証は `401`、権限不足は `403` を返す。
- データ変更系（POST/PUT/DELETE）は nonce（`X-WP-Nonce`）を必須とする。nonce と REST root は `wp_localize_script` でフロントに渡す。
- 各エンドポイントは `get_current_user_id()` で常に本人データに絞り込み、他ユーザーのデータを混入させない。機微データ取得は `current_user_can('ims_view_salary')` 等でゲートする。
- クエリは必ず `$wpdb->prepare()` を通す。
---
 
## 9. フロント資産（CSS/JS）
 
- ビルド工程なしの **生CSS/JS**（フレームワークなし）で、ワイヤーフレームと 1:1 に保つ。
- 07 デザイントークンを `assets/css/ims-tokens.css` に切り出し、ポータル全画面で最初に読み込む。モジュール固有CSSはその後に重ねる。色・余白・角丸・影・フォントは必ずトークン経由で指定し、生HEX/pxを直書きしない（事業カラーのみDB由来で動的注入）。
- 共通JS関数（`show()`・`toggleTl()` 等）のシグネチャを踏襲する。
- アセットは `/portal/*` ページ判定時のみ条件付き enqueue し、wp-admin への混入を防ぐ。
- アクセシビリティ：`*:focus-visible` の藍アウトライン、`prefers-reduced-motion` 対応、外部リンクの `target="_blank" rel="noopener noreferrer"` を必須実装（07 §7・§8）。
---
 
## 10. 新機能の追加手順（拡張モデル）
 
### 10.1. 既存モジュールの拡張
 
該当する `src/Module/Xxx/` 内のみを編集する。core・他モジュールは無関係。
 
### 10.2. コード不要の拡張（データ駆動）
 
プラグインを触らず管理画面の操作のみで増やせるもの：外部ツールランチャー（`wp_ims_launcher_apps` へ行追加）、各種マスタ（所属・部署・役職・職種・雇用形態・手当）、承認者/承認ルート設定。
 
### 10.3. 新モジュールを丸ごと追加する手順
 
1. **仕様先行**：`08` は本書のため **新機能は `09_xxx.md` から採番**。要件定義書を書き、ワイヤーフレームで検証する（07トークンを使用）。
2. `src/Module/Xxx/` に標準4点セット（`Bootstrap` / `Page` / `RestController` / `Repository`）を作る。
3. `Bootstrap` で以下を**フック登録**する（core 無改修）：
   - `ims_portal_register_page` … `/portal/xxx/` を登録
   - `ims_portal_register_tile` … ダッシュボードタイルを登録（§6.2）
   - `ims_portal_summary_contribute` … サマリー/バッジへ寄与（§6.2）
   - `ims_register_schema` … 必要なテーブルのDDLを寄与（§5.2）
4. 専用権限が要れば `Roles` に capability を追加。
5. REST は `ims/v1/xxx/` 配下に生やす（§8 の規約に従う）。
6. CSS/JS は 07 トークンを参照して書き、条件付き enqueue する。
7. 00_portal §2 のURL正本テーブルに1行追記する（運用ルール）。
> 上記 2〜6 のいずれも `ims-portal.php` 本体・他モジュールを書き換えない。モジュールがフックで自己登録することで、機能だけが増える。
 
---
 
## 11. コーディング規約・セキュリティ（必須ルール）
 
- DDL/クエリ：`wp_` 直書き禁止 → `{$wpdb->prefix}`。`$wpdb->get_charset_collate()` 付与。全クエリ `$wpdb->prepare()`。
- 出力エスケープ：`esc_html()` / `esc_attr()` / `esc_url()` を徹底。入力は `sanitize_*` で正規化。
- CSRF：フォーム/変更系RESTに nonce 必須。
- 権限：機微データの読込・APIに `current_user_can()` を徹底。
- 機密情報：OAuthクライアントシークレット・サービスアカウント鍵は `wp_options` に暗号化保存し、**Gitにコミットしない**。リダイレクトURIは本番の `/portal/login/callback` に固定。
- 物理削除の禁止：ユーザーは退職処理（`employment_status = 退職`）で無効化。マスタは論理削除（`is_active = 0`）。
- Google連携は **非ブロッキング（best-effort）**：失敗しても業務操作を止めない。Tier1/2/3 の優先度に従う。
- コーディング標準：WordPress Coding Standards（WPCS）に準拠。日本語文言は `__('...', 'ims-portal')`。
---
 
## 12. 実装フェーズ順序
 
依存関係（全モジュール → 01ユーザーマスタ＋00基盤）から、以下の順で進める。
 
| Phase | 内容 | 主な依存 |
|---|---|---|
| **0** | プラグイン骨格：起動・Installer・Roles/権限・トークンCSS・Router・AuthGuard・**TileRegistry/SummaryAggregator（拡張フック）** | — |
| **1** | 01ユーザー管理：各種マスタ・ユーザー属性・**二方式認証（Google OAuth=06 Tier1 を前倒し）**・CSV入出力 | Phase 0 |
| **2** | 00ダッシュボード：ランチャー・ステータスカード・バッジ（summary API） | Phase 1 |
| **3** | 02打刻（勤務ステータスを供給） | Phase 1・2 |
| **4** | 03勤怠（締め・スナップショット） | Phase 1・3 |
| **5** | 04交通費 | Phase 1 |
| **6** | 05稟議（承認フロー） | Phase 1・3・4 |
| 横断 | 06 Google連携：Tier1は Phase1 同梱、Tier2（Chat通知等）は各モジュール着手時に追加、Tier3は据え置き | 各 Phase |
 
---
 
## 13. 申し送り・未決事項
 
| 項目 | 状態 |
|---|---|
| ローカル開発環境 | `wp-env`（公式Docker）でプラグインをリポジトリ直下に置きマウント。Claude Code 連携の前提 |
| 07 §10 のデザイン統一タスク（`--moss-tint` の値揺れ等） | 実装時に `ims-tokens.css` へ集約して解消 |
| WFH手当計算・半休＋半在宅の運用ルール（03_04 申し送り） | 契約条件の確認後に確定 |
| Tier3（Admin SDK ライフサイクル同期） | 運用開始6ヶ月以降に実装検討 |
 