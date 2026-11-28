-- =====================================================================
--  158_yt_drive_folder.sql — 「MP3 を紐付け」で紐付けたドライブのフォルダを覚える
-- =====================================================================
--  157_chapter_song_no.sql まで流した DB（本番も含む）に、追加で流す。
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--  yt_drive_folder … 紐付けたフォルダ（解析の状況のチャプタータブの下に並べ、URL の編集・解除ができる。api_yt_drive.php）
--  band_drive_file.yt_folder … そのファイルを足したフォルダ。解除するとそのフォルダの分をバンドから外す。NULL = 手で足した・158 より前に紐付けた
--    158 より前に紐付けたフォルダは記録が無いので、もう一度紐付けると一覧に入る（同じファイルは区分を直すだけで二重にならない）
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE yt_drive_folder (
    folder_id  VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (folder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE band_drive_file
    ADD COLUMN yt_folder VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER video_id,
    ADD KEY idx_bdf_yt_folder (yt_folder);
