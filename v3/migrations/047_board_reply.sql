-- =====================================================================
--  掲示板の返信先（board_thread.php）
--  046_board.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  board_post_reply … 「どの書き込みが、どの書き込みへの返信か」。
--                     1つの書き込みが複数の書き込みに返信できる（many-to-many の中間テーブル）。
--                     post_id = 返信した書き込み、reply_to_post_id = 返信先の書き込み
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE board_post_reply (
    post_id          INT UNSIGNED NOT NULL,
    reply_to_post_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (post_id, reply_to_post_id),
    KEY idx_bpr_to (reply_to_post_id),
    CONSTRAINT fk_bpr_post FOREIGN KEY (post_id) REFERENCES board_post (post_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_bpr_to FOREIGN KEY (reply_to_post_id) REFERENCES board_post (post_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
