-- =====================================================================
--  136_board_poll.sql — 掲示板の書き込みに付ける投票（board_thread.php / board.php）
-- =====================================================================
--  135_special_content.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--
--  board_poll_option … 書き込み1件に 2〜4 個の選択肢。選択肢があればその書き込みは投票付き
--  board_poll_vote   … 書き込み × ユーザーの中間テーブル。1人1票（PRIMARY KEY）・変更不可。誰が投票したかは出さない
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE board_poll_option (
    option_id INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    post_id   INT UNSIGNED     NOT NULL,
    sort      TINYINT UNSIGNED NOT NULL,
    label     VARCHAR(25)      NOT NULL,
    PRIMARY KEY (option_id),
    KEY idx_bpo_post (post_id, sort),
    CONSTRAINT fk_bpo_post FOREIGN KEY (post_id) REFERENCES board_post (post_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE board_poll_vote (
    post_id   INT UNSIGNED NOT NULL,
    user_id   INT UNSIGNED NOT NULL,
    option_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (post_id, user_id),
    KEY idx_bpv_option (option_id),
    KEY idx_bpv_user (user_id),
    CONSTRAINT fk_bpv_post FOREIGN KEY (post_id) REFERENCES board_post (post_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_bpv_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_bpv_option FOREIGN KEY (option_id) REFERENCES board_poll_option (option_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
