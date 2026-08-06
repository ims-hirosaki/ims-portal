# IMS Hirosaki 実装 引き継ぎ書（Phase 2c 完了 / 打刻API）

- 対象プラグイン：`ims-portal`
- バージョン：**0.5.2-phase2c**（DB_VERSION = 3・**変更なし**）
- 作成日：2026-08-06
- 前提：Phase 2b（打刻コンソール表示まで / 0.5.1）の続き。前回の引き継ぎ書は `引き継ぎ書_phase2b.md`

---

## 0. このドキュメントの使い方（新しいチャットで最初に読む）

1. 現在地＝**モジュール02（打刻ツール）の 2c まで完了**。打刻が**実際に記録できる**ようになった。
   ただし **休憩中の退勤（ケースA）と6時間超の休憩確認（ケースB）は未実装**（次は 2d）。
2. ソースコードは GitHub `git-ims-shiroto/ims-portal`（main）から取得。`main` への push で自動デプロイ（§5）。
3. 要件定義書（`00`〜`08`）が**常にコードより優先される正本**。モジュール02 の正本は `02_time_tracking.md`。
   - ローカルの実体：`C:\Users\user\Documents\deveropments\Internal-tools\02_time_tracking.md`
4. 次にやることは §8 を参照（**2d：退勤補完ロジック**）。

---

## 1. プロジェクト概要

- 日本の組織向け社内業務グループウェア「IMS Hirosaki」。WordPress プラグイン群として構築。
- 構成モジュール：
  - **00** ポータルダッシュボード
  - **01** 社員管理 ✅ 完成
  - **02** 打刻ツール ← **いまここ（2c まで完了）**
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
- コアはモジュールに依存しない。モジュール側が**フック経由で自己登録**する。

### 実装で判明したコード規約（08 の記載と実装が食い違う点。**実装に合わせること**）

- **`Router::register()` はスラッグ文字列のみ**を取る。フックに渡ってくるのは**クラス名の文字列**。
- **`TileRegistry::add()` の `caps` は capability 名**（`ims_use_portal` など）。ロール名ではない。
- コアの `Assets` は**モジュール固有CSS/JSを読み込まない**。各モジュールが自前 enqueue する。
- **`ims-portal.php` の `boot()` が各モジュールの `Bootstrap::init()` を直接呼ぶ**（コア無改修の唯一の例外）。
- **REST の nonce はコアが検証する**。`Assets` が `imsPortal.nonce`（`wp_rest`）をフロントへ渡し、
  JS は `X-WP-Nonce` ヘッダーで送る。**エンドポイント側で `check_ajax_referer` を重ねる必要はない**
  （nonce 無し → コアが current_user を 0 にする → `permission_callback` が落として 401。
  nonce 不正 → コアが 403）。2c の `RestController` はこの前提で書いてある。

---

## 3. 実装済みの状態

### モジュール01（社員管理）✅ 完成（0.4.7 / Phase 1d 時点）

### モジュール02（打刻ツール）── 2a・2b・2c 完了

| スライス | 内容 | Ver |
|---|---|---|
| **2a** | 打刻モジュール骨格。`attendance_logs` / `attendance_corrections` の2テーブル（**DB_VERSION 2→3**）。ポータル設定に「打刻システム設定」。Google Chat Webhook 通知（`ChatNotifier`） | 0.5.0 |
| **2b** | 打刻コンソール `/portal/timecard/`（表示のみ）。`StatusCalculator` / `Repository`（読み取り） | 0.5.1 |
| **2c** | **打刻API**。REST `POST ims/v1/timecard/punch`。サーバー時刻強制・5秒重複ガード・退職者拒否・状態遷移検証・IP/GPS記録・`clock_out` 時のアクションフック。コンソールのボタンを実接続（リロードなしで再描画） | 0.5.2 |

#### ✅ 動作確認済み
- 2a：打刻システム設定画面（3セクションの保存・表示、テーブル2件の作成）── 実機
- 2c：`StatusCalculator` の状態遷移 **43項目のスモークテスト全パス**（下記 §7）── ローカル
- 全49 PHPファイルの `php -l` パス（ローカルは PHP 8.2。本番は 8.3）

