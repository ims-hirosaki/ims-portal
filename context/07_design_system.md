# 要件定義書：07_デザインシステム / デザイン要件定義書 (07_design_system.md)
 
## 1. 目的・位置づけ
 
本書は、IMS Hirosaki 社内業務システム全体（00〜05 の全モジュール）に共通する **ビジュアルデザインの正本（Single Source of Truth）** を定義する。
 
各モジュールのワイヤーフレーム（`*_wireframe_redesign.html` / `03_04_wireframe_redesign.html`）に実装済みの CSS を集約・体系化したものであり、今後のUI改修・新規画面追加・Claude Code によるローカル仕上げ作業の際に参照すべき基準とする。
 
> **重要：既存の `00_portal.md` 6章「CSS 設計・デザインシステム」との関係**
> `00_portal.md` 6章には初期検討時の汎用ブルー系トークン（`--ims-primary: #2563EB` 等）が残っているが、**実装済みワイヤーフレームは本書で定義する「和紙・墨」系デザインに全モジュール統一済み**である。両者に差異がある場合、**本書（＝実装実態）を正とする**。`00_portal.md` 6章は本書へのポインタに更新することを推奨する。
 
### 設計の基本原則
 
- **デザイントークンの一元管理：** すべての色・余白・角丸・影・フォントは CSS カスタムプロパティ（`:root`）で定義し、各コンポーネントは変数を参照する。ブランド変更時の影響範囲を `:root` ブロックに限定する。
- **クロスモジュール統一：** 新規画面・新規モジュールを作る際は、必ず本書のトークンと共通コンポーネント命名規約に従い、独自の色・サイズをハードコードしない。
- **PC プライマリ・限定的スマホ対応：** PC を主対象としつつ、打刻など限定操作のためのレスポンシブを担保する。
---
 
## 2. デザインコンセプト
 
**「和紙・墨（washi & ink）」** を基調とした、落ち着きと品位のある業務システム。
 
- 背景はクリーム／生成りの紙色（`--paper` / `--surface`）を基調とし、白基調の事務システムにありがちな冷たさを避ける。
- アクセントは藍（インディゴ）を主役に、朱・金・苔の和の差し色を限定的に用いる。
- 見出しは明朝体（Shippori Mincho）、本文はゴシック体（Zen Kaku Gothic New）で、和文の可読性と格を両立する。
- 監査・給与計算など「正確さが信頼につながる」業務特性に合わせ、装飾は最小限・情報の視認性を最優先する。
---
 
## 3. デザイントークン
 
### 3.1. カラーパレット
 
全モジュール共通の `:root` 定義。**この20色＋事業カラーがシステムの全色である。** 新規に色を増やさない。
 
```css
:root {
  /* ── 基調（紙・墨） ── */
  --paper:          #F3ECDD;  /* ページ全体の背景（生成り紙） */
  --surface:        #FFFCF5;  /* セクション面・薄い面 */
  --surface-raised: #FFFFFF;  /* カード・モーダルなど浮いた面 */
  --ink:            #211D17;  /* 本文テキスト（墨） */
  --ink-soft:       #4A4339;  /* 副次テキスト・ラベル */
  --sub:            #897D69;  /* 補助テキスト・ヒント・プレースホルダ */
  --line:           #E1D5B7;  /* 標準の罫線・境界 */
  --line-soft:      #ECE3CC;  /* 淡い罫線・入力欄の枠 */
 
  /* ── 主役カラー（藍 / インディゴ） ── */
  --indigo:         #1E3A5F;  /* プライマリ。主要アクション・リンク・強調 */
  --indigo-deep:    #0F2034;  /* hover時・グラデーション濃端 */
  --indigo-soft:    #34557F;  /* フォーカス枠・サブ強調 */
  --indigo-tint:    #EAEEF2;  /* インディゴ系の淡い背景（チップ・表ヘッダ） */
 
  /* ── アクセントカラー（和の差し色） ── */
  --vermillion:     #B8472F;  /* 朱。danger・差し戻し・警告・必須マーク */
  --vermillion-tint:#F6E3DB;  /* 朱の淡い背景 */
  --gold:           #A87C2E;  /* 金。パスワード認証・保留・下書き系 */
  --moss:           #5C7A4E;  /* 苔。success・確定・在籍・承認 */
 
  /* ── 影 ── */
  --shadow-sm: 0 1px 2px rgba(33,22,10,.08);    /* カード標準 */
  --shadow-md: 0 10px 28px rgba(33,22,10,.12);  /* hover・浮き */
  --shadow-lg: 0 26px 60px rgba(15,32,52,.30);  /* モーダル */
 
  /* ── 角丸 ── */
  --r-sm: 6px;   /* ボタン・入力欄・小要素 */
  --r-md: 14px;  /* カード・タイル・パネル */
  --r-lg: 22px;  /* モーダル・大きな囲み */
 
  /* ── フォント ── */
  --f-display: 'Shippori Mincho', 'Hiragino Mincho ProN', serif;
  --f-body:    'Zen Kaku Gothic New', 'Hiragino Sans', 'Noto Sans JP', sans-serif;
}
```
 
