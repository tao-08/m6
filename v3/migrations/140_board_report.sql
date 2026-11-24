-- =====================================================================
--  140_board_report.sql — 掲示板の通報
-- =====================================================================
--  139_equipment_cabinet.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--  board_report … 書き込み × 通報した人の中間テーブル（1人1書き込み1回）。reason は lib/board.php の BOARD_REPORT_REASONS
--  board_post.disclosure … 匿名・名義の本名の開示。0 = なし / 1 = 請求中（通報2件）/ 2 = 許可（全員に本名を出す）/ 3 = 却下
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE board_report (
    post_id    INT UNSIGNED     NOT NULL,
    user_id    INT UNSIGNED     NOT NULL,
    reason     TINYINT UNSIGNED NOT NULL,
    created_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (post_id, user_id),
    KEY idx_brp_user (user_id),
    CONSTRAINT fk_brp_post FOREIGN KEY (post_id) REFERENCES board_post (post_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_brp_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE board_post ADD COLUMN disclosure TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER display_name;
