# AbbeyRoad.online v2

元の m6（リポジトリ直下）の**お手本版**。元のコードは触らず、別ディレクトリに完成版を作っている。
DB は元の m6 と同じ `local_abbeydb` をそのまま使う（テーブル構造は `schema.sql` 参照）。

## できること

| 画面 | 内容 |
| --- | --- |
| `login.php` / `register.php` | ログイン制。全ページログイン必須（管理者がいなければ新規登録者が管理者） |
| `index.php` | 年度ごとのライブ一覧・検索 |
| `live.php?id=` | 日程タブ付きのタイムテーブル（楽器別メンバー・曲数・鍵盤メモ・トリ・自分の出演） |
| `members.php` / `member.php?id=` | 出演回数・トリ回数ランキング / 個人の出演履歴・楽器内訳・よく組むメンバー |
| `import.php` | タイムテーブル＆名簿の取り込み（プレビューで全部編集できる） |
| `band_edit.php?id=` / `?day=` | バンドの編集・追加・出演順の並べ替え・削除（削除は管理者） |
| `live_edit.php?id=` | ライブ名・年度・各日程の日付/会場/メモの修正（`0000-00-00` の修正にも） |
| `search.php` | バンド名・メンバー名・ライブ名・会場の横断検索（🔍） |
| `stats.php` | 集計: よく組むペア・トリ回数・よくコピーされるアーティスト・楽器別・会場別（年度で絞り込み） |
| `export.php?live=` | ライブを CSV でダウンロード（Excel で文字化けしない） / 印刷ボタンで紙用レイアウト |
| `member_edit.php` | プロフィール（名前・ふりがな・入部年度）の編集（本人か管理者） |
| `account.php` | アカウント設定（名前・パスワード変更・自分のメンバー紐付け） |
| `users.php` | ユーザー管理（管理者の付け外し・紐付け解除・削除）※管理者のみ |
| `members_merge.php` | 表記ゆれで重複したメンバーの統合（似た名前を自動で候補に出す）※管理者のみ |
| 右上の 🌙 | ライト（白基調）/ ダーク切り替え（ブラウザに保存） |
| ロゴ | 元の m6 の `src/assets/online.png`（ダーク時は文字を白くした `assets/logo-dark.png`） |

### 取り込みの流れ

1. CSV / PDF をまとめてアップロード → 中身から「タイムテーブル」か「名簿」かを自動判別
2. **タイムテーブルのプレビュー**: 年度・ライブ名・日程・開催日・会場・集合、各バンドの出演順・名前・曲数・鍵盤メモをすべて編集可。休憩などは「取込」のチェックが外れた状態で出る
3. **名簿のプレビュー**: 全セル編集可。名前は DB と照合して色分け
   - 🟩 緑: DB に登録済み　🟨 黄: 似た人がいる（書き間違い？）　🟥 赤: 新しいメンバーとして登録
   - 書き換えると `api_name_check.php` に問い合わせて色が変わる（元の m6 の `preview_name_check.php` と同じ考え方）
   - 列ごとに楽器（instrument テーブル）を選べる。「Vo(Gt.)」列をギターボーカル扱いにする、なども可
   - 各行の「対応する出演バンド」で、タイムテーブルのどのバンドのメンバーかを選ぶ（自動で照合済み）
4. 「登録する」で全部を1トランザクションで登録。同じ年度・ライブ名・日程が既にあれば止まる（「上書き」で置換）

## セットアップ（XAMPP）

1. `config.sample.php` を `config.php` にコピーし、DB 接続情報を書く（既定は `local_abbeydb` / root / パスワードなし）
2. `http://localhost/m6/v2/` を開く
3. 外部の人に登録されたくなければ `config.php` の `invite_code` に合言葉を設定（新規登録で必須になる）
4. 管理者にしたいアカウントがあれば phpMyAdmin で `UPDATE user_index SET is_admin = 1 WHERE login_id = 'tao_08';`
5. PDF を使う場合は poppler を入れる
   - Windows: <https://github.com/oschwartz10612/poppler-windows/releases> を展開し、`config.php` の `pdftotext` にフルパスを書く
   - Mac: `brew install poppler`
6. （任意）`migrations/001_recommended_keys.sql` を流して中間テーブルに複合主キーを付ける

パーサーのテスト: `php v2/tests/run.php`

## DB の使い方（local_abbeydb に合わせた点）

| テーブル | v2 での使い方 |
| --- | --- |
| `live_master` | (year, name) で探して無ければ作る。year は年度（1〜3月は前年） |
| `live_detail` | `label` に「1日目」「教室ライブ」。`date`・`venue_id` が NOT NULL なので取り込み時に必須。`note` に「集合 11:00」 |
| `band` | `note` に鍵盤の私物/貸出メモ。`song_count` は NOT NULL なので必須 |
| `band_member` + `band_member_instrument` | 両方に書く（在籍と楽器）。読むときは UNION して片方にしか無いデータも拾う |
| `member` | `name_kana` が NOT NULL・初期値なしなので、新規作成時は `''` を入れる |
| `user_index` | `login_id` / `password_hash`（必ず `password_hash()`）/ `is_admin` / `member_id` |

外部キーに `ON DELETE CASCADE` が無いので、削除は `lib/repository.php` の `delete_live_detail()` で「孫 → 子 → 親」の順に消している。

## コードを読む順番

1. `lib/bootstrap.php` — DB 接続・XSS 対策・CSRF 対策・ログイン判定（全ページの土台）
2. `login.php` → `index.php` — 「POST を受けて検証して SELECT して表示」の基本形
3. `live.php` — JOIN と、N+1 問題を避けて配列で振り分ける書き方
4. `members.php` / `member.php` — GROUP BY・サブクエリ・自己結合での集計
5. `lib/repository.php` — 「探して無ければ作る」と、外部キーを意識した削除
6. `lib/import/text.php` → `parsers.php` → `planner.php` — 表記ゆれ吸収 → 表の読み取り → トランザクションで登録
7. `import.php` + `assets/app.js` — プレビュー画面と、fetch() での色分け
8. `stats.php` — 自己結合でペアを数える / SQL で数えにくいものは PHP で集計
9. `lib/repository.php` の `merge_members()` — 重複を避けながら外部キーの付け替え
