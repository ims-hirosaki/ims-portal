# 要件定義書：05_稟議・承認フローモジュール (05_approval_workflow.md)
 
## 1. 目的・概要
 
本モジュールは、有給休暇・遅刻早退・経費精算・残業・出張・出張手当など、社内のあらゆる申請と承認手続きをデジタル管理化する機能である。
 
承認完了（`confirmed`）時に他モジュールへ自動連動し、差し戻し・取り下げ・管理者取消時には連動先のデータを**自動ロールバック**する仕様とする。これにより、人手を介さない一貫したデータ整合性を保証する。
 
本モジュールにおける操作結果の役割分担は以下の通り。
 
| 申請種別 | 承認後の連動先 |
|---|---|
| 有給休暇申請 | 03_勤怠（フラグ・事業割り当て）・04_交通費（0円化）・Google Calendar（イベント作成） |
| 遅刻・早退申請 | 03_勤怠（記録） |
| 経費精算申請 | 03_勤怠の事業別経費集計に加算・領収書を Google Drive に保存 |
| 残業申請 | 03_勤怠（承認記録）・02_打刻との乖離検知 |
| 出張申請 | Google Calendar（イベント作成） |
| 出張手当申請 | 03_勤怠の事業別経費集計に加算 |
 
---
 
## 2. マスター設定要件
 
### 2.1. 申請種別マスター管理
 
初期状態では以下の 6 種別を登録する。`hr_admin` / `administrator` が追加・無効化できる。
 
| 種別名 | `type_code` | 主な入力フォーム |
|---|---|---|
| 有給休暇申請 | `paid_leave` | 対象日（開始〜終了）・区分（全日/半日AM/半日PM/時間休）・取得理由 |
| 遅刻・早退申請 | `late_early` | 対象日・種別（遅刻/早退）・予定時刻・理由 |
| 経費精算申請 | `expense` | 発生日・支払先・金額・領収書画像・紐付け事業・目的 |
| 残業申請 | `overtime` | 対象日・予定開始時刻・予定終了時刻・申請理由 |
| 出張申請 | `business_trip` | 日程（開始〜終了）・目的地・出張目的・概算費用 |
| 出張手当申請 | `trip_allowance` | 対象期間・紐付け出張申請ID（任意）・手当金額・紐付け事業・内容 |
 
---
 
### 2.2. 承認ルートマスター管理
 
メニュー階層：`社員管理 > 承認ルート設定`
 
`administrator` のみが設定できる。各申請種別ごとに承認ステップを定義する。
 
#### 承認者の指定方法
 
各ステップの承認者は以下の3種から選択する。
 
| 種別 | 動作 |
|---|---|
| 特定ユーザー | ドロップダウンで特定の WordPress ユーザーを指定 |
| 申請者の直属チェック者 | 申請者の `first_approver_id`（01モジュール）を実行時に自動参照 |
| 申請者の最終管理者 | 申請者の `final_approver_id`（01モジュール）を実行時に自動参照 |
 
- 「＋ ステップを追加」ボタンで承認ステップを任意の数だけ追加・削除できる。
- 申請が提出された時点で、その時点の承認ルートをスナップショットして `wp_approvals.total_steps` に記録する（ルート変更が進行中案件に影響しないよう固定する）。
---
 
### 2.3. Google Chat Webhook 設定
 
メニュー階層：`設定 > 通知設定`
 
| 設定項目 | 説明 |
|---|---|
| グローバル Webhook URL | 全種別の通知を送る共通チャットルーム URL |
| 種別ごとの Webhook URL（任意） | 申請種別ごとに通知先ルームを分ける場合に個別設定 |
 
**通知失敗時の処理：**
- 最大 3 回リトライする（リトライ間隔: 指数バックオフ）。3 回失敗した場合はエラーをシステムログ（WordPress `error_log`）に記録する。
- 通知失敗は承認フロー自体をブロックしない（通知はベストエフォート）。
- **Gmail フォールバック（Tier 2）：** Google Chat Webhook が 3 回リトライ後も失敗した場合、Gmail API（`users.messages.send`）を使ってメール通知を代替送信する（→ 8.5 参照）。
**Gmail フォールバック設定（Tier 2）：**
 
| 設定項目 | 説明 |
|---|---|
| Gmail フォールバックの有効/無効 | 有効にすると Chat 失敗時に Gmail API でメール通知を代替送信する |
| 送信元メールアドレス | `noreply@ims-hirosaki.com`（ドメイン全体委任が設定されたサービスアカウントで送信） |
 
---
 
### 2.4. 添付ファイル保存先設定
 
メニュー階層：`設定 > ファイル保存設定`
 
| 設定値 | 保存先 |
|---|---|
| `local`（デフォルト） | WordPress アップロードディレクトリ（`wp-content/uploads/approvals/YYYY/MM/`） |
| `google_drive` | Google Drive の指定フォルダ（サービスアカウント経由） |
 
- **ファイルサイズ上限：** プラグイン設定で変更可能（デフォルト: 10MB）
- **許可フォーマット：** JPEG・PNG・PDF
- 保存されたファイルの URL を `wp_approval_meta` の `meta_key = 'receipt_url'` として記録する。
**`google_drive` 選択時の追加設定：**
 
