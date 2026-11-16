-- =====================================================================
--  114_user_deactivation.sql
--  退会アカウントを物理削除せず無効化し、投稿者名・履歴を保持する。
--  113_sched_google.sql まで流した DB（本番も含む）に追加で流す。
--  （新規構築では schema.sql に含まれているので不要）
--  ★ 本番ではこのSQLを先に実行してからコードをデプロイする。
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE user_account
    ADD COLUMN deactivated_at DATETIME NULL AFTER created_at;
