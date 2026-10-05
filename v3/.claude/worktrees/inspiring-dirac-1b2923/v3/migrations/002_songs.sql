-- =====================================================================
--  v3 に「曲（セットリスト）」と「曲ごとの演奏者」を追加する
--  すでに schema.sql を流して abbey_v3 を使っている人だけ、これを追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  song — バンドが演奏した曲（セットリスト）
--    UNIQUE (band_id, track_no): 同じバンドの「2曲目」が2つできない
--    UNIQUE (song_id, band_id) : song_id だけで一意なので意味は同じだが、
--      下の song_performer から「(曲, バンド) の組」で外部キーを張るために必要
-- ---------------------------------------------------------------------
CREATE TABLE song (
    song_id  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    band_id  INT UNSIGNED     NOT NULL,
    track_no TINYINT UNSIGNED NOT NULL,                -- 何曲目か
    title    VARCHAR(100)     NOT NULL,
    PRIMARY KEY (song_id),
    UNIQUE KEY uq_song_track (band_id, track_no),
    UNIQUE KEY uq_song_band (song_id, band_id),
    CONSTRAINT fk_song_band FOREIGN KEY (band_id) REFERENCES band (band_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
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
