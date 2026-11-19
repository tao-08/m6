-- =====================================================================
--  130_audio_chapter.sql — 音源のサイズと、再生中に付けるチャプター（MC・曲）
-- =====================================================================
--  129_drive_mix_flag.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--
--  band_drive_file.size … ドライブのファイルのバイト数（再生前にサイズを出す）。NULL = 不明（Google のドキュメントなど）。
--    すでにある行はバンドのページを開いたときに埋まる
--  band_audio_chapter … 音源の再生位置に付ける印（1行 = 1つ）
--    audio_key … 'a:<band_audio.image_id>'（アップロードした MP3）か 'd:<ドライブのファイル ID>'
--    kind      … mc = MC / song = 曲。曲の番号は持たず、時間順に 1, 2, 3… と数えてセットリストの曲名に当てる
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE band_drive_file
    ADD COLUMN size BIGINT UNSIGNED NULL AFTER mime_type;

CREATE TABLE band_audio_chapter (
    chapter_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    band_id    INT UNSIGNED NOT NULL,
    audio_key  VARCHAR(110) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kind       ENUM('mc','song') NOT NULL,
    at_ms      INT UNSIGNED NOT NULL,
    PRIMARY KEY (chapter_id),
    KEY idx_bac (band_id, audio_key, at_ms),
    CONSTRAINT fk_bac_band FOREIGN KEY (band_id) REFERENCES band (band_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