#### モジュール固有の追加トークン
 
一部モジュールでのみ使用する派生トークン。共通化のため、今後は `:root` 共通定義への昇格を推奨する。
 
```css
--moss-tint:  #E7EEE3;  /* 苔の淡い背景（02・05で使用。03_04は #E5EBE1） */
--gold-tint:  #F3E9D3;  /* 金の淡い背景（05で使用） */
```
 
> **整合性メモ：** `--moss-tint` が `#E7EEE3`（02・05）と `#E5EBE1`（03_04）で僅かに割れている。統一作業の際は `#E7EEE3` に寄せる。
 
#### 事業カラー（`03_04` で定義 / 事業マスタ `wp_businesses.color_code` 相当）
 
事業別の色分けは **DB の `wp_businesses.color_code` が正本**であり、ワイヤーフレームの値はプレビュー用。実装では DB 値を CSS 変数に流し込む。
 
```css
--biz-a: #B8862E;   /* 例：本社業務（黄金系・明度が高いため文字は濃色） */
--biz-b: #5C7A4E;   /* 例：氷河期支援（モス） */
--biz-c: #6E5AA6;   /* 例：障がい者雇用（紫） */
--biz-d: #B8472F;   /* 例：ジョブカフェ（朱） */
```
 
- **可読性ルール：** 黄色系・金系など明度の高い背景色の事業セルは、セル内テキストを濃色にして可読性を確保する（→ `03_attendance_management.md` 4.1）。
### 3.2. タイポグラフィ
 
| 用途 | フォント変数 | 例 |
|---|---|---|
| 見出し（ロゴ・カード見出し・タイルラベル・数値強調） | `--f-display`（明朝） | ブランドマーク、`h2`、`.tile-label`、集計の大きな数字 |
| 本文・UI全般 | `--f-body`（ゴシック） | 段落・ラベル・ボタン・表本文 |
 
**Web フォント読み込み（全 HTML 共通の `<head>`）：**
 
```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Shippori+Mincho:wght@400;500;600;700;800&family=Zen+Kaku+Gothic+New:wght@400;500;700;900&display=swap" rel="stylesheet">
```
 
**フォントサイズの目安（実装値に基づく）：**
 
| トークン的役割 | px | 用途 |
|---|---|---|
| body 標準 | 15px / line-height 1.6 | 本文基準 |
| ブランドマーク | 22px | ヘッダーロゴ |
| カード見出し `h2` | 16px | カードタイトル（明朝） |
| タイルラベル | 16px（featured 24px） | 機能タイル名 |
| 集計の数値強調 | 26px 前後 | サマリーカードの大きな数字（明朝） |
| 表本文 | 13px | データテーブル |
| 表ヘッダ | 10.5〜11px / letter-spacing .03〜.04em | 大文字寄りの小見出し |
| フォームラベル | 11.5px / weight 700 | 入力欄ラベル |
| ヒント・補助 | 11〜12px | `--sub` 色 |
| バッジ・タグ | 10.5〜11px / weight 700 | 件数・ステータス |
 
- 数値の桁揃えが必要な箇所（タイマー・集計）は `font-variant-numeric: tabular-nums;` を使用する。
### 3.3. スペーシング・レイアウト基準
 