| 設定項目 | 説明 |
|---|---|
| Drive ルートフォルダ ID | アップロード先ルートフォルダの ID（Google Drive URL から取得） |
 
**フォルダ構成（Drive 側）：**
```
IMS社内システム/
└── 経費領収書/
    └── YYYY年/MM月/
        └── {社員番号}_{氏名}_{申請ID}.pdf（または画像）
```
 
- Google Drive API（`files.create`）を使用し、サービスアカウント経由でアップロードする。
- アップロード完了後、Drive のファイル URL を `wp_approval_meta.receipt_url` に記録し、承認画面からリンクで直接開けるようにする。
- Drive アップロード失敗時はローカル保存にフォールバックし、管理者にエラーを通知する。
---
 
### 2.5. Google Calendar 同期設定（Tier 1）
 
メニュー階層：`設定 > カレンダー同期設定`
 
| 設定項目 | 説明 |
|---|---|
| Calendar 同期の有効/無効 | 有効にすると `confirmed` 時に申請者・承認者のカレンダーへイベントを作成する |
| 同期対象申請種別 | 有給休暇・出張申請（チェックボックスで個別に有効化可能） |
 
- Google Calendar API（`events.insert` / `events.delete`）を使用し、サービスアカウント（ドメイン全体委任設定済み）経由でユーザー個別の OAuth 同意なしに書き込む。
- カレンダーイベントの `description` には本システムの申請詳細 URL を含め、クリックで稟議画面に遷移できるようにする。
---
 
## 3. 機能要件
 
### 3.1. 申請フォームの動的切り替え
 
- スタッフが申請画面の「申請種別」プルダウンを選択した際、ページリロードなし（WP REST API + JavaScript）で対象種別専用の入力フォームに切り替わる。
- 切り替え時に入力済みの内容はクリアし、確認ダイアログを表示してから切り替える。
---
 
### 3.2. 申請ステータス管理
 
#### ステータス定義
 
| ステータス | `status` 値 | 説明 |
|---|---|---|
| 下書き | `draft` | 申請者が保存のみ。未提出。編集・削除可能。 |
| 審査中 | `pending` | 申請者が提出済み。現在の承認ステップの承認者が審査中。 |
| 差し戻し | `rejected` | 承認者が差し戻し。申請者は修正して再提出可能。 |
| 却下 | `dismissed` | 承認者が却下。完全終了・再申請不可。 |
| 取り下げ | `withdrawn` | 申請者が自ら取り消し。`pending` または `rejected` の状態でのみ実行可能。 |
| 決裁完了 | `confirmed` | 全ステップ承認済み。他モジュールへの自動連動が発火する。 |
| 管理者取消 | `cancelled` | `hr_admin` / `administrator` が `confirmed` 後に取消。自動ロールバックが発火する。 |
 
#### 差し戻し vs 却下
 
| 操作 | 操作者 | 申請者への影響 | 再申請 |
|---|---|---|---|
| 差し戻し | 承認者 | 理由コメントを通知。編集して再提出できる。 | **可能**（再提出でステップ 1 からフルリスタート） |
| 却下 | 承認者 | 理由コメントを通知。申請は完全終了。 | **不可**（同一申請IDでの再提出不可。別途新規申請は可） |
 
- 差し戻し・却下いずれも**コメント入力を必須**とする。
- 差し戻し後に再提出すると `status = 'pending'`・`current_step = 1` にリセットされ、承認フローが最初から始まる。
#### 取り下げ操作
 
- `pending` または `rejected` ステータスのみで、申請者本人が実行できる。
- `confirmed` 後の取り下げは不可。管理者による取消（`cancelled`）のみ対応する。
- 取り下げ時にコメント入力を任意で求め、`wp_approval_logs` に記録する。
- 取り下げ時点では他モジュールへの連動はまだ発生していないため、ロールバックは不要。
#### 管理者取消（`confirmed` 後の取消）
 
- `hr_admin` / `administrator` のみが実行できる「取消」ボタンを `confirmed` 済み申請に設ける。
- 実行前に「連動先のデータ変更も自動で元に戻されます」という確認ダイアログを表示する。
- 確定後、`status = 'cancelled'` に更新し、自動ロールバックを発火する（→ 4.2 参照）。
---
 
### 3.3. 残業申請の勤怠乖離検知アラート
 
**02_打刻管理モジュール**で `clock_out` が記録された際に以下の処理を行う。
 
1. 当該ユーザー・当該 `work_date` に `confirmed` 状態の残業申請が存在するか確認する。
2. 存在する場合、`clock_out.punched_at` と `wp_approval_meta.overtime_end_time` を比較する。
3. 差異が **30 分以上**（プラグイン設定で変更可能）の場合、Google Chat Webhook 経由でアラートを通知する。
**アラート通知内容：**
```
【残業時間乖離アラート】
申請者 : ○○ ○○
対象日 : 2025年6月15日
申請の予定終了 : 18:00
実際の退勤打刻 : 19:32（＋1時間32分）
勤怠集計は実打刻（02モジュールの打刻ログ）を正として反映しています。
```
 
**通知先：** 申請者・承認済みの承認者（全員）にそれぞれ Webhook 通知。
 
> **実打刻優先の原則：** 残業申請は承認記録（`overtime_approved`フラグ）として 03_勤怠管理モジュールに反映されるが、実際の労働時間計算は 02_打刻管理モジュールの打刻ログを唯一の正とする。
 
