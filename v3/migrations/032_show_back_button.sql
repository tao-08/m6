-- =====================================================================
--  アカウント設定「左下の戻るボタンを表示する」（account.php）
--  031_sheet_template.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  show_back_button … TRUE = 左下に「← ページ名」のボタン（assets/app.js の setupBackButton）を出す。
--                     初期値は「出す」
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE user_account
    ADD COLUMN show_back_button BOOLEAN NOT NULL DEFAULT TRUE AFTER hide_retired_suggest;