| 項目 | 値 |
|---|---|
| コンテンツ最大幅（ダッシュボード） | `max-width: 1180px`（一部画面 1280px） |
| コンテンツ左右パディング | PC 28px / スマホ 16〜18px |
| カード内パディング | header `16px 22px` / body `22px` |
| ボタンパディング | 標準 `9px 18px` / sm `6px 12px` |
| 入力欄パディング | `9px 13px` |
| グリッド間隔（gap） | 標準 14〜18px / 密 10px |
 
---
 
## 4. レイアウト原則
 
### 4.1. グローバルベース
 
```css
* { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
  font-family: var(--f-body); background: var(--paper); color: var(--ink);
  font-size: 15px; line-height: 1.6;
}
a { color: inherit; text-decoration: none; }
button { font-family: inherit; }
*:focus-visible { outline: 2px solid var(--indigo); outline-offset: 2px; border-radius: 4px; }
```
 
### 4.2. ブランドストリップ
 
ページ最上部の4pxの帯。藍→金のグラデーションでブランドを示す。
 
```css
.brand-strip { height: 4px; background: linear-gradient(90deg, var(--indigo-deep), var(--indigo) 55%, var(--gold)); }
```
 
### 4.3. ヘッダー（全画面共通・固定）
 
- `position: sticky; top: 0; z-index: 90;` で常時上部固定。
- 左：ブランドマーク（明朝・`--indigo`）＋サブテキスト。中央：ページチップ（`.page-chip`、淡インディゴ背景の丸ピル）。右：ユーザー名＋通知アイコン＋ログアウト。
- 高さ 64px、`max-width` でセンタリング。
- **スマホ表示時：** ページチップ・クイックリンクは `display: none`。ロゴ・通知・ユーザー名のみ残す。
### 4.4. 管理者レイアウト（wp-admin 内画面）
 
- 左サイドバー（`width: 232px`、`--surface` 背景、右罫線）＋右コンテンツの2カラム。
- `min-height: calc(100vh - 98px)`、`max-width: 1280px` センタリング。
### 4.5. レスポンシブ・ブレークポイント
 
| ブレークポイント | 主な変化 |
|---|---|
| `max-width: 900px` | タイルグリッドを2列化、featured タイルを全幅化、ヒーローを1カラム化 |
| `max-width: 640px` | クイックリンク・ページチップを非表示、余白縮小、サマリーレールを縦積み |
 
---
 
## 5. 共通コンポーネント仕様
 
すべて BEM 風のフラットなクラス名で統一する。新規実装時は下記の命名・スタイルを再利用し、独自の見た目を作らない。
 
### 5.1. ボタン `.btn`
 
ベース：`inline-flex` / `gap 6px` / `padding 9px 18px` / `border-radius var(--r-sm)` / `font-weight 700` / `font-size 13px` / トランジションあり。
 
| クラス | 背景 | 文字 | 用途 |
|---|---|---|---|
| `.btn-primary` | `--indigo` | #fff | 主要アクション。hover で `--indigo-deep`＋影＋1px浮き |
| `.btn-secondary` | `--surface-raised` | `--ink-soft` | キャンセル・副次。hover で枠と文字が藍に |
| `.btn-danger` | `--vermillion` | #fff | 削除・破棄。hover `#9C3A24` |
| `.btn-success` | `--moss` | #fff | 承認・確定。hover `#4A6440` |
| `.btn-warn` | `--vermillion-tint` | `#8A3A22` | 注意系の弱い警告アクション |
| `.btn-ghost-u` | なし（下線リンク風） | `--indigo` | テキストリンクボタン |
| `.btn-logout` | 透明・丸枠（radius 20px） | `--sub` | ログアウト。hover で朱 |
 
修飾子：`.btn-sm`（小）、`.btn-block`（全幅・中央寄せ）。
 
### 5.2. カード `.card`
 
```css
.card { background: var(--surface-raised); border: 1px solid var(--line); border-radius: var(--r-md); box-shadow: var(--shadow-sm); }
.card-header { padding: 16px 22px; border-bottom: 1px solid var(--line); display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.card-header h2 { font-family: var(--f-display); font-size: 16px; font-weight: 700; }
.card-header .hint { font-size: 12px; color: var(--sub); }
.card-body { padding: 22px; }
```
 
### 5.3. フォーム
 
