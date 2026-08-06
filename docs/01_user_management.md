# 要件定義書：01_ユーザー管理モジュール (01_user_management.md)
 
## 1. 目的・概要
 
本モジュールは、WordPress 標準のユーザー管理システムを拡張し、社内勤怠・交通費・稟議システムにおける「組織構造」「雇用契約」「給与条件」を一元管理するマスター機能である。他の全モジュール（02_打刻管理・03_勤怠管理・04_交通費・05_稟議）が参照する従業員属性データの唯一の正としての役割を担う。
 
一般社員は WordPress 管理画面（wp-admin）へのアクセスを完全に禁止し、本システム専用のフロントエンドポータル（`/portal/`）のみを通じて操作する。管理者・人事管理者は wp-admin を通じてユーザーおよびマスターデータを管理する。
 
**認証方式は社員ごとに以下の2種類をサポートする。** すべての社員が Google Workspace アカウント（`@ims-hirosaki.com`）を持つわけではないため、Google アカウントログインとID・パスワード認証を並立させ、ユーザーごとに使用する認証方式を管理者が設定する。
 
| 認証方式 | 対象者の例 | 認証フロー |
|---|---|---|
| Google アカウント（OAuth 2.0） | `@ims-hirosaki.com` アカウントを持つ社員 | 「Google アカウントでログイン」ボタン経由 |
| ID・パスワード | Google Workspace アカウントを持たない社員（パート・アルバイト・業務委託等） | メールアドレス＋パスワード |
 
---
 
## 2. マスターデータ管理
 
### 2.1. 各種マスタ管理
 
WordPress 管理メニューに「社員管理 > 各種マスタ」サブメニューを設置し、以下の3カテゴリのマスター情報を一元管理する。
 
#### 2.1.1. 所属情報マスタ
 
所属・部署・役職・職種の4種類を個別に管理する。各マスタは共通の管理項目を持つ。
 
**共通管理項目**
 
| フィールド名 | 型 | 説明 |
|---|---|---|
| `id` | INT (AUTO_INCREMENT) | 主キー |
| `name` | VARCHAR(100) | 名称（必須） |
| `sort_order` | INT | 表示順 |
| `is_active` | TINYINT(1) | 有効フラグ（0=停止中） |
| `created_at` | DATETIME | 登録日時 |
| `updated_at` | DATETIME | 更新日時 |
 
**① 所属マスタ（`wp_ims_affiliations`）**
 
| 追加フィールド | 型 | 説明 |
|---|---|---|
| `affiliation_code` | VARCHAR(20) | 所属コード（一意・必須） |
 
- 拠点・事業所単位の大きな括り（例：弘前本部、青森支部、八戸事務所）。
- 部署と独立して管理し、部署は所属に紐づける。
**② 部署マスタ（`wp_ims_departments`）**
 
| 追加フィールド | 型 | 説明 |
|---|---|---|
| `department_code` | VARCHAR(20) | 部署コード（一意・必須） |
| `affiliation_id` | INT | 所属マスタの `id` を参照 |
 
- 部署コードは重複不可。バリデーションを実装する。
- 所属マスタと紐づけて登録する（どの所属に属する部署か）。
**③ 役職マスタ（`wp_ims_positions`）**
 
- 役職名と表示順のみを管理（例：部長、課長、係長、主任、リーダー）。
- 社員編集画面で選択式プルダウンとして提供する。
**④ 職種マスタ（`wp_ims_job_types`）**
 
- 職種名と表示順のみを管理（例：営業職、事務職、技術職、介護職、相談支援専門員）。
- 社員編集画面で選択式プルダウンとして提供する。
**所属情報マスタ共通の運用ルール**
 
- 各マスタの削除は**物理削除を禁止**し、`is_active = 0`（停止中）により論理削除とする。
- 在籍社員が存在する項目の停止操作時は、対象社員数を警告表示して確認を求める。
- 停止中の項目は、新規社員登録・編集時の選択肢から除外されるが、既存社員のデータには残る。
- 在籍社員が0名の項目のみ完全削除を許可する。
#### 2.1.2. 雇用形態マスタ（`wp_ims_employment_types`）
 
| フィールド名 | 型 | 説明 |
|---|---|---|
| `id` | INT (AUTO_INCREMENT) | 主キー |
| `name` | VARCHAR(100) | 雇用形態名（必須・例：正社員、契約社員、パート・アルバイト、業務委託、派遣、嘱託） |
| `sort_order` | INT | 表示順 |
| `is_active` | TINYINT(1) | 有効フラグ（0=停止中） |
| `created_at` | DATETIME | 登録日時 |
| `updated_at` | DATETIME | 更新日時 |
 
- 所属情報マスタと同様の運用ルール（論理削除、停止時の警告、0名のみ完全削除）を適用する。
#### 2.1.3. 手当設定マスタ（`wp_allowance_masters`）
 
