# AbbeyRoad.online v3 — 合理的な DB 設計版

v2（既存の `local_abbeydb` に合わせた版）と**画面・機能は同じ**まま、DB を一から設計し直した版。
「同じアプリを、DB の設計だけ変えたらコードがどう変わるか」を v2 と見比べて勉強できるようにしてある。

- DB: `abbey_v3`（`schema.sql` を流す。local_abbeydb とは別の DB）
- 設計の理由は `schema.sql` のコメントに全部書いてある

## ER 図

```
live ──< live_day ──< band >── band_member ──< member ── user_account
            │           │          │
          venue       artist   instrument
```

| テーブル | 主キー | ポイント |
| --- | --- | --- |
| `live` | live_id | `UNIQUE(fiscal_year, name)`、年度は `SMALLINT` + `CHECK` |
| `live_day` | live_day_id | `UNIQUE(live_id, label)`、日付・会場・集合は不明なら `NULL`、`ON DELETE CASCADE` |
| `venue` | venue_id | `UNIQUE(name)` |
| `artist` | artist_id | **新設**。「ヨルシカ（安田）」と「ヨルシカ（八木）」が同じヨルシカだと DB が分かる |
| `band` | band_id | `UNIQUE(live_day_id, play_order)`、開始/終了時刻、`CHECK(end_time > start_time)` |
| `instrument` | instrument_id | `sort_order` で表示順を DB が持つ。「ギターボーカル」は作らない |
| `band_member` | **(band_id, member_id, instrument_id)** | 唯一の中間テーブル。兼任は2行 |
| `member` | member_id | `UNIQUE(name)`、ふりがな・入部年度は不明なら `NULL` |
| `user_account` | user_id | `UNIQUE(login_id)`、`UNIQUE(member_id)`（1メンバー1アカウント）、`is_admin` は `NOT NULL` |

## v2（local_abbeydb）から何を変えたか・なぜか

| local_abbeydb | v3 | 理由 |
| --- | --- | --- |
| `band_member` と `band_member_instrument` の2つ | `band_member` 1つ（楽器込み） | 同じ事実を2か所に書くと食い違う（正規化） |
| 中間テーブルに主キーなし | 複合主キー | 同じ行の二重登録を DB が拒否する |
| 楽器マスタに「ギターボーカル」 | Vo と Gt の2行 | 組み合わせをマスタに入れると「Vo の人数」が数えにくい |
| アーティストの概念なし | `artist` テーブル | 「よくコピーされるアーティスト」が `GROUP BY` 1発。`artist.php` が作れる |
| 日付不明を `0000-00-00` | `NULL` | 「値がない」は NULL。0000-00-00 は MySQL の設定次第でエラーになる |
| `YEAR` 型の年度（0000 が入る） | `SMALLINT` + `CHECK` | ありえない値を DB が拒否する |
| `live_detail.note` に集合時刻を文字で | `live_day.meeting_time TIME` | 型のある列にすれば並べ替え・計算ができる |
| 出演時刻を保存しない | `band.start_time / end_time` | 休憩を時刻の隙間から計算できる（休憩は保存しない） |
| `ON DELETE` 指定なし | `CASCADE` / `SET NULL` / `RESTRICT` | 消すときの動きを DB が保証。PHP で「孫→子→親」の順に消さなくていい |
| `is_admin` が NULL 可 | `BOOLEAN NOT NULL DEFAULT FALSE` | NULL と 0 の2種類の「管理者じゃない」をなくす |
| `name_kana` が NOT NULL（初期値なし） | NULL 可 | 不明なものに '' を入れさせない |
| UNIQUE なし（venue / live / 日程） | UNIQUE あり | 「探して無ければ作る」を `INSERT IGNORE` 1文で書ける |

### コードが短くなったところ（v2 と見比べてみよう）
- `lib/repository.php` の `delete_live_day()` / `merge_members()` / `attach_members()`
- `stats.php` の「よくコピーされるアーティスト」（PHP での集計 → SQL の GROUP BY）
- `live.php` の楽器の並び順（PHP の配列 → `ORDER BY i.sort_order`）

### 逆に注意が必要になったところ
- `renumber_bands()`: 出演順が UNIQUE なので、並べ替えは「いったん +1000 に逃がしてから振り直す」2段階
- `live_edit.php`: UNIQUE 違反（SQLSTATE 23000）を catch してメッセージにしている
- `INSERT IGNORE` は UNIQUE 以外のエラー（外部キー違反など）も黙って無視する。入れる値は事前にチェックすること

## セットアップ

1. phpMyAdmin で DB `abbey_v3`（照合順序 `utf8mb4_general_ci`）を作り、`schema.sql` をインポート
2. `config.sample.php` を `config.php` にコピー
3. `http://localhost/m6/v3/` → 新規登録（最初の人が管理者）→「取り込み」でタイムテーブルと名簿を入れる

機能の一覧・PDF 対応・コードを読む順番は v2 の README と同じ。v3 だけの追加は `artist.php`（アーティスト別の歴代コピー一覧・表記ゆれの統合）。
