-- =====================================================================
--  練習プレイヤーの戻す・進める秒数とリピート前後幅を、動画ごとでなく人ごとにする
-- =====================================================================
--  080_link_shared_mark.sql まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  user_account.practice_back … 戻す・進める秒数 1〜60。NULL = まだ決めていない（動画ごとの song_link_practice の値を使う）
--  user_account.practice_pad  … リピートの前後に足す秒数 0〜10。同上
--  未ログインは端末（localStorage）に持つ
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE user_account
    ADD COLUMN practice_back TINYINT UNSIGNED NULL,
    ADD COLUMN practice_pad  TINYINT UNSIGNED NULL;