| フィールド名 | 型 | 説明 |
|---|---|---|
| `id` | INT (AUTO_INCREMENT) | 主キー |
| `allowance_code` | VARCHAR(20) | 手当コード（一意） |
| `allowance_name` | VARCHAR(100) | 手当名（例：役職手当、資格手当、家族手当、住宅手当、皆勤手当） |
| `is_active` | TINYINT(1) | 有効フラグ |
| `sort_order` | INT | 表示順 |
| `created_at` | DATETIME | 登録日時 |
| `updated_at` | DATETIME | 更新日時 |
 
- ここでは手当の「種類」のみを登録する。各社員への金額設定は社員編集画面の「給与・手当設定」から行う（→ `wp_user_allowances` テーブル）。
- 本マスタは **03_勤怠管理モジュール** の月次スナップショット取得でも参照される。
---
 
### 2.2. 外部ツールランチャー管理（`wp_ims_launcher_apps`）
 
管理者が wp-admin の「ポータル設定 > 外部ツール設定」からランチャーに表示するツールを動的に管理する。ポータルのダッシュボード（`/portal/`）にのみ表示し、他ページでは表示しない。
 
#### テーブル定義
 
```sql
CREATE TABLE wp_ims_launcher_apps (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    app_name         VARCHAR(50)       NOT NULL,           -- 表示名（50文字以内）
    url              VARCHAR(2083)     NOT NULL,           -- リンク先URL（https:// または http://）
    icon_url         VARCHAR(2083)     NOT NULL,           -- アイコン画像URL（メディアライブラリ）
    protocol_scheme  VARCHAR(100)      DEFAULT NULL,       -- デスクトップアプリ起動スキーム（例：msteams://）
    sort_order       SMALLINT UNSIGNED NOT NULL DEFAULT 0, -- 表示順（小さいほど左に表示）
    is_active        TINYINT(1)        NOT NULL DEFAULT 1, -- 1=表示、0=非表示
    created_by       BIGINT UNSIGNED   NOT NULL,
    created_at       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sort_active (is_active, sort_order)
);
```
 
#### 初期登録データ
 
| 表示順 | ツール名 | リンク先 URL | 起動方法 |
|---|---|---|---|
| 1 | Gmail | `https://mail.google.com/` | 別タブ |
| 2 | Drive | `https://drive.google.com/` | 別タブ |
| 3 | Chat | `https://chat.google.com/` | 別タブ |
| 4 | カレンダー | `https://calendar.google.com/` | 別タブ |
| 5 | Teams | `https://teams.microsoft.com/` | デスクトップアプリ（`msteams://`） |
 
#### 管理機能
 
- ツールの追加・編集・削除・並べ替え（ドラッグ&ドロップ）・有効/無効切り替えを管理画面から操作できる。
- アイコン画像は PNG または SVG を WordPress メディアライブラリ経由でアップロードする（2MB以内）。
- `protocol_scheme` が設定されているツールはクリック時にデスクトップアプリ起動を優先し、失敗時は `url` へフォールバックする。
- 登録ツールが0件の場合、ダッシュボードのランチャーエリア自体を非表示にする。
- アイコン素材の利用規約（Google・Microsoft等）を遵守し、公式アイコンの改変・変形は禁止する。
#### REST API
 
| エンドポイント | メソッド | 用途 | 認証 |
|---|---|---|---|
| `GET /wp-json/ims/v1/launcher/apps` | GET | 有効なアプリ一覧を表示順で取得（ポータル用） | WordPress セッション必須 |
| `POST /wp-json/ims/v1/launcher/apps` | POST | 新規登録 | `hr_admin` 以上 |
| `PUT /wp-json/ims/v1/launcher/apps/{id}` | PUT | 更新 | `hr_admin` 以上 |
| `DELETE /wp-json/ims/v1/launcher/apps/{id}` | DELETE | 削除 | `hr_admin` 以上 |
| `POST /wp-json/ims/v1/launcher/apps/reorder` | POST | 並べ替え（`sort_order` 一括更新） | `hr_admin` 以上 |
 
---
 
## 3. 機能要件
 
### 3.1. ユーザーアカウント・認証管理
 
#### 認証方式の二方式並立
 
本システムは以下の 2 つの認証方式を並立してサポートする。各ユーザーの認証方式は `wp_usermeta` の `auth_type` メタキー（→ 3.3 参照）で管理し、管理者がユーザー登録・編集時に設定する。
 
**① Google アカウントログイン（OAuth 2.0）**
 