---
 
## 4. モジュール間自動連動要件
 
### 4.1. `confirmed` 時の自動連動
 
承認が `confirmed` に遷移した瞬間に以下の連動処理をトランザクション内で実行する。処理失敗時はロールバックし、申請ステータスを `pending`（最終ステップ承認前）に戻してエラーを通知する。
 
**連動実行後、変更内容を `wp_approval_meta` に `meta_key = '_rollback_snapshot'` としてJSON形式で保存する（ロールバック時の復元データとして使用）。**
 
**① 他モジュールへのデータ連動（トランザクション内・失敗時は全体ロールバック）**
 
| 申請種別 | 連動先 | 処理内容 |
|---|---|---|
| 有給休暇 | 03_勤怠 | 対象日の `wp_daily_attendance.attendance_flag = 'paid_leave'` に更新。複数日の場合は全日分。 |
| 有給休暇 | 03_勤怠 | `wp_project_hours` に `is_default_for_paid_leave = 1` の事業で所定時間を自動割り当て（`is_auto_assigned = 1`）。 |
| 有給休暇 | 04_交通費 | 対象日の `wp_travel_expenses.amount = 0`・`is_zero_suppressed = 1` に更新。スタッフの画面に変更通知を表示。 |
| 遅刻・早退 | 03_勤怠 | 対象日の `wp_daily_attendance.note` に遅刻/早退の記録を追記。 |
| 残業申請 | 03_勤怠 | 対象日の `wp_daily_attendance.overtime_approved = 1`・`overtime_approved_minutes` に申請時間を記録。 |
| 経費精算 | 03_勤怠 | 申請に紐付けられた `business_id` と `amount` を事業別経費として `wp_expense_claims` に記録。 |
| 出張手当 | 03_勤怠 | 申請に紐付けられた `business_id` と `amount` を事業別経費として `wp_expense_claims` に記録。 |
| 出張申請 | なし | 記録のみ。他モジュールへのデータ連動なし。 |
 
**② Google Workspace 連携処理（トランザクション外・ベストエフォート）**
 
データ連動トランザクション成功後に実行する。失敗しても `confirmed` 処理をブロックしない。
 
| 申請種別 | 連携先 | 処理内容 |
|---|---|---|
| 有給休暇 | Google Calendar | 申請者のカレンダーに「有給休暇」終日イベントを作成（対象日）。承認者カレンダーにも閲覧専用で共有。Calendar 同期設定が有効な場合のみ実行。 |
| 出張申請 | Google Calendar | 申請者のカレンダーに「出張：目的地」イベントを作成（開始日〜終了日）。承認者カレンダーにも閲覧専用で共有。Calendar 同期設定が有効な場合のみ実行。 |
| 経費精算 | Google Drive | 領収書ファイルを Drive の指定フォルダ（`経費領収書/YYYY年/MM月/`）へアップロード。Drive 保存設定が `google_drive` の場合のみ実行。完了後、Drive URL を `wp_approval_meta.receipt_url` に記録。 |
 
---
 
### 4.2. 自動連動のロールバック
 
差し戻し・取り下げは `confirmed` 前にのみ発生するため連動データは存在せず、ロールバック不要。**ロールバックが必要なのは管理者取消（`confirmed` → `cancelled`）のみ。**
 
#### ロールバック処理フロー
 
1. `_rollback_snapshot`（`wp_approval_meta` に保存済み）を読み込む。
2. 記録された変更を逆順に元の値へ復元する。
3. 全復元処理をトランザクション内で実行。失敗時は中断してエラーを管理者に通知。
4. **Google Workspace のロールバック（ベストエフォート）：** トランザクション成功後にカレンダーイベントの削除を実行する（→ 下記参照）。
#### ロールバック詳細
 
**① 他モジュールのデータ復元**
 
| 申請種別 | ロールバック内容 |
|---|---|
| 有給休暇 | 対象日の `attendance_flag` を `_rollback_snapshot` の値（`none` 等）に戻す。`wp_project_hours` の自動割り当てレコード（`is_auto_assigned = 1`）を削除。`wp_travel_expenses` を元の金額に再計算（`is_zero_suppressed = 0`・`amount` を `distance_km × unit_price` で再算出）。 |
| 遅刻・早退 | `wp_daily_attendance.note` から対象記録を削除。 |
| 残業申請 | `wp_daily_attendance.overtime_approved = 0`・`overtime_approved_minutes = NULL` に戻す。 |
| 経費精算 | `wp_expense_claims` の対象レコードを `is_cancelled = 1` で無効化。 |
| 出張手当 | `wp_expense_claims` の対象レコードを `is_cancelled = 1` で無効化。 |
 
**② Google Workspace のロールバック（ベストエフォート）**
 
| 申請種別 | ロールバック内容 |
|---|---|
| 有給休暇 | `wp_approval_meta.calendar_event_id` に保存したイベント ID を使って Google Calendar の `events.delete` を呼び出し、申請者・承認者のカレンダーからイベントを削除する。 |
| 出張申請 | 同上。 |
| 経費精算（Drive 保存） | Drive ファイルは**削除しない**（証跡保全のため）。管理者に「管理者取消済みの領収書が Drive に残存しています」と通知するに留める。 |
 
