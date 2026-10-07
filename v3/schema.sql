-- =====================================================================
--  AbbeyRoad.online v3 — 「一番合理的な構成」で設計し直したスキーマ
-- =====================================================================
--  local_abbeydb とは別の DB に流すこと（例: abbey_v3）。
--    phpMyAdmin で DB を作成 → 「インポート」でこのファイルを選ぶ
--
--  ER 図（1 ─< 多）
--
--    live ─< live_day ─< band >─ band_member ─< member ── user_account
--               │          │   │       │   ╲
--             venue   artist   │  instrument ╲
--                              └─< song ─< song_performer（曲ごとに誰が何を弾いたか）
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
-- 作り直し用: すでにテーブルがあれば消してから作る。
-- まっさらな DB に流すと「Note: #1051 '〜' は不明な表です」が表の数だけ出るが、
-- これは「消そうとした表が最初から無かった」というだけの“お知らせ”で、エラーではない。
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS artist_alias, member_favorite_album, song_performer, song, band_member, live_break, band, artist, live_day, live, venue, instrument, user_account, member;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
--  member — サークル員（出演する人）
-- ---------------------------------------------------------------------
CREATE TABLE member (
    member_id  INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    name       VARCHAR(50)       NOT NULL,             -- 表示名（空白なしで統一して保存）
    name_kana  VARCHAR(50)       NULL,                 -- 不明なら NULL（'' で代用しない）
    entry_year SMALLINT UNSIGNED NULL,                 -- 入部年度
    music_app  VARCHAR(20) CHARACTER SET ascii NULL,  -- 使っている音楽アプリ（マイアルバムのリンクをこれで開く）。NULL = 選んでいない
    created_at DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (member_id),
    UNIQUE KEY uq_member_name (name),
    CONSTRAINT ck_member_entry CHECK (entry_year IS NULL OR entry_year BETWEEN 1950 AND 2100),
    CONSTRAINT ck_member_music_app
        CHECK (music_app IS NULL OR music_app IN ('spotify', 'apple_music', 'youtube_music', 'line_music'))
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
    youtube_url VARCHAR(500)      NULL,                -- ライブ映像などの YouTube のリンク。無ければ NULL（migrations/008）
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
--  artist_alias — アーティストの別名（Oasis ⇔ オアシス など）
--    マイアルバムの artist_name は Spotify / iTunes の表記そのまま（Apple はカタカナのことがある）。
--    文字列の比較だけでは結び付けられないので、管理者が artist.php で別名を登録する。
--    主キー name: 1つの別名は1アーティストだけを指す
--    外部キー artist_id → artist: アーティストが消えたら別名も消える（統合時は artist.php が先に付け替える）
-- ---------------------------------------------------------------------
CREATE TABLE artist_alias (
    name      VARCHAR(255) NOT NULL,   -- 別名。マイアルバムの artist_name と同じ長さ
    artist_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (name),
    KEY idx_alias_artist (artist_id),
    CONSTRAINT fk_alias_artist FOREIGN KEY (artist_id) REFERENCES artist (artist_id)
        ON UPDATE CASCADE ON DELETE CASCADE
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
    youtube_url VARCHAR(500)      NULL,                -- このバンドの演奏動画の YouTube のリンク。無ければ NULL（migrations/012）
    is_omnibus  TINYINT(1)        NOT NULL DEFAULT 0,  -- 1 = いろんなアーティストの曲をやるバンド（曲ごとに song.artist_id を持つ）
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
--  live_break — タイムテーブルの「休憩・転換・撤収」などバンドではない枠
--    band に混ぜると、バンドを数える所すべてで「休憩を除く」条件が要るので別テーブル。
--    並び: 「出演順が after_order のバンドの後」（0 = 最初のバンドより前）。同じ場所に複数なら seq 順
-- ---------------------------------------------------------------------
CREATE TABLE live_break (
    break_id    INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    live_day_id INT UNSIGNED      NOT NULL,
    after_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- この出演順のバンドの後（0 = 最初のバンドより前）
    seq         TINYINT UNSIGNED  NOT NULL DEFAULT 0,  -- 同じ場所に2つ以上あるときの並び
    name        VARCHAR(50)       NOT NULL,            -- 休憩 / 転換 / 撤収 など
    start_time  TIME              NULL,
    end_time    TIME              NULL,
    PRIMARY KEY (break_id),
    KEY idx_break_day (live_day_id, after_order, seq),
    CONSTRAINT fk_break_day FOREIGN KEY (live_day_id) REFERENCES live_day (live_day_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT ck_break_time CHECK (start_time IS NULL OR end_time IS NULL OR end_time > start_time)
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

-- ---------------------------------------------------------------------
--  track — Spotify / iTunes の曲1つ（曲の編集画面で🔍検索して紐付けたもの）
--    登録した瞬間の内容を保存する（スナップショット）。表示のたびに外部 API へ聞きに行かない。
--    主キー (source, track_id): 「どのサービスの何番の曲か」。同じ曲を何バンドが紐付けても1行
--    source / track_id は ascii_bin（Spotify の ID は大文字小文字を区別するため。004 と同じ理由）
-- ---------------------------------------------------------------------
CREATE TABLE track (
    source       VARCHAR(10)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- 'itunes' / 'spotify'
    track_id     VARCHAR(40)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- そのサービスでの曲ID
    title        VARCHAR(255)      NOT NULL,                                  -- 曲名（サービスでの表記）
    artist_name  VARCHAR(255)      NOT NULL,
    album_title  VARCHAR(255)      NOT NULL,
    artwork_url  VARCHAR(500)      NOT NULL,                                  -- ジャケット（小さめ）
    release_year SMALLINT UNSIGNED NULL,
    PRIMARY KEY (source, track_id),
    CONSTRAINT ck_track_source CHECK (source IN ('itunes', 'spotify'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  song — バンドが演奏した曲（セットリスト）
--    UNIQUE (band_id, track_no): 同じバンドの「2曲目」が2つできない
--    UNIQUE (song_id, band_id) : song_id だけで一意なので意味は同じだが、
--      下の song_performer から「(曲, バンド) の組」で外部キーを張るために必要
--    artist_id: オムニバスのバンド（band.is_omnibus = 1）のときだけ入れる。
--      NULL = バンドのアーティスト（band.artist_id）と同じ。同じ値を全曲にコピーしないため
--    (track_source, track_id): 紐付けた Spotify / iTunes の曲。両方 NULL = 紐付けなし
-- ---------------------------------------------------------------------
CREATE TABLE song (
    song_id      INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    band_id      INT UNSIGNED     NOT NULL,
    track_no     TINYINT UNSIGNED NOT NULL,            -- 何曲目か
    title        VARCHAR(100)     NOT NULL,
    artist_id    INT UNSIGNED     NULL,                -- その曲のアーティスト（NULL = バンドと同じ）
    track_source VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL,
    track_id     VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (song_id),
    UNIQUE KEY uq_song_track (band_id, track_no),
    UNIQUE KEY uq_song_band (song_id, band_id),
    KEY idx_song_artist (artist_id),
    KEY idx_song_ext_track (track_source, track_id),
    CONSTRAINT fk_song_band FOREIGN KEY (band_id) REFERENCES band (band_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_song_artist FOREIGN KEY (artist_id) REFERENCES artist (artist_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_song_ext_track FOREIGN KEY (track_source, track_id) REFERENCES track (source, track_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_song_ext_track CHECK ((track_source IS NULL) = (track_id IS NULL)), -- 片方だけ入っている行を作らない
    CONSTRAINT ck_song_track CHECK (track_no >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  song_performer — その曲で、誰が、何の楽器を演奏したか
--
--    外部キーを「(band_id, member_id, instrument_id) → band_member」に張っているのがポイント。
--    → 「そのバンドのメンバーとして登録されていない人・楽器」は曲の演奏者にできない、を DB が保証する。
--      （曲だけ別の楽器を弾いた場合は、先に band_member にその楽器を足す。songs_edit.php が自動でやる）
--    → バンドからメンバーを外すと（band_member の行を消すと）、その人の曲ごとの記録も CASCADE で消える。
--
--    (song_id, band_id) も外部キーにしているので、
--    「Aバンドの曲に、Bバンドのメンバーを登録する」ような食い違いも起きない。
-- ---------------------------------------------------------------------
CREATE TABLE song_performer (
    song_id       INT UNSIGNED     NOT NULL,
    band_id       INT UNSIGNED     NOT NULL,
    member_id     INT UNSIGNED     NOT NULL,
    instrument_id TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (song_id, member_id, instrument_id),
    KEY idx_sp_member (member_id),
    KEY idx_sp_role (band_id, member_id, instrument_id),
    CONSTRAINT fk_sp_song FOREIGN KEY (song_id, band_id) REFERENCES song (song_id, band_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_sp_role FOREIGN KEY (band_id, member_id, instrument_id)
        REFERENCES band_member (band_id, member_id, instrument_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ---------------------------------------------------------------------
--  member_favorite_album — メンバーが登録した「好きなアルバム」
--
--    ・1人で最大30枚（上限はアプリ側 member_album_save.php で数えて止める）
--    ・アルバムの情報は Spotify（無ければ iTunes）から取ってきて「登録した瞬間の内容」を保存する
--      （スナップショット保存。ページを開くたびに外部サービスに聞きに行かない
--        → 表示が速い & 外部サービスが止まってもページは壊れない）
--
--    主キー (member_id, sort_order)
--      → 「同じ人の3枚目」が2つできない。並び順そのものが行の住所になる。
--      ※ 列名を rank にしなかったのは、RANK が MySQL 8 で予約語（関数名）だから。
--        予約語を列名にすると毎回 `rank` とバッククォートで囲む必要があり、事故の元。
--        instrument テーブルの sort_order と名前を揃えた。
--
--    UNIQUE (member_id, source, album_id)
--      → 同じ人が同じアルバムを2回登録できない、を DB が保証する。
--      source = どのサービスから取ったか（'itunes' / 'spotify'）。ID の形がサービスごとに違うので2列で決める。
--      album_id は ascii_bin: Spotify の ID は大文字小文字を区別するので、区別して比べる照合順序にする
--
--    外部キー member_id → member
--      ON DELETE CASCADE: メンバーが消えたら（統合など）アルバムも一緒に消える（孤児データを残さない）
--      ON UPDATE CASCADE: member_id が変わったらこっちも追従する
-- ---------------------------------------------------------------------
CREATE TABLE member_favorite_album (
    member_id            INT UNSIGNED      NOT NULL,
    sort_order           SMALLINT UNSIGNED NOT NULL,          -- 表示順 1, 2, 3...（小さいほど前）
    source               VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- 'itunes' か 'spotify'
    album_id             VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- そのサービスでのアルバムID
    title                VARCHAR(255)      NOT NULL,          -- アルバム名
    artist_name          VARCHAR(255)      NOT NULL,          -- アーティスト名
    artwork_url          VARCHAR(500)      NOT NULL,          -- ジャケット画像のURL（600x600）
    release_year         SMALLINT UNSIGNED NULL,              -- 発売年。分からなければ NULL
    created_at           DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (member_id, sort_order),
    UNIQUE KEY uq_fav_album (member_id, source, album_id),
    CONSTRAINT fk_fav_member FOREIGN KEY (member_id) REFERENCES member (member_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT ck_fav_order CHECK (sort_order >= 1),
    CONSTRAINT ck_fav_source CHECK (source IN ('itunes', 'spotify'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  album_link_cache — 「このアルバムは、このアプリだとこの URL」の覚え書き（キャッシュ）
--
--    例: Spotify から登録したアルバムを Apple Music の人が押したとき、
--        iTunes で検索して見つけた Apple Music の URL をここに保存しておく。
--        → 2回目からは検索しないで済む（速い & 外部 API を叩きすぎない）
--
--    主キー (source, album_id, app): 「どのサービスの何番のアルバムを、どのアプリで開くか」で1行
--    url が NULL = 探したけど見つからなかった（そのときは検索ページを開く。毎回探し直さないために記録する）
--
--    ※ 外部キーは張っていない。source + album_id は Spotify / iTunes 側の ID で、
--      このDBに「アルバム」の親テーブルは無いため（member_favorite_album は「誰が登録したか」の表で、
--      同じアルバムが何人分も入るので参照先にできない）。消えても困らない「覚え書き」なのでこれで良い。
--    ※ source / album_id は 004 と同じく ascii_bin（Spotify の ID は大文字小文字を区別するため）
-- ---------------------------------------------------------------------
CREATE TABLE album_link_cache (
    source     VARCHAR(10)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- 'itunes' / 'spotify'
    album_id   VARCHAR(40)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- そのサービスでのアルバムID
    app        VARCHAR(20)  CHARACTER SET ascii NOT NULL,                   -- 開くアプリ（member.music_app と同じ値）
    url        VARCHAR(500) NULL,                                           -- 見つかった URL。NULL = 見つからなかった
    checked_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,             -- 調べた日時
    PRIMARY KEY (source, album_id, app),
    CONSTRAINT ck_alc_source CHECK (source IN ('itunes', 'spotify')),
    CONSTRAINT ck_alc_app CHECK (app IN ('spotify', 'apple_music', 'youtube_music', 'line_music'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  track_link_cache — 「この曲は、このアプリだとこの URL」の覚え書き（album_link_cache の曲版）
--    Spotify で紐付けた曲を Apple Music の人が押したとき、iTunes で探した結果を覚えておく（song_go.php）。
--    url が NULL = 探したけど見つからなかった。
--    こちらは親の track テーブルがあるので外部キーを張る（曲が消えたら覚え書きも消える）
-- ---------------------------------------------------------------------
CREATE TABLE track_link_cache (
    source     VARCHAR(10)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    track_id   VARCHAR(40)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    app        VARCHAR(20)  CHARACTER SET ascii NOT NULL,                   -- 開くアプリ（member.music_app と同じ値）
    url        VARCHAR(500) NULL,                                           -- 見つかった URL。NULL = 見つからなかった
    checked_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (source, track_id, app),
    CONSTRAINT fk_tlc_track FOREIGN KEY (source, track_id) REFERENCES track (source, track_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT ck_tlc_app CHECK (app IN ('spotify', 'apple_music', 'youtube_music', 'line_music'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