#### ⚠ 未確認（次チャットの冒頭で確認するとよい）
- **2b の実機表示**（`/portal/timecard/` が開くか）── 2b 時点から持ち越し。まだ未確認。
- **2c の実機打刻**（下記 §7 の確認手順を実施すること）。
- **Webhook のテスト送信**（Google Chat ルームへの実送信）。

---

## 4. 現在のファイル構成（0.5.2）

```
ims-portal/
├── ims-portal.php                    # VERSION 0.5.2-phase2c / DB_VERSION 3
├── .github/workflows/deploy.yml      # ★ push で本番へFTP同期。触らない
├── assets/
│   ├── css/ … timecard.css           ← 2c で送信中/成功/失敗の表示を追加
│   └── js/  … timecard.js            ← 2c で打刻API接続に書き換え
└── src/
    ├── Core/      Assets / AuthGuard / DashboardPage / EnvironmentCheck / Installer /
    │              Layout / LoginPagePlaceholder(※未使用) / Roles / Router /
    │              SummaryAggregator / TileRegistry
    ├── Support/   Capabilities / Crypto / UserRepository / Google/…
    ├── Module/User/  … モジュール01（変更なし）
    └── Module/Timecard/
        ├── Bootstrap.php             # RestController::init() を追加（2c）
        ├── Schema.php                # 2表のDDL（変更なし）
        ├── Settings.php              # 打刻システム設定（変更なし）
        ├── AdminSettingsPage.php     # ポータル設定＞打刻システム設定（変更なし）
        ├── StatusCalculator.php      # ＋ validate_transition() / error_message()（2c）
        ├── Repository.php            # ＋ insert_punch() / has_recent_punch() / open_shift_date()（2c）
        ├── PunchService.php          # ★新規（2c）打刻の業務ロジック
        ├── RestController.php        # ★新規（2c）REST の入出力のみ
        └── TimecardPage.php          # ボタンを実接続・退職者対応（2c）
```

---

## 5. 2c の設計判断（次の実装者が知っておくべきこと）

### 責務の分け方
- **`RestController`** … HTTP だけ。権限は `permission_callback`、業務判断は一切持たない。
- **`PunchService`** … 業務判断の唯一の置き場。2d/2e/2f はここのメソッドを再利用すること。
- **`StatusCalculator`** … DB も WP も触らない純粋ロジック。テストはここに書く。
- **`Repository`** … SQL だけ。渡された値を安全に読み書きするのみ。

### `validate_transition()` を追加した（StatusCalculator）
`active_buttons()`（UI用）の裏返しをサーバー側検証として実装。**UI とサーバーが同じ関数を見る**ので
食い違いが起きない。戻り値はエラーコード文字列で、`error_message()` が日本語文言に変換する。

| 現在状態 | clock_in | break_in | break_out | clock_out |
|---|---|---|---|---|
| 出勤前 | ok | not_clocked_in | not_clocked_in | not_clocked_in |
| 勤務中 | already_clocked_in | ok | not_on_break | ok |
| 休憩中 | already_clocked_in | already_on_break | ok | **break_completion_required** |
| 退勤後 | already_clocked_out | already_clocked_out | already_clocked_out | already_clocked_out |

> **`break_completion_required` が 2d の入口。** 要件 §3.2 ケースA（休憩中の退勤 → 休憩補完ポップアップ）は
> 2レコードのトランザクション保存が必要なため 2c では実装せず、**この専用コードで 409 を返して**
> 「先に休憩終了を打刻してください」と案内している。2d ではこのコードを受けて補完UIへ分岐させる。
> ※つまり 2c 時点では、休憩中に退勤したいユーザーは「休憩終了 → 退勤」の2操作が必要。

### `work_date`（帰属日付）の決め方 ── **要件より一歩踏み込んでいる。要確認**
`PunchService::resolve_work_date()` に集約した。

