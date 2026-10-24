-- =====================================================================
--  パスワードの再設定（管理者が再設定用 URL を発行する。users.php → password_reset.php）
-- =====================================================================
--  064_member_bio.sql まで流した DB（本番も含む）に、追加で流す。
--  password_reset … 1人1件。発行し直すと上書きされ、古い URL は使えなくなる
--    token_hash … URL に載せたトークンの sha256。生のトークンは保存しない（DB が漏れても URL を作れない）
--  password_changed_at … パスワードを変えた日時。セッションの写しと違えば、その端末はログアウト（lib/bootstrap.php の require_login）
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE password_reset (
    user_id    INT UNSIGNED NOT NULL,
    token_hash CHAR(64)     NOT NULL,
    expires_at DATETIME     NOT NULL,
    created_by INT UNSIGNED NULL,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_reset_token (token_hash),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES user_account (user_id) ON DELETE CASCADE,
    CONSTRAINT fk_reset_creator FOREIGN KEY (created_by) REFERENCES user_account (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE user_account ADD COLUMN password_changed_at DATETIME NULL AFTER password_hash;