---
 
### 4.3. Google Chat 通知トリガー一覧
 
| トリガー | 通知先 | 通知内容 | Gmail フォールバック |
|---|---|---|---|
| 新規申請（`pending`） | 第 1 ステップの承認者 | 申請者名・種別・対象日・申請内容の要約・承認画面への直リンク URL | あり（Tier 2） |
| ステップ承認（途中） | 次ステップの承認者 | 申請内容の要約・承認画面への直リンク URL | あり（Tier 2） |
| 最終決裁完了（`confirmed`） | 申請者 | 決裁完了通知・詳細確認 URL | あり（Tier 2） |
| 差し戻し（`rejected`） | 申請者 | 差し戻し者名・コメント・修正画面への直リンク URL | あり（Tier 2） |
| 却下（`dismissed`） | 申請者 | 却下者名・コメント・詳細確認 URL | あり（Tier 2） |
| 残業乖離アラート | 申請者・全承認者 | 申請時間 vs 実打刻の差分（→ 3.3 参照） | あり（Tier 2） |
| 管理者取消（`cancelled`） | 申請者 | 取消通知・取消理由・詳細確認 URL | あり（Tier 2） |
 
---
 
## 5. 画面要件（UI/UX）
 
> **対応ワイヤーフレーム：** `05_approval_workflow_wireframe.html`（全6画面：①申請・履歴／②承認待ち一覧／③決裁詳細／④稟議管理／⑤承認ルート設定／⑥申請種別・通知設定）。
> デザインは 01〜04 モジュールと共通のデザインシステム（青系プライマリ `#2563EB`、スレートのナビ、事業カラー、カード型 wp-admin レイアウト）に準拠する。
 
#### ステータスバッジの配色（全画面共通）
 
| ステータス | 配色 | 表示 |
|---|---|---|
| 下書き（`draft`） | グレー | ● 下書き |
| 審査中（`pending`） | 青 | ● 審査中 |
| 差し戻し（`rejected`） | オレンジ | ↩ 差し戻し |
| 却下（`dismissed`） | 赤 | ✕ 却下 |
| 取り下げ（`withdrawn`） | グレー | 取り下げ |
| 決裁完了（`confirmed`） | 緑 | ✓ 決裁完了 |
| 管理者取消（`cancelled`） | グレー（破線枠） | 管理者取消 |
 
---
 
### 5.1. スタッフ向け：申請・申請履歴画面（`/portal/approval/`）
 
完全レスポンシブ（スマートフォン対応）。画面上部を **「新規申請」「申請履歴」の2タブ**で切り替える構成とする。「申請履歴」タブには承認待ち・差し戻しの合計件数バッジを表示する。
 
#### 5.1.1. 新規申請タブ
 
**下書き通知バナー**
 
- 未提出の下書き（`status = 'draft'`）が存在する場合、タブ最上部に「保存中の下書きが N 件あります」とバナー表示し、「下書きを開く」ボタンから続きを編集できる。
**申請種別の動的切り替え（→ 3.1）**
 
- 画面上部に大きめの「申請の種類」プルダウンを配置する。選択すると、対象種別専用の入力フォームにリロードなし（WP REST API + JavaScript）で切り替わる。
- 切り替え時に入力済みの内容はクリアし、確認ダイアログ（「入力中の内容はクリアされます。よろしいですか？」）を表示してから切り替える。
- 各種別フォームの入力項目は 2.1 の申請種別マスターおよび 6.4 の `meta_key` 定義に準拠する。
  - **有給休暇：** 区分（全日／半日AM／半日PM／時間休）はピル型ラジオで選択し、「時間休」選択時のみ取得時間帯（開始〜終了）の入力欄を動的に表示する。
  - **経費精算：** 紐付け事業のプルダウンは事業マスタ（`wp_businesses`・03モジュール）から動的生成する。領収書はドラッグ&ドロップ対応のアップロードゾーン（JPEG・PNG・PDF／上限はプラグイン設定値）。
  - **出張手当：** 紐付け出張申請は、同一申請者の `business_trip` 申請を任意でプルダウン選択できる。
**各種別フォームの「連携の説明」ボックス**
 
- 各フォームの末尾に、この申請が承認されると何が起きるかを**スタッフにわかる平易な言葉**で示す情報ボックスを表示する（開発用語は使わない）。
  - 有給休暇：「勤務表のその日が有給になる」「所定労働時間が本社業務に自動割り当て」「その日の交通費が0円になる」「Googleカレンダーに登録される」
  - 経費精算：「選んだ事業の経費として月次集計に計上」「領収書がGoogle Driveに保存」
  - 残業：**「実際の労働時間は打刻ツールの記録が正」「予定終了と実際の退勤打刻が一定時間以上ずれると、申請者と承認者に自動でお知らせが届く」**（→ 3.3・02モジュール連携を明示）
  - 出張：「Googleカレンダーに出張予定が登録される」
**承認ルートプレビュー（→ 01モジュール連携）**
 
- フォーム下部に、この申請に適用される承認ルートをステップ図（横並びステッパー）で表示する。
- 「直属チェック者」「最終管理者」ステップは、申請者本人の `first_approver_id` / `final_approver_id`（01モジュール）を参照し、**承認者の実名・役割を表示**する。各ステップに「マイページの設定から自動」と注記する。
- 「🔒 提出した時点のルートで固定されます」と明示する（→ 8.2）。
- 承認者の変更は人事部への連絡が必要である旨を案内する。
**操作ボタン**
 
