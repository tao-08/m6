-- =====================================================================
--  初回ログインのチュートリアル（partials/tutorial.php）を「見たかどうか」を記録する
--  023_band_setlist_private.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  NULL = まだ見ていない。既存のアカウントも全員 NULL で始まるので、次に開いたとき1回だけ出る。
--  localStorage ではなく DB に置くのは、スマホと PC など端末を変えるたびに何度も出ないようにするため。
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE user_account
    ADD COLUMN tutorial_seen_at DATETIME NULL AFTER member_id;