- `.form-group`（`margin-bottom: 18px`）でラベル＋入力をまとめる。
- `.form-label`：11.5px / weight 700 / `--ink-soft`。必須は `.req`（朱の `*`）。
- `.form-input` / `.form-select`：`padding 9px 13px` / 枠 `--line-soft` / 背景 `--surface`。**フォーカス時：枠 `--indigo-soft`＋`box-shadow: 0 0 0 3px var(--indigo-tint)`**（藍のフォーカスリング）。
- `.form-select` はネイティブ矢印を消し、SVG矢印（`--sub` 色）を背景画像で右寄せ表示。
- `.form-row.cols-2` / `.cols-3` でグリッド分割。
- `.form-hint`：11px / `--sub`。
### 5.4. データテーブル `.data-table`
 
- `border-collapse: collapse` / `font-size: 13px`。
- `th`：10.5px / weight 700 / `--indigo-soft` 文字 / `--indigo-tint` 背景 / `letter-spacing .04em` / 下罫線。
- セルの下罫線は `--line` / `--line-soft`。行 hover で淡背景。
### 5.5. タグ・ステータスチップ `.tag`
 
ベース：`inline-flex` / `padding 3px 10px` / `border-radius 20px`（丸ピル）/ `font-size 11px` / weight 700。
 
| クラス | 意味 | 配色 |
|---|---|---|
| `.tag-google` | Google SSO 認証 | 淡インディゴ背景＋藍文字 |
| `.tag-pw` | パスワード認証 | `#EFE7D8` 背景＋金文字 |
| `.tag-active` / `.tag-on` | 在籍・有効 | 淡モス背景＋苔文字 |
| `.tag-retire` / `.tag-off` | 退職・無効 | `--line-soft` 背景＋`--sub` 文字 |
| `.tag-leave` | 休職 | 淡朱背景＋朱文字 |
 
### 5.6. 稟議ステータスチップ `.st-*`（05_稟議モジュール）
 
申請ワークフローの状態色。**勤怠・稟議で同じ状態には同じ色を使う。**
 
| クラス | 状態 | 配色 |
|---|---|---|
| `.st-draft` | 下書き | `#EDEAE2` 背景＋`--sub` |
| `.st-pending` | 承認待ち | `--indigo-tint`＋`--indigo` |
| `.st-rejected` | 差し戻し | `--gold-tint`＋`#8A6418`（金系） |
| `.st-dismissed` | 却下 | `--vermillion-tint`＋`--vermillion`（朱） |
| `.st-withdrawn` | 取り下げ | `#EDEAE2`＋`--sub` |
| `.st-confirmed` | 承認・確定 | `--moss-tint`＋`--moss`（苔） |
| `.st-cancelled` | キャンセル | `--line-soft`＋`--sub`＋破線枠 |
 
各チップ先頭に `.dot-sm`（6px の `currentColor` 丸）を置ける。
 
### 5.7. タイル `.tile`（ポータルダッシュボード）
 
- カード同様の面に `padding 22px` / `flex-direction: column`。hover で影＋3px浮き＋枠が藍に。
- `.tile-icon`（26px / `--indigo` / `stroke-width 1.6`）、`.tile-label`（明朝16px）、`.tile-desc`（12px / `--sub`）。
- `.tile-badge`：右上に朱背景の件数バッジ（`--vermillion`＋#fff）。
- `.tile-featured`：藍グラデーション（`--indigo-deep`→`#1A3554`）の主役タイル。ラベル24px、文字白、`--gold` の脚注。
- 初期表示時に `fadeUp` アニメーションを `nth-of-type` で順次ディレイ。
### 5.8. モーダル
 
```css
.modal-wrap { background: var(--surface-raised); border: 1px solid var(--line);
  border-radius: var(--r-lg); box-shadow: var(--shadow-lg); max-width: 680px; overflow: hidden; }
.mhdr { padding: 18px 22px; border-bottom: 1px solid var(--line);
  background: linear-gradient(160deg, var(--indigo-deep), var(--indigo) 140%); color: #fff; }
```
 
ヘッダーは藍グラデーション＋白文字、本体は紙面。最大幅 680px・センタリング。
 
### 5.9. その他の共通要素
 
- `.section-label`：セクション見出し。11px / weight 700 / `--sub` / `letter-spacing .14em`、右側に `--line` の罫線が伸びる（`::after`）。
- `.icon-btn`：36px の丸ボタン。hover で枠・アイコンが藍に。通知バッジを内包可。
- `.notice-box` / `.info-item`：`--indigo-tint` または `--paper` 背景の囲み情報枠。
---
 
