-- =====================================================================
--  掲示板の書き込みのお気に入り（❤）（api_board_like.php）
--  047_board_reply.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  board_post_like … 書き込み × ユーザーの中間テーブル（band_like と同じ作り）。画面に出すのは数だけ（匿名）
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE board_post_like (
    post_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (post_id, user_id),
    KEY idx_bpl_user (user_id),
    CONSTRAINT fk_bpl_post FOREIGN KEY (post_id) REFERENCES board_post (post_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_bpl_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
