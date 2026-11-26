-- =====================================================================
--  150_band_video.sql — バンドに YouTube の動画を2本以上つなげる
-- =====================================================================
--  149_track_preview_retry_accent.sql まで流した DB（本番も含む）に、追加で流す。
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--  1本目は今までどおり band.youtube_url。2本目以降を band_video に入れる（「バンド名 1」「バンド名 2」のように分かれた動画）
--  youtube_title: 動画のタイトル（複数のときのポップアップに出す）。保存のときに oEmbed で取る
--  song.video_no: video_start が何本目の動画の秒か（0 = 1本目 = band.youtube_url、1 = band_video の sort_order 1）
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE band_video (
    band_id    INT UNSIGNED     NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL,  -- 1 = 2本目
    url        VARCHAR(500)     NOT NULL,
    PRIMARY KEY (band_id, sort_order),
    CONSTRAINT fk_bv_band FOREIGN KEY (band_id) REFERENCES band (band_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE youtube_title (
    video_id CHAR(11)     NOT NULL,
    title    VARCHAR(200) NOT NULL,
    PRIMARY KEY (video_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE song ADD COLUMN video_no TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER video_start;
