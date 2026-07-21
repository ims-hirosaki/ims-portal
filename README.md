# IMS Hirosaki Portal — Phase 0 骨格

`08_implementation_guidelines.md` に基づくプラグイン骨格（Phase 0）。
現時点では **業務モジュール（01〜06）は未実装**で、以下のみを提供する。

- 起動・オートローダー
- DBマイグレーション基盤（Installer／`ims_register_schema` フィルタ）
- ロール・カスタム権限登録（Roles）
- `/portal/` ルーティング（Router／`ims_portal_register_page` フック）
- 認証ガード（AuthGuard）
- 条件付きアセット読み込み（Assets）
- ダッシュボードタイル登録の土台（TileRegistry／`ims_portal_register_tile` フック）
- サマリーAPI集約の土台（SummaryAggregator／`ims_portal_summary_contribute` フック）
- 07デザイントークンCSS
- 動作確認用の暫定ダッシュボード・暫定ログインページ

---

## 1. デプロイ手順（FTP）

1. このフォルダ一式（`ims-portal/`）を、テストサイトの
   `wp-content/plugins/` 直下にアップロードする。
   最終的なパスは `wp-content/plugins/ims-portal/ims-portal.php` になること。
2. WordPress 管理画面 → **プラグイン** → 「IMS Hirosaki Portal」を **有効化**する。
   - 有効化時に自動実行される処理：
     - ロール登録（`general_staff` / `approver` / `hr_admin`。administrator は標準ロールを流用）
     - `/portal/` の rewrite rule 登録＋ `flush_rewrite_rules()`
     - （現時点ではDDLの寄与モジュールが無いため、テーブル作成は発生しない）
3. **設定 → パーマリンク** が「投稿名」等（「基本」以外）になっていることを確認する。
   - なっていない場合、管理画面に赤いエラー通知が表示される（EnvironmentCheck）。

## 2. 動作確認手順

1. ブラウザで `https://（テストサイトのURL）/portal/` にアクセスする。
   - 未ログイン → `/portal/login/` へリダイレクトされることを確認する。
2. `/portal/login/`（Phase 0 暫定ページ）から「WordPress標準ログインへ」をクリックし、
   通常の `wp-login.php` でログインする。
3. ログイン後、`/portal/` に自動遷移し、ダッシュボードが表示されることを確認する
   （Phase 0 時点ではタイルは0件のため「表示できる機能がまだ登録されていません。」
   と出るのが正しい状態）。
4. `general_staff` ロールのテストユーザーで `wp-admin` にアクセスし、
   `/portal/` へ強制リダイレクトされることを確認する。
5. `hr_admin` または `administrator` では通常通り `wp-admin` に入れることを確認する。

## 3. 安全に関する注意（本番サーバー上での直接デプロイ運用のため）

- **物理削除はしない設計。** 無効化してもデータ・オプションは一切削除されない。
- **有効化・無効化は何度実行しても安全（べき等）** になるよう設計している。
  何か想定外の挙動が起きた場合は、まず **プラグインの無効化**（管理画面、または
  FTPで `ims-portal` フォルダ名を一時的にリネーム）で切り戻せる。
- **脱出口の確保：** カスタムロールを一切付けていない `administrator` アカウントを
  最低1つ、必ず温存しておくこと（本プラグインが `administrator` の
  wp-admin アクセスを妨げることはない設計だが、念のため）。
- FTPでファイルだけ上書きした場合（activation hookが発火しない場合）でも、
  `admin_init` でスキーマバージョン差分を検知して追従する
  （`Installer::maybe_upgrade`）。

## 4. 次のステップ

`08_implementation_guidelines.md` §12 の Phase順に、
`src/Module/User/`（01_user_management）から実装する。
新モジュールは以下のフックで自己登録するだけで、このファイル（`ims-portal.php`）
および他モジュールを一切改修せずに追加できる。

- `ims_portal_register_page`（URL登録）
- `ims_portal_register_tile`（ダッシュボードタイル登録）
- `ims_portal_summary_contribute`（サマリー/バッジ集計への寄与）
- `ims_register_schema`（DBテーブルDDLの寄与）
- `ims_seed_initial_data`（初期データ投入）

## 5. Composer について

`composer.json` はIDEの補完・将来のテスト導入のために同梱しているが、
**`composer install` の実行は不要**。ランタイムは `ims-portal.php` 内の
軽量オートローダー（`spl_autoload_register`）で `IMS\` 名前空間を解決するため、
FTPでこのフォルダを置くだけで動作する。
