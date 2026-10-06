-- =====================================================================
--  曲を Spotify / iTunes の曲と紐付ける（ジャケット・聴くリンク）
--  006_song_artist.sql を流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  track — Spotify / iTunes の曲1つ（曲の編集画面で🔍検索して紐付けたもの）
--    登録した瞬間の内容を保存する（スナップショット）。表示のたびに外部 API へ聞きに行かない。
--    主キー (source, track_id): 同じ曲を何バンドが紐付けても1行
-- ---------------------------------------------------------------------
CREATE TABLE track (
    source       VARCHAR(10)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- 'itunes' / 'spotify'
    track_id     VARCHAR(40)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- そのサービスでの曲ID
    title        VARCHAR(255)      NOT NULL,
    artist_name  VARCHAR(255)      NOT NULL,
    album_title  VARCHAR(255)      NOT NULL,
    artwork_url  VARCHAR(500)      NOT NULL,
    release_year SMALLINT UNSIGNED NULL,
    PRIMARY KEY (source, track_id),
    CONSTRAINT ck_track_source CHECK (source IN ('itunes', 'spotify'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
--  song.(track_source, track_id) — 紐付けた曲。両方 NULL = 紐付けなし
--    track の行が消えたら NULL に戻す（演奏した記録の方は消さない）
-- ---------------------------------------------------------------------
ALTER TABLE song
    ADD COLUMN track_source VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER artist_id,
    ADD COLUMN track_id     VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER track_source,
    ADD KEY idx_song_ext_track (track_source, track_id),
    ADD CONSTRAINT fk_song_ext_track FOREIGN KEY (track_source, track_id) REFERENCES track (source, track_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    ADD CONSTRAINT ck_song_ext_track CHECK ((track_source IS NULL) = (track_id IS NULL));

-- ---------------------------------------------------------------------
--  track_link_cache — 「この曲は、このアプリだとこの URL」の覚え書き（album_link_cache の曲版）
-- ---------------------------------------------------------------------
CREATE TABLE track_link_cache (
    source     VARCHAR(10)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    track_id   VARCHAR(40)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    app        VARCHAR(20)  CHARACTER SET ascii NOT NULL,
    url        VARCHAR(500) NULL,
    checked_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (source, track_id, app),
    CONSTRAINT fk_tlc_track FOREIGN KEY (source, track_id) REFERENCES track (source, track_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT ck_tlc_app CHECK (app IN ('spotify', 'apple_music', 'youtube_music', 'line_music'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
