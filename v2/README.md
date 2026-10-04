# AbbeyRoad.online v2

既存サイト（リポジトリ直下）はそのまま残し、**別ディレクトリに作り直した完成版**。
ライブのタイムテーブルとメンバー表（CSV / PDF）を取り込み、ライブごと・メンバーごとに閲覧できる。

## できること

| 画面 | 内容 |
| --- | --- |
| `login.php` / `register.php` | ログイン・新規登録（最初の登録者は管理者） |
| `index.php` | 年度ごとのライブ一覧・検索 |
| `live.php?id=` | ライブの日程ごとのタイムテーブル（メンバー・曲数・鍵盤・休憩・トリ） |
| `members.php` | 出演回数ランキング・トリ回数・検索 |
| `member.php?id=` | 出演履歴・パート内訳・よく組むメンバー |
| `import.php` | タイムテーブル＆メンバー表の取り込み（プレビュー → 修正 → 登録） |
| `band_edit.php?id=` | バンド名・メンバーの手修正 |

### 取り込みの仕組み

1. CSV / PDF をまとめてアップロード（中身から「タイムテーブル」か「メンバー表」かを自動判別）
2. タイムテーブル上部の「○○ライブ2日目 / 会場 / △△」「1月5日」からライブ名・日目・会場・日付を読む
3. バンド名でメンバー表と照合（全角/半角・大文字小文字・空白の違いを吸収。「ヨルシカ(安田)」→ 安田さんが入っているヨルシカ、も判定）
4. 「清水啓之介 / 清水啓乃介」「皆川 / 皆川桜」のような**同一人物っぽい名前**を検出し、プレビューで名寄せできる
5. 登録は全件トランザクション。同じ年度・ライブ・日目が既にあればエラー（「上書き」チェックで置換）

PDF は Excel から書き出した「文字を選択できる PDF」に対応（poppler の `pdftotext -bbox` で単語の座標を取り、見出しの位置から表を復元）。写真・スキャンの PDF は不可。

## セットアップ（XAMPP）

1. phpMyAdmin などで新しいDB（例: `m6_v2`、照合順序 `utf8mb4_unicode_ci`）を作り、`schema.sql` をインポート
   - **既存DBには流さないこと**（`DROP TABLE` が入っている）
2. `config.sample.php` を `config.php` にコピーして DB 接続情報を書く
3. `http://localhost/m6/v2/` を開いて新規登録 → 最初のユーザーが管理者になる
4. PDF を使う場合は poppler を入れる
   - Windows: <https://github.com/oschwartz10612/poppler-windows/releases> を展開し、`config.php` の `pdftotext` に `C:\\poppler\\Library\\bin\\pdftotext.exe` のようにフルパスを書く
   - Mac: `brew install poppler`

パーサーのテスト: `php v2/tests/run.php`

## テーブル設計

```
live_master 1─* live_detail 1─* band_master *─* member
                    │                    (band_member: band_id, member_id, part が複合主キー)
                    *─1 venue
user_index ─0..1 member（自分の出演履歴と紐付け）
```

- `band_member` の主キーに `part` を含めているのは、同じ人が同じバンドで Vo と Ba を兼任するケースがメンバー表に実在するため
- 休憩は保存せず、前のバンドの終了時刻と次の開始時刻の隙間から表示している（カラムを増やさないため）
- トリは「その日の出演順が最大のバンド」として集計時に計算

## セキュリティ

- SQL はすべてプリペアドステートメント（`PDO::ATTR_EMULATE_PREPARES => false`）
- 出力はすべて `h()`（`htmlspecialchars`）を通す
- POST はすべて CSRF トークン検証（`verify_csrf()`）
- パスワードは `password_hash()` / `password_verify()`、ログイン時に `session_regenerate_id()`
- アップロードは拡張子・サイズ・`is_uploaded_file()` を確認し、ファイル自体は保存しない
- `lib/` `partials/` `config.php` `schema.sql` は `.htaccess` で直接アクセス禁止

## コードを読む順番（学習用）

1. `lib/bootstrap.php` — DB接続・CSRF・ログイン判定。全ページの土台
2. `login.php` → `index.php` — 「POST を受けて検証して SELECT して表示」の基本形
3. `live.php` — JOIN と、PHP 側で配列を組み直して表示する流れ
4. `members.php` — `GROUP BY` とサブクエリでの集計
5. `lib/import/parsers.php` — 2次元配列から意味を取り出す処理
6. `lib/import/planner.php` — トランザクションでまとめて INSERT する処理
