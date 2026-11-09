-- =====================================================================
--  ライブ・バンドに Google ドライブのリンク（ラインミックスの音源）を持たせる
--  100_timetable_draft_live.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  YouTube（008 / 012）と同じ形にしている:
--    live.drive_url … ラインミックスを入れたフォルダのリンク（中のファイルをバンドに割り当てる元）
--    band.drive_url … そのバンドの音源ファイルのリンク（ライブ・バンドのページで再生する）
--  1バンドに1ファイルなので band の列にする（多対多ではないので中間テーブルは作らない）
-- =====================================================================
SET NAMES utf8mb4;

-- live.drive_url — ライブ編集で入れたドライブのフォルダの URL。無ければ NULL
--   保存前に PHP（drive_folder_id）で https://drive.google.com/drive/folders/… の形かチェックしている
ALTER TABLE live
    ADD COLUMN drive_url VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL AFTER youtube_url;

-- band.drive_url — そのバンドの音源ファイルの URL（https://drive.google.com/file/d/ID/view の形にそろえて保存）。無ければ NULL
ALTER TABLE band
    ADD COLUMN drive_url VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL AFTER youtube_url;