- `@ims-hirosaki.com` の Google Workspace アカウントを持つ社員が対象。
- ログイン・ログアウト・セッション維持は WordPress の `wp_set_auth_cookie` / `wp_logout` を使用するが、認証の起点は Google OAuth フローとする。
- **ログインフロー：**
  1. `/portal/login/` の「Google アカウントでログイン」ボタンを押下する。
  2. Google の OAuth 2.0 同意画面へリダイレクトする。
  3. Google 認証成功後に返却されるメールアドレスを `wp_users.user_email` と突合し、一致するユーザーの WordPress セッションを発行する。
  4. 対応する WordPress ユーザーが存在しない場合、または当該ユーザーの `auth_type` が `google_sso` でない場合はログインを拒否する。
- **ドメイン制限：** `@ims-hirosaki.com` のアカウントのみ許可する。個人 Gmail 等からの認証はサーバーサイドで拒否する。
- Google アカウントログイン対象ユーザーは WordPress 側のパスワードを使用しない。パスワード設定・変更・リセット UI は当該ユーザーの編集画面で非表示にする。
**② ID・パスワード認証**
 
- Google Workspace アカウントを持たない社員（パート・アルバイト・業務委託等）が対象。
- WordPress 標準のパスワード認証を使用する（ログイン・セッション維持・パスワードリセット）。
- パスワードリセット時は WordPress 標準のメール送信フローを使用する。
- ID・パスワードユーザーが Google アカウントログインボタンを使用してもログインできない（`auth_type` チェックで拒否する）。
#### 一般社員の wp-admin 完全ブロック
 
- `general_staff` ロールを持つユーザーが `wp-admin` にアクセスした場合、`init` フックで検知し、フロントエンドポータルトップ（`/portal/`）へ強制リダイレクトする。
- ログイン後のデフォルトリダイレクト先も `/portal/` とする（`login_redirect` フィルターで制御）。
- 上記は認証方式に関わらず共通で適用する。
#### アカウントの一括管理（CSV 取り込み・ダウンロード）
 
- **取り込み仕様**
  - 文字コード：UTF-8（BOM 付きも許容・Excel からの保存を考慮）
  - 取込キー：`employee_code`（社員番号）を突合キーとし、既存の場合は上書き更新、新規の場合は新規作成する。
  - エラー処理：行単位でバリデーションを行い、エラー行をスキップして有効行のみ取り込む。処理後にエラー行一覧をサマリー表示する。
  - 取込列定義（順序は固定）：
    | 列 | フィールド | 必須 |
    |---|---|---|
    | A | 社員番号 (employee_code) | ✓ |
    | B | 姓 (last_name) | ✓ |
    | C | 名 (first_name) | ✓ |
    | D | メールアドレス | ✓ |
    | E | 認証方式 (auth_type)：`google_sso` または `password` | ✓ |
    | F | 所属コード (affiliation_code) | |
    | G | 部署コード (department_code) | |
    | H | 役職名 (position_name) | |
    | I | 職種名 (job_type_name) | |
    | J | 雇用形態名 (employment_type_name) | |
    | K | 在籍状況 (employment_status) | |
    | L | 所定労働時間/日（時間）(scheduled_work_hours) | |
    | M | 基本給 (base_salary) | |
    | N | 交通費単価 (travel_unit_price) | |
    | O | 通勤片道距離 km (commute_one_way_km) | |
    | P | 確認者社員番号 (first_approver_code) | |
    | Q | 最終承認者社員番号 (final_approver_code) | |
  - `auth_type = google_sso` の場合、メールアドレスは `@ims-hirosaki.com` ドメインであることをバリデーションする。
  - `auth_type = password` の場合、メールアドレスのドメイン制限はしない。新規作成時は仮パスワードを自動生成してメール送信する。
- **ダウンロード仕様**
  - 退職者を含む全ユーザーを UTF-8 CSV 形式でダウンロード可能とする。
  - 出力列は取り込み列と同一フォーマットとする。
---
 
### 3.2. 権限（ロール）の定義
 
システム内の操作範囲を制限するため、以下の 4 つのカスタムロールを定義する。WordPress 標準の `administrator` ロールはシステム管理者として流用する。
 
| 画面上の呼称 | ロール識別子 | 主な権限 |
|---|---|---|
| 一般社員 | `general_staff` | 自身の打刻・勤務表・交通費入力・各種申請と申請履歴の閲覧のみ。wp-admin へのアクセス不可。 |
| 承認者（上長） | `approver` | 一般社員の権限に加え、担当部下の勤怠・交通費の確認（承認・差し戻し）と申請の承認・差し戻しが可能。wp-admin へのアクセス不可。 |
| 人事管理担当者 | `hr_admin` | 全社員の勤怠・交通費・申請データの閲覧・編集・CSV 出力、給与手当マスタ設定、月次締め処理が可能。wp-admin にアクセス可能（ユーザー管理・マスター管理画面に限定）。 |
| システム管理者 | `administrator` | 全データへのアクセス、プラグイン設定、ユーザー追加・削除、権限設定、承認ルート設定など全権限を持つ。 |
 