- **出勤（clock_in）は常に打刻日**（要件 §2.1① の明文どおり）。
- **休憩・退勤は日付境界時刻（`date_boundary_hour`）を適用**し、境界より前の深夜帯なら前日に寄せる。
  - 要件が境界を名指しするのは**退勤だけ**だが、休憩にも同じ規則を当てている。
    そうしないと 0 時をまたいだ休憩打刻だけが翌日の孤立レコードになり、状態機械が壊れるため。
  - **既定値 `0` ではどちらの規則でも「打刻日」になる**ので、既定運用での挙動は要件と完全一致。
- さらに、**「開いている勤務」（出勤済み・退勤未）が当日か境界日付にあれば、そちらを優先**する。
  境界時刻より後に始まる深夜シフト（例：境界4時に対し2時出勤）でも出勤と退勤の work_date が揃う。
- **画面も同じ関数を通す**（`PunchService::console_work_date()`）。2b では画面が暦日ベースだったため、
  深夜帯に「画面は出勤前・APIは勤務中」とズレる余地があった。2c でこれを解消し、
  `Repository::logs_for_today()` は**削除**した（「今日」の定義が2つあると必ず事故るため）。

### セキュリティ上の判断
- **IPアドレスは `REMOTE_ADDR` のみ**を記録する。`X-Forwarded-For` 等のプロキシヘッダーは**意図的に見ない**
  （クライアントが詐称できる値を監査証跡に残すと意味が薄れるため）。
  → もし本番がCDN/リバースプロキシ配下で `REMOTE_ADDR` がプロキシIPになる場合、
    信頼できるプロキシを明示したうえで XFF を読む改修が必要。**実機で記録されたIPを一度確認すること。**
- **対象ユーザーはリクエストではなくログインセッションから取る**（`get_current_user_id()`）。
  リクエストで user_id を受け取らないので、なりすまし打刻の余地がない。
- **退職者拒否は他のどの判定よりも先**に行う（要件 §6.1）。画面の非活性は信用しない。
- 5秒重複ガード（要件 §7.1）は `work_date` で絞らず**実時刻のみ**で判定する（境界をまたぐ連打も弾くため）。

### 他モジュール向けフック（2c で撃つようにした）
```php
do_action('ims_timecard_punched',     $user_id, $punch_type, $work_date, $punched_at, $log_id);
do_action('ims_timecard_clocked_out', $user_id, $work_date, $punched_at, $log_id);
```
- `ims_timecard_clocked_out` は **05_稟議モジュールの残業乖離検知の受け口**。05 側は本モジュール無改修で拾える。
- `ims_timecard_punched` は 04_交通費（出勤打刻のある日＝通勤費発生日）が使える。

---

## 6. APIリファレンス（2c）

### `POST /wp-json/ims/v1/timecard/punch`

**認証：** Cookie ＋ `X-WP-Nonce`（`wp_rest`）。権限 `ims_use_portal`。

**リクエスト（JSON）:**
| キー | 必須 | 型 | 備考 |
|---|---|---|---|
| `punch_type` | ✅ | string | `clock_in` / `break_in` / `break_out` / `clock_out` のいずれか。他は 400 |
| `gps_latitude` | – | number | オプトイン。範囲外・欠落は NULL 保存 |
| `gps_longitude` | – | number | 同上 |

> **時刻は送らない。** サーバーが `current_time('mysql')` で決める（要件 §3.1）。

**成功（201）:**
```json
{
  "ok": true,
  "message": "出勤を記録しました。",
  "punch": { "log_id": 12, "punch_type": "clock_in", "punched_at": "2026-08-06 09:00:12",
             "time": "09:00", "work_date": "2026-08-06" },
  "state": { "work_date": "2026-08-06", "status": "working", "status_label": "勤務中",
             "status_variant": "working", "active": ["break_in","clock_out"],
             "worked_seconds": 0, "worked_label": "0時間 0分", "server_now": 1785...}
}
```

**エラー:**
| HTTP | code | 意味 |
|---|---|---|
| 401 | – | 未ログイン（nonce 無しを含む） |
| 403 | – | nonce 不正 / `ims_use_portal` なし |
| 403 | `retired` | 退職者 |
| 409 | `duplicate` | 同一種別が直近5秒以内にある |
| 409 | `already_clocked_in` / `already_clocked_out` / `already_on_break` / `not_clocked_in` / `not_on_break` | 状態遷移エラー |
| 409 | `break_completion_required` | **休憩中の退勤 → 2d で補完UIへ分岐させる** |
| 400 | `unknown_punch_type` | 種別不正（通常はスキーマ検証が先に弾く） |
| 500 | `db_error` | INSERT 失敗 |

