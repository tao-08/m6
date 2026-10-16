-- =====================================================================
--  掲示板の自由スレに参加制限（現役のみ・同期のみ・入部年度の指定）
-- =====================================================================
--  055_notice_board_category.sql まで流した DB（本番も含む）に、追加で流す。
--  only_active … 1 = 現役（1〜4年で退会していない）だけ
--  cohort_year … 値あり = その入部年度（立てた人の同期）だけ
--  board_thread_year … 参加できる入部年度。行が無ければ年度の制限なし（学年は作成時の入部年度に変換して保存）
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE board_thread
    ADD COLUMN only_active TINYINT(1) NOT NULL DEFAULT 0 AFTER category_id,
    ADD COLUMN cohort_year SMALLINT UNSIGNED NULL AFTER only_active;

CREATE TABLE board_thread_year (
    thread_id  INT UNSIGNED      NOT NULL,
    entry_year SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (thread_id, entry_year),
    CONSTRAINT fk_bty_thread FOREIGN KEY (thread_id) REFERENCES board_thread (thread_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