- 「下書き保存」：入力内容を `status = 'draft'` で保存。提出はしない。
- 「提出する」：バリデーション後 `status = 'pending'` に更新し、提出時点の承認ルートを `total_steps` にスナップショット（→ 2.2）。第 1 ステップ承認者に Google Chat 通知を送信する。
#### 5.1.2. 申請履歴タブ
 
- 過去の申請一覧をタイムライン形式で表示し、各行の左端にステータス色のカラーバーを付す。各申請のステータスを上記配色のカラーバッジで視覚化する。
- 各申請行を展開（アコーディオン）すると以下を確認できる。
  - 申請内容（フォーム入力の全項目）
  - 差し戻し・却下時は、差し戻し者名とコメントをアラートボックスで強調表示
  - 承認ルートの進捗（完了ステップは緑チェック、現在ステップは青ハイライト）
  - 承認ログ（誰がいつ承認/差し戻し/提出したか、コメント、システムによる乖離アラート送信記録を含む時系列）
- `pending` / `rejected` の申請には「取り下げる」ボタンを表示する。
- `rejected` の申請には「修正して再提出」ボタンを表示し、修正フォームへ遷移する（再提出で `status = 'pending'`・`current_step = 1` にリセット）。
- **Google Calendar 同期が有効な場合：** `confirmed` 済みの有給・出張申請行に「📅 カレンダー登録済み」バッジを表示する。
- **Drive 保存が有効な場合：** `confirmed` 済みの経費精算申請行に「📁 Drive 保存済み」バッジと Drive リンクを表示する。
---
 
### 5.2. 承認者向け：稟議確認・決裁画面（`/portal/approval/review/`）
 
#### 5.2.1. 承認待ち一覧（②画面）
 
- アクセスすると、自分が**承認ルート上の「現在のステップ」の承認者になっている**「承認待ち」申請の一覧が表示される。
- 画面上部にサマリーカードを表示する：「あなたへの承認待ち件数」「差し戻し中（自分起因）件数」「今月決裁した件数」。
- 一覧の列：申請者（アバター付き）・種別・対象日・要約・現在ステップ（N/総数）・提出日・「確認・決裁」ボタン。
- **残業の打刻乖離があった申請など、注意喚起が必要な行は背景を強調**する。
- 一覧に表示される承認者の決定根拠（部下の確認者／最終承認者設定〔01モジュール〕、または承認ルートでの個別指定）を画面下部に注記する。
#### 5.2.2. 決裁詳細（③画面）
 
申請内容（左カラム）と決裁アクションパネル（右カラム・スクロール追従）の2カラム構成とする。
 
- **左カラム：**
  - 申請内容（フォーム入力の全項目を定義リスト形式で表示）
  - 添付ファイル（領収書画像等）のプレビュー。Drive 保存の場合は Drive へのリンクボタンを表示。
  - **残業申請の打刻乖離ボックス（→ 3.3・02モジュール連携）：** `confirmed` 済み残業の有無に関わらず、申請の予定終了時刻と実際の退勤打刻（02モジュールの `clock_out`）を左右に並べ、差分を赤系で表示する。「勤怠の労働時間は実打刻を正として集計する」「この残業申請は承認の記録として扱う」旨を明記する。
  - 承認ルートの進捗（ステップ図）と承認ログ（前ステップのコメント・システムの乖離アラート送信記録を含む）
- **右カラム（決裁アクションパネル）：**
  - コメント入力欄（複数行）
  - 「✓ 承認する（次のステップへ）」：コメント任意。次ステップへ進む（最終ステップの場合は `confirmed` へ）。
  - 「↩ 差し戻す（修正を依頼）」：コメント必須。`rejected` へ遷移。
  - 「✕ 却下する（再申請不可）」：コメント必須。`dismissed` へ遷移。
  - 差し戻し・却下を選んだ際は「コメントの入力が必須です」と注意表示する。
  - 各ボタンの結果（誰へ進むか・申請者が再提出できるか等）を平易な言葉で補足表示する。
---
 
### 5.3. 管理者向け：全申請俯瞰管理画面（wp-admin 内・④画面）
 
メニュー階層：`社員管理 > 稟議管理`
 
- 画面上部にサマリーカードを表示する：「承認待ち（全社）」「差し戻し中」「今月決裁完了」「管理者取消（ロールバック済み）」。
- フィルタ：申請種別 / ステータス / 申請者（氏名・社員番号）/ 対象期間（開始〜終了）。
- 一覧の列：申請ID・申請者・種別・対象日・金額/区分・ステータス・現在ステップ・連携バッジ・操作。
- 連携バッジの例：「⚠ 打刻乖離」（残業）・「📅 登録済」（カレンダー）・「📁 Drive保存済」・「📁 Drive残存」（取消後にファイルが残存）。
- 各申請の詳細・承認ログ・ロールバック状態を管理者権限で閲覧・操作できる。
- `confirmed` 申請に「取消」ボタンを設ける（`hr_admin` / `administrator` のみ表示）。押下時に**確認モーダル**を表示し、「連動先のデータ変更も自動で元に戻されます」と、戻る内容（有給フラグ解除・自動割り当て事業時間の削除・交通費の再計算・カレンダーイベント削除）を箇条書きで明示してから実行する（→ 4.2）。
- すべての申請（取消・却下・取り下げを含む）は監査証跡として永続保持され、物理削除できない旨を画面に注記する（→ 8.4）。
---
 