> **注記：** `approver` および `general_staff` は wp-admin アクセスをブロックし、フロントエンドポータルのみで操作を完結させる。認証方式はロールとは独立した設定であり、いずれのロールでも両認証方式を使用できる。
 
---
 
### 3.3. ユーザー属性・雇用契約情報の管理
 
以下の項目を `wp_usermeta` テーブルに保持する。ただし「手当金額」は独自テーブル（`wp_user_allowances`）、「基本給変更履歴」は独自テーブル（`wp_salary_history`）で管理する。
 
#### ① 基本情報
 
| メタキー | 型 | 説明 |
|---|---|---|
| `employee_code` | VARCHAR(20) | 社員番号（一意・重複不可。手動入力。登録後の変更は管理者のみ可） |
| `auth_type` | VARCHAR(20) | 認証方式：`google_sso`（Google アカウントログイン）/ `password`（ID・パスワード認証）。管理者が設定。 |
| `affiliation_id` | INT | 所属ID（`wp_ims_affiliations.id` を参照） |
| `department_id` | INT | 部署ID（`wp_ims_departments.id` を参照） |
| `position_id` | INT | 役職ID（`wp_ims_positions.id` を参照） |
| `job_type_id` | INT | 職種ID（`wp_ims_job_types.id` を参照） |
| `employment_type_id` | INT | 雇用形態ID（`wp_ims_employment_types.id` を参照） |
| `employment_status` | VARCHAR(20) | 在籍状況：`在籍` / `休職` / `育児休業中` / `出向` / `退職`（雇用形態とは別フィールド） |
| `scheduled_work_hours` | DECIMAL(4,2) | 1日の所定労働時間（単位：時間。例：8.00）→ 03_勤怠管理モジュールが参照 |
 
#### ② 承認フロー設定
 
| メタキー | 型 | 説明 |
|---|---|---|
| `first_approver_id` | INT | 確認者（第1承認者）のユーザーID → 03_勤怠管理・05_稟議モジュールが参照 |
| `final_approver_id` | INT | 最終承認者（第2承認者）のユーザーID → 03_勤怠管理・05_稟議モジュールが参照 |
 
- `first_approver_id` に登録するユーザーは `approver` 以上の権限を持つことを必須とし、登録時にバリデーションする。
- `final_approver_id` に登録するユーザーは `hr_admin` 以上の権限を持つことを必須とし、登録時にバリデーションする。
- **自己承認の禁止：** `first_approver_id` および `final_approver_id` に当該ユーザー自身の ID を設定しようとした場合はバリデーションエラーとする。
#### ③ 雇用・給与条件（センシティブ情報）
 
> 以下の項目は `hr_admin` および `administrator` のみが閲覧・編集できる。それ以外の権限には UI 上で完全に非表示とし、REST API・管理画面への直接アクセスも `current_user_can()` による権限チェックで拒否する。
 
| メタキー | 型 | 説明 |
|---|---|---|
| `base_salary` | INT | 基本給（現在の有効値。変更履歴は `wp_salary_history` で管理） |
| `travel_unit_price` | DECIMAL(6,2) | 交通費単価（円/km）→ 04_交通費モジュールが参照 |
| `commute_one_way_km` | DECIMAL(6,2) | 通勤片道距離（km）→ 04_交通費モジュールが参照。直行直帰対応のため往復ではなく片道で管理する。 |
 
---
 
### 3.4. 給与・手当変更履歴管理
 
#### 基本給の変更操作
 
- `base_salary` を変更する際は、新しい金額と適用開始日を入力させる専用フォームを使用する。
- 保存時に `wp_salary_history` テーブルへ新レコードを追記し、`wp_usermeta` の `base_salary` も現在の有効値として更新する。
- 変更操作者（`created_by`）と操作日時（`created_at`）を自動記録する（監査証跡）。
#### 手当金額の変更操作
 
- `wp_user_allowances` テーブルに適用開始日付きの新レコードを追記する形で変更を記録する。
- 現在の有効値は「`effective_date <= 本日` のレコードのうち最新の1件」として取得する。
- → 03_勤怠管理モジュールとの連携：月次締め時のスナップショット取得は、この有効値を参照して取得する。
---
 
### 3.5. 退職者アーカイブ管理
 
- ユーザーの物理削除は**システム上で禁止**する。代わりに `employment_status` を `退職` に変更し、アカウントを無効化する。
- 退職処理（`employment_status = 退職`）を実行した時点でそのユーザーの WordPress セッションを即時無効化する（`user_status = 0` に設定）。
- **認証方式別の追加対応：**
  - `auth_type = google_sso` の場合：Google Workspace 管理者と連携して Google アカウントを停止することを運用上推奨する。Google 側が停止されれば SSO 認証が通らなくなるため二重のログイン防止となる。
  - `auth_type = password` の場合：WordPress のパスワードをランダム値でリセットし、以降のログインを実質不可とする。
