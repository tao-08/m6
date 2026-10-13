-- =====================================================================
--  アカウント設定「退会した人を名前の候補（サジェスト）に出さない」（account.php）
--  029_member_retired.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  hide_retired_suggest … TRUE = バンド編集・タイムテーブル編集・取り込みのメンバー名の候補に、
--                         退会済み（member.retired = 1）の人を出さない。初期値は「出さない」
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE user_account
    ADD COLUMN hide_retired_suggest BOOLEAN NOT NULL DEFAULT TRUE AFTER tutorial_seen_at;
