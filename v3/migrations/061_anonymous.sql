-- =====================================================================
--  掲示板の書き込み・ライブ/バンドのコメントを匿名にできるようにする
-- =====================================================================
--  060_timetable_draft.sql まで流した DB（本番も含む）に、追加で流す。
--  anonymous … 1 = 匿名。画面では「匿名」と出す（管理者には本名も出す）。user_id は残す（本人の削除・管理者の対応のため）
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE board_post ADD COLUMN anonymous TINYINT(1) NOT NULL DEFAULT 0 AFTER body;
ALTER TABLE comment    ADD COLUMN anonymous TINYINT(1) NOT NULL DEFAULT 0 AFTER body;