- 退職者は通常の社員一覧から除外され、「社員管理 > 退職者一覧」サブメニューで専用一覧表示する。
- 退職者の過去の勤怠・交通費・申請データは**読み取り専用**として参照可能な状態を維持する。
---
 
### 3.6. Google Workspace ユーザーライフサイクル自動同期（Tier 3）
 
> **本機能は将来的な発展機能（Tier 3）として位置づける。** 初期リリース時点では手動運用とし、運用が成熟した段階（目安：運用開始から6ヶ月以降）で実装を検討する。
> **対象：** `auth_type = google_sso` のユーザーのみ。`auth_type = password` のユーザーはこの自動同期の対象外とする。
 
Google Admin SDK（Directory API）と連携し、Google Workspace 側でのユーザー追加・停止を本システムの WordPress ユーザー管理に自動反映する。
 
#### 入社時（Google Workspace にユーザーを追加）
 
1. Google Admin でアカウント（`@ims-hirosaki.com`）を作成する。
2. Webhook または定期バッチ（Google Apps Script）で本システムの REST API を呼び出す。
3. 本システムが WordPress ユーザーを新規作成し、初期権限 `general_staff`・`auth_type = google_sso` を設定する。
4. 管理者に「新規ユーザーが作成されました。属性情報（所属・部署・承認者等）を設定してください」と Google Chat または Gmail で通知する。
> **注意：** 自動作成されたユーザーの「所属・部署・役職・職種・承認者設定」は自動同期の対象外とし、管理者が手動で設定する。
 
#### 退職時（Google Workspace でアカウントを停止）
 
1. Google Admin でアカウントを停止する。
2. 定期バッチ（1日1回）で停止済みユーザーを検知する。
3. 本システムの `employment_status` を `退職` に自動更新し、WordPress セッションを無効化する。
#### 実装上の前提条件
 
- Google Admin SDK の有効化が必要。
- サービスアカウントへのドメイン全体の委任（Domain-Wide Delegation）の設定が必要。
---
 
## 4. データベース設計（独自テーブル）
 
### 4.1. 所属情報マスタ
 
```sql
-- 所属
CREATE TABLE wp_ims_affiliations (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    affiliation_code VARCHAR(20)  NOT NULL UNIQUE,
    name             VARCHAR(100) NOT NULL,
    sort_order       INT          NOT NULL DEFAULT 0,
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
 
-- 部署
CREATE TABLE wp_ims_departments (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    department_code  VARCHAR(20)  NOT NULL UNIQUE,
    name             VARCHAR(100) NOT NULL,
    affiliation_id   INT          DEFAULT NULL,  -- wp_ims_affiliations.id を参照
    sort_order       INT          NOT NULL DEFAULT 0,
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
 
-- 役職
CREATE TABLE wp_ims_positions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    sort_order INT          NOT NULL DEFAULT 0,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
 
-- 職種
CREATE TABLE wp_ims_job_types (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    sort_order INT          NOT NULL DEFAULT 0,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```
 
### 4.2. 雇用形態マスタ
 
```sql
CREATE TABLE wp_ims_employment_types (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    sort_order INT          NOT NULL DEFAULT 0,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```
 
### 4.3. 手当設定マスタ
 
```sql
CREATE TABLE wp_allowance_masters (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    allowance_code VARCHAR(20)  NOT NULL UNIQUE,
    allowance_name VARCHAR(100) NOT NULL,
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order     INT          NOT NULL DEFAULT 0,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```
 
### 4.4. 外部ツールランチャー
 
```sql
CREATE TABLE wp_ims_launcher_apps (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    app_name         VARCHAR(50)       NOT NULL,
    url              VARCHAR(2083)     NOT NULL,
    icon_url         VARCHAR(2083)     NOT NULL,
    protocol_scheme  VARCHAR(100)      DEFAULT NULL,
    sort_order       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active        TINYINT(1)        NOT NULL DEFAULT 1,
    created_by       BIGINT UNSIGNED   NOT NULL,
    created_at       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sort_active (is_active, sort_order)
);
```
 
### 4.5. 個人手当金額・変更履歴
 
```sql
CREATE TABLE wp_user_allowances (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_id             BIGINT UNSIGNED NOT NULL,
    allowance_master_id INT             NOT NULL,  -- wp_allowance_masters.id を参照
    amount              INT             NOT NULL DEFAULT 0,
    effective_date      DATE            NOT NULL,
    created_by          BIGINT UNSIGNED NOT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_allowance (user_id, allowance_master_id, effective_date)
);
```
 
