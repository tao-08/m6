-- =====================================================================
--  ライブに YouTube のリンク（ライブ映像・プレイリストなど）を持たせる
--  007_song_track.sql を流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
-- =====================================================================
SET NAMES utf8mb4;

-- live.youtube_url — ライブ編集で入れた YouTube の URL。無ければ NULL
--   保存前に PHP（youtube_url_valid）で youtube.com / youtu.be の https のリンクかチェックしている
ALTER TABLE live
    ADD COLUMN youtube_url VARCHAR(500) NULL AFTER name;