### 5.4. 管理者向け：承認ルート設定画面（wp-admin 内・⑤画面）
 
メニュー階層：`社員管理 > 承認ルート設定`（`administrator` のみ。マスター要件は → 2.2）
 
- 申請種別ごとにカード（`route-edit`）を表示し、カードヘッダーのトグルで当該種別の有効/無効を切り替える。
- 各承認ステップを「ステップカード」で縦に並べる。ステップカードはステップ番号・承認者種別プルダウン（「申請者の確認者（直属上長）」「申請者の最終管理者」「特定の人を指名」）・削除ボタンで構成する。
  - 「特定の人を指名」選択時のみ、ユーザー選択プルダウンを横に表示する。
  - 「申請者の確認者／最終管理者」選択時は、実行時に `first_approver_id` / `final_approver_id` を参照する旨を「自動参照」タグ付きで補足表示する。
- 「＋ ステップを追加」ボタンでステップを任意数だけ追加・削除できる。
- 画面に「ルートを変更しても提出済みの申請には影響しない（提出時点のルートが固定される）」旨を注記する（→ 8.2）。
---
 
### 5.5. 管理者向け：申請種別・通知設定画面（wp-admin 内・⑥画面）
 
メニュー階層：`設定`（各マスター要件は → 2.1・2.3・2.4・2.5）。以下の4ブロックを1画面に集約する。
 
- **申請種別の管理（→ 2.1）：** 種別を行リストで表示し、ドラッグで並べ替え・トグルで有効/無効を切り替える。各行に `type_code` をモノスペースで表示。「＋ 種別を追加」ボタンを設ける。
- **通知設定（Google Chat／→ 2.3・3.3）：** 共通の通知先 Webhook URL・種別ごとの通知先分割トグル・メール代替送信（Gmail フォールバック）トグルと送信元アドレス・**残業の乖離アラートしきい値（分・既定30）** を配置する。ラベルは平易な日本語とする（例：「通知先のチャットルーム URL」）。
- **領収書ファイルの保存先（→ 2.4）：** 保存先（Google Drive / ローカル）・Drive 保存先フォルダ ID・ファイルサイズ上限・許可形式を配置する。
- **カレンダー同期設定（→ 2.5）：** 同期の有効/無効トグル・同期対象種別（有給休暇・出張のチェックボックス）を配置する。
- サービスアカウントキーは暗号化保存され外部に公開されない旨を画面に注記する（→ 8.7）。
---
 
## 6. データベース設計（独自テーブル）
 
### 6.1. `wp_approval_types`（申請種別マスタ）
 
```sql
CREATE TABLE wp_approval_types (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    type_code   VARCHAR(50)  NOT NULL UNIQUE,
    type_name   VARCHAR(100) NOT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order  INT          NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```
 
### 6.2. `wp_approval_routes`（承認ルートマスタ）
 
```sql
CREATE TABLE wp_approval_routes (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    approval_type_id  INT             NOT NULL,   -- wp_approval_types.id
    step_order        INT             NOT NULL,   -- ステップ番号（1始まり）
    approver_type     ENUM('specific_user','first_approver','final_approver') NOT NULL,
    approver_user_id  BIGINT UNSIGNED DEFAULT NULL, -- specific_user の場合のみ使用
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type_step (approval_type_id, step_order)
);
```
 
### 6.3. `wp_approvals`（稟議親テーブル）
 
```sql
CREATE TABLE wp_approvals (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          BIGINT UNSIGNED NOT NULL,    -- 申請者 wp_users.ID
    approval_type_id INT             NOT NULL,    -- wp_approval_types.id
    status           ENUM('draft','pending','confirmed','rejected',
                          'dismissed','withdrawn','cancelled') NOT NULL DEFAULT 'draft',
    current_step     INT             NOT NULL DEFAULT 1,
    total_steps      INT             NOT NULL DEFAULT 1,  -- 提出時に確定・固定
    submitted_at     DATETIME        DEFAULT NULL,
    confirmed_at     DATETIME        DEFAULT NULL,
    cancelled_at     DATETIME        DEFAULT NULL,
    cancelled_by     BIGINT UNSIGNED DEFAULT NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_status (user_id, status),
    INDEX idx_type_status (approval_type_id, status)
);
```
 
### 6.4. `wp_approval_meta`（稟議カスタムフィールド・縦持ち）
 
```sql
CREATE TABLE wp_approval_meta (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    approval_id  INT          NOT NULL,    -- wp_approvals.id
    meta_key     VARCHAR(100) NOT NULL,
    meta_value   LONGTEXT     DEFAULT NULL,
    INDEX idx_approval_key (approval_id, meta_key)
);
```
 
**主要な `meta_key` 一覧（申請種別ごと）：**
 
