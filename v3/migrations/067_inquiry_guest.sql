-- =====================================================================
--  ログインできない人からの問い合わせ（contact_guest.php）
-- =====================================================================
--  066_drop_password_reset.sql まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  inquiry.sender_name … ログインできない人が自分で書いた氏名（user_id は NULL のまま）
--  inquiry.contact     … 返信先（メールアドレスなど）。自由入力
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE inquiry
    ADD COLUMN sender_name VARCHAR(50)  NULL AFTER user_id,
    ADD COLUMN contact     VARCHAR(100) NULL AFTER sender_name;
