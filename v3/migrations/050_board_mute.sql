-- =====================================================================
--  掲示板のスレ単位のミュート（board_thread.php のスレ名の右のボタン）
--  049_board_notify.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  board_thread_mute … ユーザー × スレの中間テーブル。行があるスレは、ヘッダーのバッジに数えない
--  board_notify.participating … 48時間以内に自分が書き込んだスレの新着を通知するか（通知設定で変えられる）
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE board_notify ADD COLUMN participating TINYINT(1) NOT NULL DEFAULT 1 AFTER cohort_active;

CREATE TABLE board_thread_mute (
    user_id   INT UNSIGNED NOT NULL,
    thread_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, thread_id),
    KEY idx_btm_thread (thread_id),
    CONSTRAINT fk_btm_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_btm_thread FOREIGN KEY (thread_id) REFERENCES board_thread (thread_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
