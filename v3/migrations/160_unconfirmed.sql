-- =====================================================================
--  160_unconfirmed.sql — 解析で入れたセトリ・チャプターに「未確認」の印を持たせる
-- =====================================================================
--  159_chapter_to_video.sql まで流した DB（本番も含む）に、追加で流す。
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--  未確認のものはシャッフル再生（api_shuffle.php）に流さない。バンドのページの「確認済みにする」で外す（band.php）
--  band.setlist_unconfirmed … 曲の検出の結果から保存したセトリ（songs_edit.php）
--  band_audio_unconfirmed … 自動で付けたチャプター（lib/chapter_job.php の chapter_job_save）。行がある音源が未確認
--  今まで流れていた分は確認済みのまま。YouTube の動画は一度すべて未確認にし、人が直した（edited）動画は
--  lib/chapter_job.php の chapter_unconfirmed_migrate が外す（デプロイ後に setlist_jobs?tab=chapter を一度開く）
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE band
    ADD COLUMN setlist_unconfirmed TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE band_audio_unconfirmed (
    band_id   INT UNSIGNED NOT NULL,
    audio_key VARCHAR(110) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (band_id, audio_key),
    CONSTRAINT fk_bau_band FOREIGN KEY (band_id) REFERENCES band (band_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO band_audio_unconfirmed (band_id, audio_key)
    SELECT DISTINCT band_id, audio_key FROM band_audio_chapter WHERE audio_key LIKE 'y:%';
