-- =====================================================================
--  151_song_kana.sql — 曲名のふりがな（kana.php で AI に付けてもらい、確認して保存する）
-- =====================================================================
--  150_band_video.sql まで流した DB（本番も含む）に、追加で流す。
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--  曲は song・song_catalog・song_tab・song_link に散らばっているので、曲名そのものをキーにする（同じ曲名は同じ読み）
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE song_kana (
    title VARCHAR(100) NOT NULL,
    kana  VARCHAR(100) NOT NULL,
    PRIMARY KEY (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
