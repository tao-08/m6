-- =====================================================================
--  バンド1組ずつに YouTube のリンク（そのバンドの演奏動画）を持たせる
--  011_live_break.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
-- =====================================================================
SET NAMES utf8mb4;

-- band.youtube_url — バンド編集で入れた YouTube の URL。無ければ NULL
--   保存前に PHP（youtube_url_valid）で youtube.com / youtu.be の https のリンクかチェックしている
--   1バンドに1本なので band の列にする（別テーブルにするほどの多対多ではない）
ALTER TABLE band
    ADD COLUMN youtube_url VARCHAR(500) NULL AFTER note;