> **409 のときはレスポンスに `state` も含まれる。** フロントはこれで画面を正しい状態へ戻す
> （リロードせずに復帰させるため）。

### フロント（`assets/js/timecard.js`）の挙動
- 押下直後に**全ボタンを disabled**、`#tc-feedback` に「送信中…」を表示（要件 §4.1）。
- 成功：バッジ・実勤務時間・ボタン活性・**打刻履歴の当日行**をその場で更新（リロードなし）。
- 通信失敗：「打刻は記録されていません」と明示してボタンを復帰（誤認防止・要件 §4.1）。
- GPS：**スマホ（`pointer: coarse` かつ `maxTouchPoints > 0`）のその日の初回打刻時のみ**許可を求める。
  PCでは試みない。拒否・失敗・5秒タイムアウトでも打刻は続行する（要件 §4.1）。

---

## 7. 動作確認 ── **次チャットの冒頭でやること**

### ローカルで確認済み
- `StatusCalculator` スモークテスト **43項目全パス**（状態遷移の全組み合わせ／実労働時間／
  `active_buttons` と `validate_transition` の整合）。
- 日付演算（5秒窓の日またぎ・月初・年初・うるう年 2028-02-29）の丸め確認。
- 全49 PHPファイル `php -l` パス。JS は `node --check` パス。

### 実機で確認すること（デプロイ後）
1. `/portal/timecard/` が開く（開かなければ**設定＞パーマリンクを変更せず保存1回**）。
2. **出勤 → 休憩開始 → 休憩終了 → 退勤**を順に押し、
   - ボタン活性がその都度切り替わるか（リロードなしで）
   - バッジが 出勤前→勤務中→休憩中→勤務中→退勤済 と変わるか
   - 打刻履歴の当日行に時刻が入るか
   - 実勤務時間が勤務中だけ進み、休憩中は止まるか
3. **連打**（出勤を2回素早く押す）→ 409 になり二重登録されないこと。
4. **状態違反**（開発者ツールから `break_out` を出勤前に直接POST）→ 409 と日本語メッセージ。
5. phpMyAdmin で `wp_attendance_logs` を見て、**`ip_address` に妥当なグローバルIPが入っているか**確認
   （§5 の注意参照。プロキシ配下だと社内IPやプロキシIPになる）。
6. スマホから初回打刻 → 位置情報ダイアログが出るか。拒否しても打刻できるか。
7. 退職者アカウントでログイン…は AuthGuard に弾かれるはずなので、
   代わりに **在籍者で打刻 → 管理画面で退職に変更 → 再度打刻して 403** を確認するとよい。

> **DB_VERSION は 3 のまま（変更なし）**。今回はマイグレーション不要。

---

## 8. 次にやること

### 推奨する次の着手先：**モジュール02 の 2d（退勤補完ロジック）**

要件 §3.2。2c が `break_completion_required` を返すところが入口になっている。

- **ケースA（休憩中に退勤）**：休憩補完ポップアップ。
  - 選択可能な最大分数 = `floor((現在時刻 − 最終 break_in) の分数)`
  - 「45分」は最大≥45、「60分」は最大≥60 のときだけ表示。自由入力は常に表示し超過はリアルタイムエラー。
  - どれも出せない（＝休憩開始直後）なら自由入力のみ＋「最大○分まで」の案内。
  - 確定時：`break_out`（`is_auto_filled = 1`・時刻＝最終break_in＋選択分数）と `clock_out` を
    **1つのDBトランザクションで保存**。片方でも失敗したらロールバック。
- **ケースB（休憩を一度も打刻せず退勤）**：
  - 実勤務 6時間以下 → そのまま退勤。6時間超 → 「休憩は取りましたか？」確認 → ケースAのUIか、休憩なし退勤。
