-- =====================================================================
--  お知らせ（notices.php）
--  041_utf8mb4_columns.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  notice      … 管理者が書くお知らせ
--  notice_read … 誰がどのお知らせを読んだか（中間テーブル）。行がある＝既読、無い＝未読
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE notice (
    notice_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NULL,          -- 書いた管理者。アカウントが消えても残す
    title      VARCHAR(100) NOT NULL,
    body       TEXT         NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notice_id),
    CONSTRAINT fk_notice_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE notice_read (
    user_id   INT UNSIGNED NOT NULL,
    notice_id INT UNSIGNED NOT NULL,
    read_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, notice_id),
    KEY idx_nr_notice (notice_id),
    CONSTRAINT fk_nr_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_nr_notice FOREIGN KEY (notice_id) REFERENCES notice (notice_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
