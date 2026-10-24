-- =====================================================================
--  流れる TAB 譜（tab.php）と曲データベース（songs.php）
-- =====================================================================
--  067_inquiry_guest.sql まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  含まれるテーブル: song_tab（TAB 譜）・song_tab_revision（編集履歴）・song_tab_practice（練習用マーカー）・song_catalog（手動で登録した曲）
--
--  song_tab — 曲 × パート の TAB 譜1つ
--    「曲」の同一性は song_key（文字列）で決める。形は2通り:
--      ・Spotify / iTunes に紐付けた曲 … "spotify:ID" / "itunes:ID"（album_key() と同じ。同じ曲を別のバンドがやっても同じ TAB を使える）
--      ・紐付けていない曲 … 全員で共通に使える ID が無いので、曲名から作る
--          アーティストが分かる（song.artist_id ?? band.artist_id）… "t:a<artist_id>|<曲名のキー>"
--          アーティストが無い（オリジナル曲）                      … "t:b<band_id>|<曲名のキー>"（そのバンドの曲）
--        曲名のキーは song_title_key()（lib/tracks.php）。表記ゆれをそろえるので、
--        "Lemon" と "Lemon - Acoustic ver." は同じ曲になる
--      既知の制限: 紐付けていなかった曲をあとで Spotify / iTunes に紐付けると、キーが変わって別の曲扱いになる
--    track_source / track_id … 紐付けた曲のときだけ入る（ジャケット表示用。曲が消えたら NULL になるだけで TAB は残る）
--    title / artist_name    … 表示用の控え（紐付け無しでも画面が出せるように）
--    UNIQUE (song_key, part): 同じ曲に「Gt1」が2つできない
--    data … 小節と音符（JSON）。1音 = 1行にすると保存のたびに何百行も消して入れ直すことになるので、
--           TAB 譜1つを1つの文書として持つ。中身の形は api_tab_save.php が検証してから入れる
--    歌詞は保存しない（著作権）。小節に付けられるのはセクション名などの短いメモだけ
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE song_tab (
    tab_id        INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    song_key      VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    track_source  VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL,
    track_id      VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    title         VARCHAR(100)     NOT NULL,
    artist_name   VARCHAR(255)     NULL,
    part          VARCHAR(20)      NOT NULL,                 -- Gt1 / Ba など
    instrument_id TINYINT UNSIGNED NOT NULL,                 -- Gt / Ba / Dr
    kind          ENUM('string', 'drum') NOT NULL DEFAULT 'string', -- drum = ドラム譜（tuning にレーンの略号が入り、fret は奏法になる。lib/tabs.php）
    first_bar     SMALLINT UNSIGNED NOT NULL DEFAULT 1,      -- 小節番号の最初（部分 TAB は途中の小節から。1 = 曲の頭から）
    is_partial    TINYINT(1)       NOT NULL DEFAULT 0,       -- 1 = 部分 TAB（ギターソロだけ等）。offset_ms = その部分が音源のどこから始まるか
    tuning        VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- 低い弦から "E A D G B E"（ドラムは上のレーンから "CR RD HH SD T1 T2 FT BD"）
    bpm           DECIMAL(5,2)     NOT NULL DEFAULT 120,
    beats_per_bar TINYINT UNSIGNED NOT NULL DEFAULT 4,
    steps_per_beat TINYINT UNSIGNED NOT NULL DEFAULT 4,      -- 4 = 16分音符まで
    offset_ms     INT UNSIGNED     NOT NULL DEFAULT 0,       -- 1小節目が始まる位置（音源の何ミリ秒目か）
    capo          TINYINT UNSIGNED NOT NULL DEFAULT 0,       -- カポ（0〜12）。フレットはカポを基準に書く（ふつうの TAB と同じ。カポ2なら、カポの位置が0）
    youtube_id    VARCHAR(11) CHARACTER SET ascii COLLATE ascii_bin NULL,
    links         MEDIUMTEXT       NOT NULL DEFAULT '[]',     -- 参考にする TAB サイトなどのリンク（JSON。[{"u": URL, "l": 表示名}]、最大10個。履歴・復元にも含まれる）
    memo          MEDIUMTEXT       NULL,                      -- この TAB（パート）のメモ（20000文字まで。URL は自動でリンクになる）
    data          MEDIUMTEXT       NOT NULL,
    created_by    INT UNSIGNED     NULL,
    updated_by    INT UNSIGNED     NULL,
    updated_at    DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3), -- ミリ秒まで（同じ1秒に2人が保存しても楽観ロックで気付けるように）
    PRIMARY KEY (tab_id),
    UNIQUE KEY uq_tab_part (song_key, part),
    KEY idx_tab_track (track_source, track_id),
    KEY idx_tab_instrument (instrument_id),
    CONSTRAINT fk_tab_track FOREIGN KEY (track_source, track_id) REFERENCES track (source, track_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_tab_instrument FOREIGN KEY (instrument_id) REFERENCES instrument (instrument_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_tab_created FOREIGN KEY (created_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_tab_updated FOREIGN KEY (updated_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_tab_data CHECK (JSON_VALID(data)),
    CONSTRAINT ck_tab_links CHECK (JSON_VALID(links)),
    CONSTRAINT ck_tab_capo CHECK (capo BETWEEN 0 AND 12),
    CONSTRAINT ck_tab_first_bar CHECK (first_bar BETWEEN 1 AND 999),
    CONSTRAINT ck_tab_bpm CHECK (bpm BETWEEN 20 AND 400),
    CONSTRAINT ck_tab_beats CHECK (beats_per_bar BETWEEN 1 AND 16),
    CONSTRAINT ck_tab_steps CHECK (steps_per_beat IN (1, 2, 3, 4, 6, 8))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  song_tab_revision — TAB 譜の編集履歴（作成 / 保存 / 削除 / 復元）。tab.php の「履歴」で見て、戻せる
--    サイト全体の変更履歴（lib/history.php）とは別物。TAB 譜は1つが大きな JSON なので、
--    「その時点の行まるごと」を snapshot に入れておいて、そこへ戻す（差分は持たない）
--    snapshot … 作成・保存・復元は「その操作のあとの状態」、削除は「消す直前の状態」。data は配列にして入れる
--    tab_id に外部キーを張らないのは意図的: song_tab の行が消えても履歴を残して、削除した TAB を復元できるようにするため
--      （復元のときは同じ tab_id で入れ直す）
--    同じ人の10分以内の連続した「保存」は1行にまとめる。1つの TAB につき新しい200件だけ残す（lib/tabs.php の tab_record_revision）
-- ---------------------------------------------------------------------
CREATE TABLE song_tab_revision (
    rev_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tab_id     INT UNSIGNED NOT NULL,
    song_key   VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    part       VARCHAR(20)  NOT NULL,
    action     ENUM('create', 'save', 'delete', 'restore') NOT NULL,
    snapshot   MEDIUMTEXT   NOT NULL,
    user_id    INT UNSIGNED NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (rev_id),
    KEY idx_rev_tab (tab_id, rev_id),
    KEY idx_rev_key (song_key, rev_id),
    CONSTRAINT fk_rev_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_rev_snapshot CHECK (JSON_VALID(snapshot))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  song_tab_practice — 練習用マーカー（自分だけに見える。人ごと・TAB ごとに1行）
--    data … {"m": [{"t": ミリ秒, "e": ミリ秒 or null, "l": "ラベル（20文字まで）"}], "ab": [A, B] or null, "bpm": 練習BPM or null}
--    編集履歴（song_tab_revision）には入れない。JS が止まってから約1秒で自動保存する
--    tab_id に外部キーを張らないのは意図的: 履歴と同じ理由で、TAB を削除して復元しても（同じ tab_id で戻る）マーカーが残るように
--      （TAB を消したまま復元しない場合は、行が残り続けるだけで害は無い）
-- ---------------------------------------------------------------------
CREATE TABLE song_tab_practice (
    tab_id     INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    data       MEDIUMTEXT   NOT NULL,
    updated_at DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (tab_id, user_id),
    CONSTRAINT fk_practice_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT ck_practice_data CHECK (JSON_VALID(data))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- ---------------------------------------------------------------------
--  song_meta — 曲ごとのメモ（全パート共通。曲キー song_key で結び付く。最初に保存したときに作る）
--    song_tab_image / song_meta_image — TAB・曲に添付した画像（耳コピのメモ、手書きの譜面など。lib/attach_image.php の kind）
--      song_tab_image の tab_id に外部キーを張らないのは意図的: TAB を削除して復元しても（同じ tab_id で戻る）画像が残るように
--      （履歴・マーカーと同じ理由。TAB を消したまま復元しなければ、画像のファイルと行が残り続けるだけ）
-- ---------------------------------------------------------------------
CREATE TABLE song_meta (
    meta_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    song_key   VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    memo       MEDIUMTEXT   NULL,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (meta_id),
    UNIQUE KEY uq_meta_key (song_key),
    CONSTRAINT fk_meta_updated FOREIGN KEY (updated_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE song_tab_image (
    image_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tab_id     INT UNSIGNED NOT NULL,
    file_name  VARCHAR(40)  NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (image_id),
    KEY idx_tabimg_tab (tab_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE song_meta_image (
    image_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    meta_id    INT UNSIGNED NOT NULL,
    file_name  VARCHAR(40)  NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (image_id),
    KEY idx_metaimg_meta (meta_id, sort_order),
    CONSTRAINT fk_metaimg_meta FOREIGN KEY (meta_id) REFERENCES song_meta (meta_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- ---------------------------------------------------------------------
--  song_tab_ai_usage — AI で TAB を読み取る回数（ユーザーごと・日ごと。1日の上限は lib/tab_ai.php の TAB_AI_DAILY_LIMIT）
--    AI は1回ごとにお金がかかるので、使いすぎを止めるための小さな表。7日より古い行は呼ぶときに掃除する
-- ---------------------------------------------------------------------
CREATE TABLE song_tab_ai_usage (
    user_id INT UNSIGNED      NOT NULL,
    day     DATE              NOT NULL,
    n       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, day),
    CONSTRAINT fk_aiusage_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- ---------------------------------------------------------------------
--  song_catalog — 曲データベース（songs.php。管理人だけ）に「手動で登録した曲」
--    セットリストに出てこない曲（これから練習する曲など）にも TAB 譜を付けられるようにする入口。
--    セットリストの曲（song）は自動で一覧に載るので、ここに入れるのは手動で足した曲だけ。
--    song_key は song_tab と同じ形（紐付けた曲は "spotify:ID" / "itunes:ID"、紐付けなしは "t:a<artist_id>|<曲名のキー>"）。
--      紐付けなしの手動登録は、アーティストマスタ（artist）にいるアーティストを必ず選ぶ。
--      自由入力のアーティストを許すと "t:b…" とは別の形のキーが要り、表記ゆれで同じ曲が別のキーになってしまうため
--    UNIQUE (song_key): 同じ曲を2回登録できない
--    track_source / track_id … 紐付けた曲のときだけ入る。曲（track）が消えても行は残る（ON DELETE SET NULL）
-- ---------------------------------------------------------------------
CREATE TABLE song_catalog (
    catalog_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    song_key     VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    title        VARCHAR(100) NOT NULL,
    artist_name  VARCHAR(255) NULL,
    artist_id    INT UNSIGNED NULL,
    track_source VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL,
    track_id     VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_by   INT UNSIGNED NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (catalog_id),
    UNIQUE KEY uq_catalog_key (song_key),
    KEY idx_catalog_artist (artist_id),
    KEY idx_catalog_track (track_source, track_id),
    CONSTRAINT fk_catalog_artist FOREIGN KEY (artist_id) REFERENCES artist (artist_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_catalog_track FOREIGN KEY (track_source, track_id) REFERENCES track (source, track_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_catalog_created FOREIGN KEY (created_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
