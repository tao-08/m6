-- =====================================================================
--  131_audio_wave.sql — 音源の波形（再生位置をつまんでいるときに出す音量の棒）
-- =====================================================================
--  130_audio_chapter.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--
--  band_audio_wave … 音源ごとの波形（1行 = 1音源）。最初に再生した人のブラウザで音量を計算して保存し、次からはそれを出す
--    audio_key … band_audio_chapter と同じ（'a:<band_audio.image_id>' か 'd:<ドライブのファイル ID>'）
--    levels    … 棒ごとの音量を 16 進で並べたもの（2 文字 = 1 本、00〜ff）
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE band_audio_wave (
    band_id   INT UNSIGNED NOT NULL,
    audio_key VARCHAR(110) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    levels    VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (band_id, audio_key),
    CONSTRAINT fk_baw_band FOREIGN KEY (band_id) REFERENCES band (band_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
