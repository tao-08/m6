-- =====================================================================
--  AbbeyRoad.online v3 — 「一番合理的な構成」で設計し直したスキーマ
-- =====================================================================
--  local_abbeydb とは別の DB に流すこと（例: abbey_v3）。
--    phpMyAdmin で DB を作成 → 「インポート」でこのファイルを選ぶ
--
--  ER 図（1 ─< 多）
--
--    live ─< live_day ─< band >─ band_member ─< member ── user_account
--               │          │          │
--             venue      artist   instrument
--
--  設計の方針（なぜこうしたか）
--    1. 1つの事実は1か所にだけ書く（正規化）
--         - 「誰がどのバンドで何を弾いたか」は band_member の1テーブルだけ。
--           local_abbeydb の band_member + band_member_instrument の二重管理をやめた。
--         - 「ギターボーカル」は「Vo」と「Gt」の2行で表す（楽器の組み合わせを楽器マスタに入れない）。
--         - 「何のコピーか」を artist テーブルに分けた。
--           「ヨルシカ（安田）」「ヨルシカ（八木）」が同じヨルシカだと DB が分かるので、
--           「よくコピーされるアーティスト」が GROUP BY 1発で出せる。
--    2. 間違ったデータを DB が拒否する（制約）
--         - 主キー / UNIQUE / FOREIGN KEY / CHECK を全テーブルに付けた。
--         - 中間テーブルは複合主キーで「同じ行2回」を防ぐ。
--    3. 「分からない」は NULL で表す
--         - 日付不明を '0000-00-00'、年度不明を 0000 で表さない（NULL にする）。
--    4. 消すときの動きを決めておく（ON DELETE）
--         - ライブを消したら日程・バンド・出演記録も一緒に消える（CASCADE）。
--         - 出演記録があるメンバーは消せない（RESTRICT）→ 統合ツールを使う。
--
--  照合順序は utf8mb4_general_ci（XAMPP の MariaDB 10.4 で使え、
--  「は」と「ば」を別の文字として扱う。unicode_ci だと同じ扱いになり、名前の UNIQUE が誤爆する）
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS band_member, band, artist, live_day, live, venue, instrument, user_account, member;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
--  member — サークル員（出演する人）
-- ---------------------------------------------------------------------
CREATE TABLE member (
    member_id  INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    name       VARCHAR(50)       NOT NULL,             -- 表示名（空白なしで統一して保存）
    name_kana  VARCHAR(50)       NULL,                 -- 不明なら NULL（'' で代用しない）
    entry_year SMALLINT UNSIGNED NULL,                 -- 入部年度
    created_at DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (member_id),
    UNIQUE KEY uq_member_name (name),
    CONSTRAINT ck_member_entry CHECK (entry_year IS NULL OR entry_year BETWEEN 1950 AND 2100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  user_account — ログインするアカウント
--    メンバーと 1対1（または未紐付け）。member_id を UNIQUE にして
--    「1人のメンバーに2つのアカウント」を DB レベルで防ぐ。
-- ---------------------------------------------------------------------
CREATE TABLE user_account (
    user_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    login_id      VARCHAR(25)  NOT NULL,
    name          VARCHAR(50)  NOT NULL,
    password_hash VARCHAR(255) NOT NULL,               -- password_hash() の結果だけ
    is_admin      BOOLEAN      NOT NULL DEFAULT FALSE, -- NULL を許さない（「NULL=管理者じゃない？」の曖昧さを消す）
    member_id     INT UNSIGNED NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_user_login (login_id),
    UNIQUE KEY uq_user_member (member_id),
    CONSTRAINT fk_user_member FOREIGN KEY (member_id) REFERENCES member (member_id)
        ON UPDATE CASCADE ON DELETE SET NULL           -- メンバーが統合で消えても、アカウントは残る
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  venue — 会場
-- ---------------------------------------------------------------------
CREATE TABLE venue (
    venue_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(50)  NOT NULL,
    address     VARCHAR(255) NULL,
    website_url VARCHAR(255) NULL,
    PRIMARY KEY (venue_id),
    UNIQUE KEY uq_venue_name (name)                    -- 同じ会場が2つできない
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  live — ライブ（例: 2025年度 文化祭ライブ）
-- ---------------------------------------------------------------------
CREATE TABLE live (
    live_id     INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    fiscal_year SMALLINT UNSIGNED NOT NULL,            -- 年度（4月始まり）。YEAR 型は 1901〜2155 しか入らず 0000 も入るので使わない
    name        VARCHAR(50)       NOT NULL,
    PRIMARY KEY (live_id),
    UNIQUE KEY uq_live (fiscal_year, name),
    CONSTRAINT ck_live_year CHECK (fiscal_year BETWEEN 1990 AND 2100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  live_day — ライブの各日程（1日目 / 2日目 / 教室ライブ ...）
-- ---------------------------------------------------------------------
CREATE TABLE live_day (
    live_day_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    live_id      INT UNSIGNED NOT NULL,
    label        VARCHAR(50)  NOT NULL,                -- 「1日目」「教室ライブ」
    held_on      DATE         NULL,                    -- 開催日。不明なら NULL
    venue_id     INT UNSIGNED NULL,                    -- 不明なら NULL
    meeting_time TIME         NULL,                    -- 集合時刻（メモ欄に文字で書かず、型のある列にする）
    note         TEXT         NULL,
    PRIMARY KEY (live_day_id),
    UNIQUE KEY uq_live_day_label (live_id, label),     -- 同じライブに「1日目」が2つできない
    KEY idx_live_day_held_on (held_on),
    CONSTRAINT fk_day_live  FOREIGN KEY (live_id)  REFERENCES live (live_id)
        ON UPDATE CASCADE ON DELETE CASCADE,           -- ライブを消すと日程も消える
    CONSTRAINT fk_day_venue FOREIGN KEY (venue_id) REFERENCES venue (venue_id)
        ON UPDATE CASCADE ON DELETE SET NULL           -- 会場を消しても日程は残る
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  artist — コピー元のアーティスト（ヨルシカ、King Gnu ...）
-- ---------------------------------------------------------------------
CREATE TABLE artist (
    artist_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name      VARCHAR(100) NOT NULL,
    PRIMARY KEY (artist_id),
    UNIQUE KEY uq_artist_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  band — その日に出演したコピーバンド1組
--    name は「タイムテーブルに書いてあった名前」（ヨルシカ(安田) など）をそのまま残す。
--    artist_id は「何のコピーか」。
-- ---------------------------------------------------------------------
CREATE TABLE band (
    band_id     INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    live_day_id INT UNSIGNED      NOT NULL,
    artist_id   INT UNSIGNED      NULL,                -- オリジナル曲のバンドなどは NULL
    name        VARCHAR(100)      NOT NULL,
    play_order  SMALLINT UNSIGNED NOT NULL,            -- 出演順。その日の最大がトリ
    start_time  TIME              NULL,
    end_time    TIME              NULL,
    song_count  TINYINT UNSIGNED  NOT NULL DEFAULT 0,
    note        VARCHAR(255)      NULL,                -- 鍵盤の私物/貸出など
    PRIMARY KEY (band_id),
    UNIQUE KEY uq_band_order (live_day_id, play_order), -- 同じ日に「3番目」が2組できない
    KEY idx_band_artist (artist_id),
    CONSTRAINT fk_band_day    FOREIGN KEY (live_day_id) REFERENCES live_day (live_day_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_band_artist FOREIGN KEY (artist_id) REFERENCES artist (artist_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_band_order CHECK (play_order >= 1),
    CONSTRAINT ck_band_time  CHECK (start_time IS NULL OR end_time IS NULL OR end_time > start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  instrument — 楽器マスタ
--    sort_order で表示順（Vo → Gt → Ba → Dr → Key ...）を DB が持つ。
--    「ギターボーカル」のような組み合わせは作らない（band_member に Vo と Gt の2行を入れる）。
-- ---------------------------------------------------------------------
CREATE TABLE instrument (
    instrument_id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(30)      NOT NULL,           -- ボーカル
    short_name    VARCHAR(10)      NOT NULL,           -- Vo
    sort_order    TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (instrument_id),
    UNIQUE KEY uq_instrument_short (short_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO instrument (instrument_id, name, short_name, sort_order) VALUES
(1, 'ボーカル',       'Vo',   1),
(2, 'ギター',         'Gt',   2),
(3, 'ベース',         'Ba',   3),
(4, 'ドラム',         'Dr',   4),
(5, 'キーボード',     'Key',  5),
(6, 'コーラス',       'Cho',  6),
(7, 'パーカッション', 'Perc', 7),
(8, 'ヴァイオリン',   'Vn',   8),
(9, 'サックス',       'Sax',  9),
(10, 'その他',        'etc',  99);

-- ---------------------------------------------------------------------
--  band_member — 誰がどのバンドで何を担当したか（中間テーブル）
--    band と member の many-to-many を解決し、さらに楽器も持つ。
--    複合主キー (band_id, member_id, instrument_id):
--      ・同じ人・同じ楽器の重複を DB が拒否する
--      ・同じ人が Vo と Ba を兼任 → 2行（instrument_id が違うので OK）
-- ---------------------------------------------------------------------
CREATE TABLE band_member (
    band_id       INT UNSIGNED     NOT NULL,
    member_id     INT UNSIGNED     NOT NULL,
    instrument_id TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (band_id, member_id, instrument_id),
    KEY idx_bm_member (member_id),                     -- 「この人の出演履歴」を速く引くため
    KEY idx_bm_instrument (instrument_id),
    CONSTRAINT fk_bm_band       FOREIGN KEY (band_id)       REFERENCES band (band_id)
        ON UPDATE CASCADE ON DELETE CASCADE,           -- バンドを消すと出演記録も消える
    CONSTRAINT fk_bm_member     FOREIGN KEY (member_id)     REFERENCES member (member_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,          -- 出演記録がある人は消せない
    CONSTRAINT fk_bm_instrument FOREIGN KEY (instrument_id) REFERENCES instrument (instrument_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