- 現在の有効値取得：`WHERE user_id = ? AND allowance_master_id = ? AND effective_date <= CURDATE() ORDER BY effective_date DESC LIMIT 1`
### 4.6. 基本給変更履歴
 
```sql
CREATE TABLE wp_salary_history (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    user_id        BIGINT UNSIGNED NOT NULL,
    base_salary    INT             NOT NULL,
    effective_date DATE            NOT NULL,
    note           TEXT,
    created_by     BIGINT UNSIGNED NOT NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_salary (user_id, effective_date)
);
```
 
- 現在の有効値取得：`WHERE user_id = ? AND effective_date <= CURDATE() ORDER BY effective_date DESC LIMIT 1`
---
 
## 5. 画面要件（UI/UX）
 
### 5.1. 管理者向け：WordPress 管理画面内の拡張 UI
 
#### 5.1.1. 社員一覧・編集画面
 
- 社員一覧テーブルに「社員番号」「所属・部署」「在籍状況」「ログイン方法」列を表示する。
- 在籍状況が `退職` のユーザーはデフォルトで一覧から除外し、「退職者も含む」フィルターで表示切り替えできるようにする。
- 社員編集画面は以下のカードセクション構成で表示する。
  | セクション | 対象権限 | 主な内容 |
  |---|---|---|
  | 基本情報・ログイン設定 | `hr_admin` 以上 | 社員番号、メールアドレス、氏名、ログイン方法（Googleアカウント／ID・パスワード）の切り替え |
  | 所属・雇用情報 | `hr_admin` 以上 | 所属・部署・役職・職種・雇用形態・在籍状況・所定労働時間・権限 |
  | 申請の承認者設定 | `hr_admin` 以上 | 確認者（第1承認者）・最終承認者（第2承認者）の選択 |
  | 給与・手当設定 | `hr_admin` 以上のみ | 基本給（変更フォーム・履歴リンク付き）・交通費単価・通勤片道距離・手当設定テーブル |
- ログイン方法の切り替えトグルで、Googleアカウントを選択した場合はパスワード設定フィールドを非表示にする。
- 基本給変更は専用サブフォーム（新金額・適用開始日・変更理由）から行い、変更履歴テーブルへ追記する。
#### 5.1.2. 各種マスタ管理画面
 
- メニュー階層：`社員管理 > 各種マスタ`
- 画面内を「所属情報」「雇用形態」「手当設定」の3タブで切り替える構成とする。
**所属情報タブ**
 
- 所属・部署・役職・職種の4セクションを縦スクロールで確認・編集できる構成。
- 各セクションは左側に一覧テーブル、右側に追加フォームを横並びで配置する（2カラムレイアウト）。
- 一覧テーブルの操作列に「編集」「停止」ボタンを設置する。停止中の項目は行をグレーアウトし「再開」「削除」ボタンに切り替わる。
- 部署の追加フォームには所属との紐づけ選択肢を含める。
**雇用形態タブ**
 
- 雇用形態の一覧テーブルと追加フォームを横並びで表示する。
**手当設定タブ**
 
- 手当の種類一覧と追加フォームを横並びで表示する。
- 「ここでは手当の種類を登録します。各社員への金額設定は社員編集画面から行ってください。」という案内文を表示する。
#### 5.1.3. 外部ツール設定画面
 
- メニュー階層：`ポータル設定 > 外部ツール設定`
- 左側に登録済みツール一覧（⠿ アイコンによるドラッグ並べ替え対応）、右側に追加・編集フォームを配置する。
- 一覧テーブルの列：アイコンプレビュー・ツール名・リンク先URL・起動方法・表示順・状態（表示中/非表示）・操作ボタン（編集）。
- 追加・編集フォームの項目：
  - **アイコン画像**：PNG / SVG ファイルアップロード（2MB以内）。プレビュー表示あり。
  - **ツール名**：50文字以内。
  - **リンク先URL**：`https://` または `http://` で始まること。
  - **起動方法**：「別タブで開く」「デスクトップアプリを起動」の選択式。
  - **デスクトップアプリ起動URL**（任意）：プロトコルスキーム（例：`msteams://`）。
  - **表示順**：数値入力。
  - **バーへの表示**：表示する / 非表示にする。
- フォーム下部にバー上のプレビューカードを表示し、登録後のアイコン表示イメージを確認できるようにする。
#### 5.1.4. 退職者一覧画面
 
- メニュー階層：`社員管理 > 退職者一覧`
- 退職者の一覧（社員番号・氏名・所属・部署・退職処理日・ログイン方法）を表示。
- 各退職者の過去データ（勤怠・交通費・申請）へのリンクを読み取り専用で提供。
- 退職者の再雇用処理：`employment_status` を `在籍` に戻すことでアカウントを復元可能とする。
  - `auth_type = google_sso` の場合：Google Workspace 側でアカウントを再有効化すれば次回ログインが可能になる旨を案内する。
  - `auth_type = password` の場合：パスワードリセットメールを自動送信し、本人に新しいパスワードを設定させる。
  - 再雇用時は管理者が所属・部署・承認者設定を改めて確認・更新することを推奨する旨を画面に案内する。
