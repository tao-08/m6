-- =====================================================================
--  037 で DB に移した初期の型紙2つの「登録した人」を、最初の管理者にする
--  （037 のままだと created_by が NULL で、一覧に「削除済みのユーザー」と出る）
--  037_sheet_template_builtin.sql を流したあとに流す。何回流しても同じ結果
--  別の人にしたいときは、下の SELECT を WHERE login_id = 'ログインID' に変える
-- =====================================================================
SET NAMES utf8mb4;

UPDATE sheet_template
   SET created_by = (SELECT user_id FROM (SELECT MIN(user_id) AS user_id FROM user_account WHERE is_admin) AS a)
 WHERE created_by IS NULL
   AND pdf_path IN ('assets/sheets/club_voice.pdf', 'assets/sheets/club_common.pdf');