## 6. ステータス色のマッピング規約
 
色の意味をシステム全体で固定する。**同じ意味は必ず同じ色を使う。**
 
| 意味カテゴリ | 代表色 | 使用例 |
|---|---|---|
| 通常・主要アクション・リンク | 藍 `--indigo` | ボタン、リンク、承認待ち、フォーカス |
| 成功・確定・在籍・承認済 | 苔 `--moss` | 確定ステータス、在籍タグ、成功ボタン |
| 警告・差し戻し・保留・下書き | 金 `--gold` | 差し戻し、パスワード認証、下書きバナー |
| 危険・却下・必須・休職・エラー | 朱 `--vermillion` | 削除、却下、必須マーク、未入力警告、件数バッジ |
| 無効・非活性・補助情報 | `--sub` / `--line` | 退職・無効タグ、キャンセル、ヒント |
 
---
 
## 7. モーション・アニメーション
 
```css
@keyframes fadeUp { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: translateY(0); } }
@keyframes pulse  { 0% { box-shadow: 0 0 0 0 rgba(127,216,147,.55); } 70% { box-shadow: 0 0 0 7px rgba(127,216,147,0); } 100% { box-shadow: 0 0 0 0 rgba(127,216,147,0); } }
```
 
- 画面初期表示：カード／タイルは `fadeUp`（0.5s）を順次ディレイで適用。
- 勤務中など「ライブ状態」の表現に `pulse` を限定使用。
- hover トランジションは 0.15〜0.2s を基準。
- **アクセシビリティ：** `@media (prefers-reduced-motion: reduce)` で全アニメーション・トランジションを無効化する（全 HTML に実装済み・必須）。
---
 
## 8. アクセシビリティ要件
 
- フォーカス可視化：`*:focus-visible` に藍の2pxアウトラインを必須実装。
- コントラスト：本文は `--ink`（#211D17）×紙面で十分なコントラストを確保。明度の高い事業カラー上のテキストは濃色に切り替える。
- モーション低減：`prefers-reduced-motion` 対応必須（→ 7節）。
- 外部ツールリンクは `target="_blank" rel="noopener noreferrer"` を必須とする（→ `06_google_workspace_integration.md`）。
---
 
## 9. 実装ガイドライン
 
1. **トークン参照を徹底する。** 色・余白・角丸・影・フォントは必ず CSS 変数経由で指定し、生のHEXや px を直書きしない（事業カラーは DB 由来のため例外的に動的注入）。
2. **共通コンポーネントを再利用する。** 新規画面は `.btn` `.card` `.form-*` `.data-table` `.tag` `.st-*` などの既存クラスを使う。新しい見た目が必要な場合は、まず本書に追記してから実装する。
3. **モジュール間の差分を作らない。** 新モジュール着手時は、全モジュールの `:root` と共通コンポーネントを読み込み、デザイントークン・命名・JS関数シグネチャ（`show()`, `toggleTl()` 等）の規約を踏襲する。
4. **派生トークンは共通へ昇格させる。** `--moss-tint` / `--gold-tint` のようにモジュール個別定義になっているものは、`:root` 共通定義に集約し、値の揺れ（`#E7EEE3` / `#E5EBE1`）を解消する。
5. **`00_portal.md` 6章を本書に同期する。** 旧ブルー系トークンは廃止し、6章は「デザイン定義は `07_design_system.md` を参照」とする。
---
 
## 10. 既知の不整合・統一タスク（申し送り）
 
今後の統一作業で解消すべき点。
 
| 項目 | 現状 | あるべき姿 |
|---|---|---|
| `00_portal.md` 6章のトークン | 旧ブルー系（`#2563EB` 等）が残存 | 本書（和紙・墨系）へ統一・参照化 |
| `--moss-tint` の値 | 02/05 は `#E7EEE3`、03_04 は `#E5EBE1` | `#E7EEE3` に統一 |
| `--moss-tint` / `--gold-tint` の定義場所 | モジュール個別（`:root` 末尾に追記） | 共通 `:root` に昇格 |
| 事業カラー | ワイヤーフレームにプレビュー値をハードコード | DB `wp_businesses.color_code` から動的注入に統一 |
 