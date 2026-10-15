-- =====================================================================
--  掲示板の通知設定（board.php の「通知設定」）
--  048_board_like.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  board_notify          … ユーザーごとの通知設定。行が無い人は初期設定（すべて通知）。
--                          cohort_active = 現役・同期スレの追加書き込みを通知するか
--  board_notify_category … 新規スレッドを通知するカテゴリ（ユーザー × カテゴリの中間テーブル）。
--                          board_notify の行がある人は、ここにあるカテゴリだけ通知。無ければ通知しない
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE board_notify (
    user_id       INT UNSIGNED NOT NULL,
    cohort_active TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_bn_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE board_notify_category (
    user_id     INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, category_id),
    KEY idx_bnc_category (category_id),
    CONSTRAINT fk_bnc_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_bnc_category FOREIGN KEY (category_id) REFERENCES board_category (category_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