| 申請種別 | meta_key 例 |
|---|---|
| 有給休暇 | `leave_start_date`, `leave_end_date`, `leave_type`（full/half_am/half_pm/hourly）, `leave_hours`, `reason` |
| 遅刻・早退 | `target_date`, `late_early_type`（late/early）, `expected_time`, `reason` |
| 経費精算 | `expense_date`, `payee`, `amount`, `receipt_url`, `business_id`, `purpose` |
| 残業 | `target_date`, `overtime_start_time`, `overtime_end_time`, `reason` |
| 出張 | `trip_start_date`, `trip_end_date`, `destination`, `purpose`, `estimated_cost` |
| 出張手当 | `period_start`, `period_end`, `business_trip_approval_id`（任意）, `allowance_amount`, `business_id`, `description` |
| 共通（連動管理） | `_rollback_snapshot`（JSON：連動時に変更した各モジュールのデータの before/after を記録） |
| 共通（Google Workspace） | `calendar_event_id`（JSON：申請者・承認者ごとのイベント ID を保持。ロールバック時の削除に使用）, `drive_file_id`（Drive にアップロードしたファイルの ID） |
 
### 6.5. `wp_approval_logs`（承認ログ・監査証跡）
 
```sql
CREATE TABLE wp_approval_logs (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    approval_id  INT             NOT NULL,    -- wp_approvals.id
    step_order   INT             NOT NULL,
    approver_id  BIGINT UNSIGNED NOT NULL,    -- 操作者 wp_users.ID
    action       ENUM('step_approved','final_approved','rejected',
                      'dismissed','withdrawn','cancelled') NOT NULL,
    comment      TEXT            DEFAULT NULL,  -- 差し戻し・却下・取消時は必須
    acted_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_approval_id (approval_id)
);
```
 
**本テーブルのレコードは削除不可（監査証跡）。**
 
### 6.6. `wp_expense_claims`（経費精算・出張手当 事業別集計用）
 
経費精算・出張手当の `confirmed` 時に生成される事業別集計レコード。03_勤怠管理モジュールの事業別経費集計画面から参照される。
 
```sql
CREATE TABLE wp_expense_claims (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    approval_id  INT             NOT NULL,    -- wp_approvals.id
    user_id      BIGINT UNSIGNED NOT NULL,
    business_id  INT             NOT NULL,    -- 03 wp_businesses.business_id
    year_month   CHAR(7)         NOT NULL,    -- 'YYYY-MM'（対象月）
    amount       INT             NOT NULL,    -- 金額（円）
    claim_type   ENUM('expense','trip_allowance') NOT NULL,
    is_cancelled TINYINT(1)      NOT NULL DEFAULT 0,  -- 1 = 管理者取消でロールバック済み
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_business_month (business_id, year_month),
    INDEX idx_user_month     (user_id, year_month)
);
```
 
---
 
## 7. 他モジュールとの連携定義
 
### 7.1. ← 01_ユーザー管理モジュール（参照元）
 
| 参照データ | 用途 |
|---|---|
| `first_approver_id` | 承認ルートの「直属チェック者」ステップで実行時に参照 |
| `final_approver_id` | 承認ルートの「最終管理者」ステップで実行時に参照 |
| ロール（`approver` / `hr_admin` 等） | 承認・却下操作の権限判定に使用 |
 
### 7.2. → 02_打刻管理モジュール（提供先・受信元）
 
| 連携 | 用途 |
|---|---|
| `clock_out` イベントを受信 | 残業申請の乖離検知アラートのトリガー（→ 3.3 参照） |
 
### 7.3. → 03_勤怠管理モジュール（提供先）
 
| 提供データ | 用途 |
|---|---|
| 有給確定通知 | `wp_daily_attendance.attendance_flag` の自動更新 |
| 残業確定通知 | `wp_daily_attendance.overtime_approved` の自動更新 |
| 経費精算・出張手当確定 | `wp_expense_claims` への記録（事業別経費集計に使用） |
| `wp_businesses`（03定義）を参照 | 経費精算・出張手当申請フォームの事業選択肢に使用 |
 
### 7.4. → 04_交通費モジュール（提供先）
 
| 提供データ | 用途 |
|---|---|
| 有給確定通知（対象日） | `wp_travel_expenses` の通勤レコードを `amount = 0`・`is_zero_suppressed = 1` に自動更新 |
| ロールバック通知 | `wp_travel_expenses` を元の金額に復元 |
 
### 7.5. → Google Workspace 連携
 
| 連携先 | 連携内容 | タイミング | 優先度 |
|---|---|---|---|
| Google Calendar API | 有給・出張申請の `confirmed` 時に申請者・承認者のカレンダーへイベント作成。`cancelled` 時にイベント削除（→ 2.5・4.1・4.2 参照） | `confirmed` / `cancelled` 時 | Tier 1 |
| Google Drive API | 経費精算申請の領収書を Drive の指定フォルダへ自動保存（→ 2.4・4.1 参照） | `confirmed` 時 | Tier 1 |
| Google Chat Webhook | 全申請種別の承認イベント・差し戻し・却下・乖離アラートをリアルタイム通知（→ 2.3・4.3 参照） | 各ステータス変化時 | Tier 1 |
| Gmail API | Chat Webhook 失敗時のフォールバックメール通知（→ 2.3・8.5 参照） | Chat 3回失敗時 | Tier 2 |
| Google Forms + GAS | 外部スタッフ（WordPress アカウントなし）向けの申請フォームブリッジ（→ 3.4 参照） | フォーム送信時 | Tier 3 |
 
