-- =====================================================================
--  141_band_acr_file.sql — 動画から曲を検出する（ACRCloud）解析の ID
-- =====================================================================
--  140_board_report.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--  band.acr_file_id … バンド編集で送った音の、ACRCloud File Scanning の file_id。NULL = 送っていない
--    結果は ACRCloud 側にあり、songs_edit.php?from_acr=1 が取りに行く（lib/acrcloud.php）
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE band ADD COLUMN acr_file_id VARCHAR(64) NULL AFTER youtube_url;
