-- =====================================================================
--  アカウント名（user_account.name）をメンバー名（member.name）にそろえる
--  008_live_youtube.sql を流した DB（本番も含む）に、1回だけ追加で流す。
--  これ以降は PHP（sync_account_names）が名前の変更・紐付けのたびに自動でそろえる。
-- =====================================================================
SET NAMES utf8mb4;

-- メンバーと紐付いているアカウントだけ。未紐付けの人は自分の名前のまま
UPDATE user_account u
    JOIN member m ON m.member_id = u.member_id
    SET u.name = m.name;
