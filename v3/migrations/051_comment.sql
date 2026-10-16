-- =====================================================================
--  ライブ・バンドのコメント（lib/comments.php / api_comment_like.php）
--  050_board_mute.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  comment         … コメント。live_id か band_id のどちらか1つだけ入る（lib/comments.php が必ずそうする）。
--                    ライブにもバンドにも外部キーを張れるよう、種類ごとの列に分けている。
--                    削除は印だけ（deleted_at）。掲示板と同じ
--  comment_reply   … 返信先（many-to-many の中間テーブル）
--  comment_like    … ❤（コメント × ユーザーの中間テーブル。画面には数だけ = 匿名）
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE comment (
    comment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    live_id    INT UNSIGNED NULL,
    band_id    INT UNSIGNED NULL,
    user_id    INT UNSIGNED NULL,
    body       TEXT         NOT NULL,
    deleted_at DATETIME     NULL,
    deleted_by INT UNSIGNED NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (comment_id),
    KEY idx_comment_live (live_id, comment_id),
    KEY idx_comment_band (band_id, comment_id),
    CONSTRAINT fk_comment_live FOREIGN KEY (live_id) REFERENCES live (live_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_comment_band FOREIGN KEY (band_id) REFERENCES band (band_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_comment_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_comment_deleted_by FOREIGN KEY (deleted_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE comment_reply (
    comment_id       INT UNSIGNED NOT NULL,
    reply_to_comment_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (comment_id, reply_to_comment_id),
    KEY idx_cr_to (reply_to_comment_id),
    CONSTRAINT fk_cr_comment FOREIGN KEY (comment_id) REFERENCES comment (comment_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_cr_to FOREIGN KEY (reply_to_comment_id) REFERENCES comment (comment_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE comment_like (
    comment_id INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    PRIMARY KEY (comment_id, user_id),
    KEY idx_cl_user (user_id),
    CONSTRAINT fk_cl_comment FOREIGN KEY (comment_id) REFERENCES comment (comment_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_cl_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