---
 
### 5.2. 一般社員向け：フロントエンドポータル
 
一般社員（`general_staff`・`approver`）が操作するすべての機能を、wp-admin を一切使わずに提供する専用フロントエンドポータル。PC をメインターゲットとし、スマートフォンでは限定的な操作に対応するレスポンシブ設計とする。
 
#### URL 構造（全モジュール共通）
 
| URL | 画面名 | 提供モジュール |
|---|---|---|
| `/portal/login/` | ログイン画面 | 本モジュール |
| `/portal/` | ダッシュボード（ホーム） | 本モジュール |
| `/portal/profile/` | マイページ | 本モジュール |
| `/portal/timecard/` | 打刻コンソール | 02_打刻管理 |
| `/portal/attendance/` | 月次勤務表・勤怠入力 | 03_勤怠管理 |
| `/portal/expenses/` | 交通費・借上げ入力 | 04_交通費 |
| `/portal/approval/` | 申請・承認 | 05_稟議 |
 
#### 5.2.1. ログイン画面（`/portal/login/`）
 
- 以下の 2 つのログイン方式を 1 画面に並べて提供するカスタムデザインページとする。
  - **「Google アカウントでログイン」ボタン：** Google アカウントログイン対象者向け。押下で Google OAuth 2.0 同意画面へリダイレクト。
  - **メールアドレス・パスワードフォーム：** ID・パスワード認証対象者向け。
- `@ims-hirosaki.com` 以外のドメインで Google 認証を試みた場合はログインを拒否する。
- ログイン成功後は `/portal/` へリダイレクトする。
- 未認証ユーザーが `/portal/` 以下にアクセスした場合はすべて `/portal/login/` へリダイレクトする。
#### 5.2.2. マイページ（`/portal/profile/`）
 
- 自分の基本情報を**読み取り専用**で表示する。表示項目：氏名・社員番号・所属・部署・役職・職種・雇用形態・在籍状況・確認者（上長）・最終承認者。
- 変更フォームは設置しない。変更が必要な場合は管理者（人事部）へ連絡するよう案内テキストを表示する。
- 給与・手当情報は一切表示しない。
#### 5.2.3. ダッシュボード（`/portal/`）
 
**外部ツールランチャー（ダッシュボードのみ表示）**
 
- ヘッダーの直下、ステータスカードの上に設置する。
- 白地・薄いボーダーのカード型コンテナに、ツールアイコンを横並びで表示する。
- Google 系ツールと Microsoft Teams の間に区切り線を入れる。
- 各アイコンはホバー時に軽い浮き上がりアニメーション（`translateY(-2px)`）を適用する。
- `protocol_scheme` が設定されているツール（Teams 等）はクリック時にデスクトップアプリ起動を優先し、失敗時は `url` へフォールバックする。
- **スマートフォン（640px以下）では非表示にする。**
**ステータスカードエリア**
 
- 以下の3枚のカードをグリッドで表示する。
  | カード | 表示内容 |
  |---|---|
  | 本日の勤務状況 | 出勤前 / 勤務中 / 休憩中 / 退勤済 ＋ 出退勤時刻 |
  | 今月の勤怠 | 提出ステータス（未提出 / 提出済 / 承認済 / 確定）＋ 出勤日数 |
  | 申請・承認 | 承認待ち件数・差し戻し件数 |
- グリッドは `repeat(auto-fill, minmax(240px, 1fr))` で実装し、スマートフォンでは1列縦並びになる。
**メニュータイルグリッド**
 
- 打刻・月次勤務表・交通費・申請・承認・マイページの機能タイルを表示する。
- グリッドは `repeat(auto-fill, minmax(160px, 1fr))` で実装し、最小2列を保証する。
#### 5.2.4. レスポンシブ対応方針
 
| ブレークポイント | 対象 |
|---|---|
| `≥ 1024px` | PC レイアウト（多列グリッド・ランチャー表示） |
| `640px ～ 1023px` | タブレット（グリッド列数を縮小） |
| `< 640px` | スマートフォン（ランチャー非表示・ステータスカード1列・タイル2列固定） |
 
---
 
## 6. 他モジュールとの連携定義
 
### 6.1. → 02_打刻管理モジュール
 
| 提供データ | 説明 |
|---|---|
| `user_id`（WordPress） | 打刻ログ（`wp_attendance_logs`）の `user_id` として使用 |
| `employment_status` | `退職` 状態のユーザーは打刻ボタンを非活性化する |
 
