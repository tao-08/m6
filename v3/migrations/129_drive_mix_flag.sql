-- =====================================================================
--  129_drive_mix_flag.sql — ドライブのファイルに「ミックス」の印を付けられるようにする
-- =====================================================================
--  128_band_audio.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--
--  band_drive_file.is_mix … 1 = ミックス音源。ミックスのファイルか MP3（band_audio）があるバンドに MIX の印が付く
--    いま紐付いているファイルはすべてミックスとして扱う（UPDATE）
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE band_drive_file
    ADD COLUMN is_mix TINYINT(1) NOT NULL DEFAULT 0 AFTER mime_type;

UPDATE band_drive_file SET is_mix = 1;
