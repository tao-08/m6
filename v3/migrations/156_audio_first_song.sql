-- =====================================================================
--  156_audio_first_song.sql — 音源ごとの「開始の曲」
-- =====================================================================
--  155_special_view_year.sql まで流した DB（本番も含む）に、追加で流す。
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--  途中の曲から始まる MIX のため。1つ目の曲のチャプター = セトリ（SE 以外）の first_song 曲目。
--  行が無い = 1曲目から（1 は保存しない）
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE band_audio_first (
    band_id    INT UNSIGNED NOT NULL,
    audio_key  VARCHAR(110) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- band_audio_chapter.audio_key と同じ
    first_song SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (band_id, audio_key),
    CONSTRAINT fk_baf_band FOREIGN KEY (band_id) REFERENCES band (band_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