### 6.2. → 03_勤怠管理モジュール
 
| 提供データ | 説明 |
|---|---|
| `scheduled_work_hours` | 有給設定時の労働時間自動付与に使用 |
| `first_approver_id` | 月次勤務表の第1承認者として使用 |
| `final_approver_id` | 月次勤務表の第2承認者（最終承認者）として使用 |
| `base_salary`（現在有効値） | 月次締め時の給与スナップショット取得元 |
| `wp_user_allowances`（現在有効値） | 月次締め時の手当スナップショット取得元 |
| `wp_salary_history` | スナップショット取得時の参照元（有効日判定） |
 
### 6.3. → 04_交通費・車両借上げ申請モジュール
 
| 提供データ | 説明 |
|---|---|
| `travel_unit_price` | 通勤交通費および車両借上げ費の自動計算に使用（旧：`gasoline_unit_price`） |
| `commute_one_way_km` | 通常通勤交通費の自動計算に使用（旧：`commute_round_trip_km`。直行直帰対応のため片道距離で管理） |
 
### 6.4. → 05_稟議・承認フローモジュール
 
| 提供データ | 説明 |
|---|---|
| `first_approver_id` | 各申請の確認者（直属上長）の自動割り当てに使用 |
| `final_approver_id` | 各申請の最終承認者として使用 |
| 権限情報 | 各申請画面の表示制御・操作権限の判定に使用 |
 
### 6.5. → Google Workspace 連携
 
| 連携先 | 連携内容 | 対象ユーザー | 優先度 |
|---|---|---|---|
| Google OAuth 2.0 | `auth_type = google_sso` ユーザーの認証起点。`@ims-hirosaki.com` アカウントを `wp_users.user_email` と突合してセッション発行 | `auth_type = google_sso` のみ | Tier 1（即効） |
| Google Admin SDK（Directory API） | ユーザーライフサイクル自動同期（入社・退職の自動検知と WordPress ユーザーへの反映） | `auth_type = google_sso` のみ | Tier 3（将来的な発展） |
 
---
 
## 7. 非機能要件・セキュリティ
 
### 7.1. データ保護
 
- 給与・手当・個人情報などのセンシティブデータを含む API エンドポイントおよびデータ読み込み処理に対し、`current_user_can()` による権限チェックを徹底する。
- REST API カスタムエンドポイントには必ず `permission_callback` を実装し、未認証・権限不足のリクエストを拒否する。
- フォーム送信には WordPress の nonce（`wp_nonce_field` / `check_admin_referer`）を使用し CSRF 攻撃を防ぐ。
### 7.2. Google アカウントログインのセキュリティ
 
- **ドメイン制限の徹底：** Google OAuth のコールバック処理において、返却されたメールアドレスが `@ims-hirosaki.com` ドメインであることをサーバーサイドで必ず検証する。クライアントサイドの検証のみに依存しない。
- **`auth_type` の照合：** コールバック時にメールアドレス突合だけでなく、当該 WordPress ユーザーの `auth_type` が `google_sso` であることを確認してからセッションを発行する。
- **OAuth クライアント認証情報の管理：** Google Cloud Console で発行した OAuth 2.0 クライアント ID・クライアントシークレットは WordPress の `wp_options` テーブルに暗号化して保存する。Git リポジトリには絶対にコミットしない。
- **リダイレクト URI の固定：** Google Cloud Console の認証情報設定において、許可するリダイレクト URI を本番環境の `/portal/login/callback` のみに限定する。
- **State パラメータによる CSRF 対策：** OAuth フロー開始時にランダムな `state` パラメータを生成してセッションに保存し、コールバック時に一致を検証する。
### 7.3. パスワードポリシー（ID・パスワード認証対象）
 
- WordPress 標準のパスワード強度メーターを使用する。
- パスワードリセット時は WordPress 標準のメール送信フローを使用する。
- 初回登録時は管理者が仮パスワードを発行してメール送信し、初回ログイン後に本人がパスワードを変更する運用とする。
### 7.4. 給与情報の変更監査証跡
 
- `wp_salary_history` および `wp_user_allowances` テーブルへの登録時に操作者（`created_by`）と日時（`created_at`）を必ず記録する。
- これにより「誰がいつ給与情報を変更したか」を追跡可能とする。
### 7.5. データ整合性の保護
 
- ユーザーの物理削除は UI 上で実行不可とする。退職処理（`employment_status = 退職`）を唯一の「アカウント停止」手段とする。
- 退職処理を行ったユーザーの WordPress セッションは即時無効化される。`auth_type = google_sso` の場合は Google 側アカウント停止との二重ロックを推奨し、`auth_type = password` の場合はパスワードランダムリセットを自動実行する。
- 各種マスタ項目の停止（無効化）前に、在籍社員が存在しないことを確認する制約を設ける。