---
 
## 8. 非機能要件
 
### 8.1. 自動連動のトランザクション保証
 
`confirmed` 時の他モジュールへの連動処理（→ 4.1①）は、すべてデータベーストランザクション内で実行する。いずれか 1 つの書き込みが失敗した場合は全体をロールバックし、申請ステータスを承認直前の状態に戻してエラーを管理者に通知する。
 
Google Workspace 連携処理（→ 4.1②）はトランザクション外でベストエフォートで実行するため、失敗しても `confirmed` 処理自体をロールバックしない。
 
### 8.2. 承認ルートの変更と進行中案件の保護
 
管理者が承認ルートを変更しても、すでに `pending` 以降のステータスにある申請には影響しない。`wp_approvals.total_steps` と `wp_approval_logs` に提出時点のルート情報が固定されているため、新ルートは変更後に新規提出された申請にのみ適用される。
 
### 8.3. 添付ファイルのセキュリティ
 
- **ローカル保存時：** アップロードされたファイルには PHP 実行を無効化した `.htaccess` ルールを適用する。ファイルへの直接 URL アクセスは WordPress の権限チェックを介したプロキシ経由でのみ許可し、申請の閲覧権限を持たないユーザーがファイルを直接取得できないようにする。
- **Drive 保存時：** サービスアカウントのみが所有者となるフォルダに保存し、社員個人の Google アカウントからは直接参照できないよう Drive の共有設定を制限する。管理者・承認者への共有は Drive リンク経由で行い、リンクは `wp_approval_meta.receipt_url` に保持する。
### 8.4. データ保持
 
- `wp_approvals`・`wp_approval_meta`・`wp_approval_logs` は `cancelled` / `dismissed` / `withdrawn` を含むすべての申請を永続保持する。
- 物理削除は管理画面から実行不可とする。
### 8.5. Gmail フォールバック通知の信頼性（Tier 2）
 
- Google Chat Webhook が 3 回リトライ後も失敗した場合、Gmail API（`users.messages.send`）をサービスアカウント（ドメイン全体委任設定済み）で呼び出し、`noreply@ims-hirosaki.com` からメール通知を代替送信する。
- メール件名・本文は Google Chat 通知と同等の内容（申請者・種別・対象日・承認画面 URL）とする。
- フォールバック発動時はシステムログに記録し、管理者にもシステム通知を発行する。
- Gmail フォールバックも失敗した場合はシステムログに記録するに留め、承認フロー自体はブロックしない。
- ドメイン全体委任が未設定の場合は Gmail フォールバックを無効にし、Chat 通知のみとする。
### 8.6. Google Calendar 連携の信頼性
 
- カレンダーイベントの作成・削除は最大 3 回リトライする（リトライ間隔: 指数バックオフ）。
- 作成成功時はイベント ID を `wp_approval_meta.calendar_event_id` に JSON 形式で記録する（申請者・各承認者ごとのイベント ID を保持）。
- ロールバック時のイベント削除は `calendar_event_id` を参照して実行する。イベント ID が未記録の場合（作成失敗時）は削除をスキップする。
- Calendar 連携の失敗は `confirmed` / `cancelled` 処理自体をブロックしない（ベストエフォート）。
### 8.7. Google Drive 連携の信頼性
 
- Drive アップロードは最大 3 回リトライする（リトライ間隔: 指数バックオフ）。
- アップロード失敗時はローカル保存にフォールバックし、管理者に「Drive 保存失敗・ローカルに保存されました」と通知する。
- サービスアカウントキー（JSON）は WordPress の `wp_options` テーブルに暗号化して保存し、Git リポジトリにはコミットしない。
---
 
## 9. 外部フォームブリッジ（Tier 3）
 
> **本機能は将来的な発展機能（Tier 3）として位置づける。** 初期リリース時点では手動運用とし、運用成熟後（目安：運用開始から6ヶ月以降）に実装を検討する。
> **対象：** WordPress アカウントを持たない社外スタッフ（パート・業務委託等）向けに、Google Forms で申請を受け付け、GAS 経由で本システムの REST API へ自動転送する。
 
#### フロー
 
```
社外スタッフ → Google Forms に入力・送信
  → GAS がフォーム回答をトリガーに本システムの REST API を呼び出し
  → wp_approvals に申請レコードを作成
  → 以降は通常の承認フローで処理（Google Chat 通知・Calendar 同期含む）
```
 
#### 対象申請種別
 
外部ブリッジで受け付けるのは以下に限定する（センシティブ情報を扱う申請は対象外）。
 
- 有給休暇申請
- 遅刻・早退申請
- 残業申請
#### 実装上の注意
 
- フォーム回答の送信者認証は Google アカウント（`@gmail.com` 含む）ログインを必須とし、メールアドレスで本システムの `wp_users.user_email` と突合する。突合できない場合は申請レコードを作成せず、フォーム送信者にエラーメールを送信する。
- GAS から本システムへのリクエストには API キー認証（専用キーを `wp_options` に保持）を使用し、外部からの不正リクエストを防ぐ。
- `auth_type = password` のユーザー（Google Workspace アカウントなし）が主な利用対象となるが、`auth_type = google_sso` のユーザーも利用可能とする（例：スマートフォンからの手軽な申請用途）。
 