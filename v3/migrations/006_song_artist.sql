-- =====================================================================
--  オムニバスのバンドと、曲ごとのアーティスト
--  005_music_app.sql を流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  band.is_omnibus — 1 = いろんなアーティストの曲をやるバンド
--    曲の編集画面の「オムニバス」チェック。0 なら全曲バンドのアーティスト。
-- ---------------------------------------------------------------------
ALTER TABLE band
    ADD COLUMN is_omnibus TINYINT(1) NOT NULL DEFAULT 0 AFTER note;

-- ---------------------------------------------------------------------
--  song.artist_id — その曲のアーティスト（オムニバスのときだけ入れる）
--    NULL = バンドのアーティスト（band.artist_id）と同じ。
--    アーティストを消したら NULL に戻す（曲は消さない）
-- ---------------------------------------------------------------------
ALTER TABLE song
    ADD COLUMN artist_id INT UNSIGNED NULL AFTER title,
    ADD KEY idx_song_artist (artist_id),
    ADD CONSTRAINT fk_song_artist FOREIGN KEY (artist_id) REFERENCES artist (artist_id)
        ON UPDATE CASCADE ON DELETE SET NULL;
