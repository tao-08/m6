-- =====================================================================
--  掲示板の書き込み・コメントに、送信元の名義（自由入力）を付けられるようにする
-- =====================================================================
--  061_anonymous.sql まで流した DB（本番も含む）に、追加で流す。
--  display_name … 値あり = その名前で表示（管理者には本名も出す）。NULL = 本名。匿名（anonymous = 1）のときは使わない
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE board_post ADD COLUMN display_name VARCHAR(30) NULL AFTER anonymous;
ALTER TABLE comment    ADD COLUMN display_name VARCHAR(30) NULL AFTER anonymous;