- 実装場所：`PunchService` に `punch_with_break_completion()` 相当を足し、
  `RestController` にエンドポイントを追加する（既存の `punch` は壊さない）。
  トランザクションは `$wpdb->query('START TRANSACTION')` / `COMMIT` / `ROLLBACK`
  （**テーブルが InnoDB であることを先に確認すること**）。

### 2d 以降のスライス計画
| # | 内容 | リスク | 想定Ver |
|---|---|---|---|
| 2d | 退勤補完ロジック（ケースA/B）。2レコードのトランザクション保存 | **高** | 0.5.3 |
| 2e | 打刻修正（`staff_correction_level` の3レベル制御・修正履歴記録・整合性チェック） | **高** | 0.5.4 |
| 2f | wp-admin 社員管理＞打刻ログ照会（検索・展開・管理者修正） | 中 | 0.5.5 |
| 2g | ダッシュボード連携（タイル登録・summary 寄与・ステータスカード） | 低 | 0.5.6 |

### 他モジュール依存で保留中（再掲）
- 残業乖離アラートの検知本体 → **05 実装後**に `ims_timecard_clocked_out` へ接続（受け口は 2c で用意済み）
- 締め後ロック → **03 の `wp_monthly_summary` 作成後**。判定を1メソッドに閉じ込め、テーブル不在時は
  「ロックなし」を返す方針（2e で実装する）。

---

## 9. 作業の進め方（この会話で確立した進行様式）

1. **要件定義書を先に精読**してから実装する（コードより要件が正本）。
2. **1機能を細かくスライス**。特に書き込み・外部送信を伴う処理は単独スライスにする。
3. 実装後は必ず **全ファイル `php -l`**、ロジックは**スモークテスト**を書いて通す。
4. デプロイは **commit → `main` に push（自動デプロイ）**。DB_VERSION を上げたら wp-admin で確認。
   新規URL追加時はパーマリンク再保存。
5. 既存実装を触るときは**依頼された箇所だけ**。共有CSSは画面スコープで回避。

---

## 10. 既知の申し送り・要確認事項

- **✅ 対処済み：引き継ぎ書（`.md`）を本番へ送らないようにした。**
  `deploy.yml` は `local-dir: ./` でリポジトリ全体をプラグインディレクトリへ送るため、
  `https://portal-site.labs-ims.com/wp-content/plugins/ims-portal/引き継ぎ書_phase2c.md` が
  **公開状態で読めてしまう**（テーブル名・設計・セキュリティ判断が書かれている）。
  2c で `exclude:` に `**/*.md` と `composer.json` を追加した。
  - **⚠ `exclude` を指定するとアクション既定の除外リストが置き換わる。**
    既定の `**/.git*` / `**/.git*/**` / `**/node_modules/**` を消すと `.git` や `.github`
    （ワークフロー定義）が本番へ公開される。**必ず残したうえで追記すること。**
  - **⚠ 要確認：`引き継ぎ書_phase1c.md` が既に本番へ上がっている可能性が高い。**
    コミット `1cace54`（デプロイ設定の後）に含まれていたため。
    2c のデプロイでアクションが差分削除してくれるはずだが確実ではないので、
    **FTPで実サーバーを見て、残っていたら手動削除すること。**
    確認URL：`.../wp-content/plugins/ims-portal/引き継ぎ書_phase1c.md`
- **IPアドレスの記録方式**（§5）。本番で実際に何が入るか要確認。
- リポジトリ直下に**空の `src 2` ディレクトリ**が存在する（同期ツールの残骸と思われる）。
  空なので Git には乗っていない。ローカルで削除して差し支えない。
- `src/Core/LoginPagePlaceholder.php` が**未使用のまま残存**。整理して削除してよい。
- `00_portal.md` 第6章に**古い青系デザイントークン**が残り、`07_design_system.md`（和紙・墨）と矛盾。未解決。
- テストユーザーに **`hr_admin` ロールが不在**（administrator×2 / approver×1）。
- テストサーバーの**メール送達（SMTP）未検証**。
- **`attendance_logs` のストレージエンジンが InnoDB か未確認。** 2d のトランザクションに必須。
  `SHOW TABLE STATUS LIKE 'wp_attendance_logs'` で先に確認すること。
