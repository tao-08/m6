-- =====================================================================
--  サイトの設定を DB に置く（いまは招待コードだけ。users.php で管理者が変えられる）
--  020_track_preview.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  行が無い設定は config.php の値を使う（lib/bootstrap.php の invite_code()）。
--  なので、流しただけでは今までと何も変わらない。
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE app_setting (
    setting_key   VARCHAR(50)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- 'invite_code' など
    setting_value VARCHAR(255) NOT NULL,
    updated_by    INT UNSIGNED NULL,                                         -- 最後に変えた管理者
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key),
    CONSTRAINT fk_setting_user FOREIGN KEY (updated_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL                                   -- 変えた人のアカウントが消えても設定は残す
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
