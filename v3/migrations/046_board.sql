-- =====================================================================
--  掲示板（board.php / board_thread.php）
--  045_notice_publish_at.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  board_category … 自由スレのカテゴリ（管理者が board.php で追加・変更・削除）
--  board_thread   … スレッド。kind で3種類
--                     free   = 誰でも立てられる。全員が読み書きできる
--                     active = 現役用（1〜4年で退会していない人だけ）。1本だけ
--                     cohort = 同期用（入部年度が同じ人だけ）。年度ごとに1本。学年が上がっても同じスレを使い続ける
--                   active / cohort は board.php を開いたときに自動で作る（fixed_key で重複を防ぐ）
--  board_post     … 書き込み。削除は印だけ（deleted_at）。管理者は削除された内容も見られる
--  board_read     … どのスレをどこまで読んだか（ユーザー × スレの中間テーブル）。未読バッジに使う
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE board_category (
    category_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(20)  NOT NULL,
    color       VARCHAR(10)  NOT NULL DEFAULT 'gray',  -- 表示色（notices.php の NOTICE_COLORS と同じキー）
    PRIMARY KEY (category_id),
    UNIQUE KEY uq_board_category_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO board_category (name, color) VALUES ('雑談', 'gray'), ('募集', 'blue'), ('機材', 'green'), ('質問', 'yellow');

CREATE TABLE board_thread (
    thread_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind           VARCHAR(10)  NOT NULL DEFAULT 'free',
    entry_year     SMALLINT UNSIGNED NULL,               -- cohort のときだけ。どの入部年度のスレか
    fixed_key      VARCHAR(20)  NULL,                    -- 'active' / 'cohort:2025'。free は NULL（NULL は UNIQUE でも重複できる）
    category_id    INT UNSIGNED NULL,                    -- free のときだけ
    user_id        INT UNSIGNED NULL,                    -- 立てた人。active / cohort は NULL
    title          VARCHAR(100) NOT NULL,
    last_post_id   INT UNSIGNED NOT NULL DEFAULT 0,      -- 最後の書き込み（未読の判定用。書き込むたびに更新）
    last_posted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at     DATETIME     NULL,
    deleted_by     INT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (thread_id),
    UNIQUE KEY uq_board_fixed (fixed_key),
    KEY idx_board_last (last_posted_at),
    CONSTRAINT ck_board_kind CHECK (kind IN ('free', 'active', 'cohort')),
    CONSTRAINT fk_bt_category FOREIGN KEY (category_id) REFERENCES board_category (category_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_bt_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_bt_deleted_by FOREIGN KEY (deleted_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE board_post (
    post_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    thread_id  INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NULL,
    body       TEXT         NOT NULL,
    deleted_at DATETIME     NULL,
    deleted_by INT UNSIGNED NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (post_id),
    KEY idx_bp_thread (thread_id, post_id),
    CONSTRAINT fk_bp_thread FOREIGN KEY (thread_id) REFERENCES board_thread (thread_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_bp_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_bp_deleted_by FOREIGN KEY (deleted_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE board_read (
    user_id      INT UNSIGNED NOT NULL,
    thread_id    INT UNSIGNED NOT NULL,
    last_post_id INT UNSIGNED NOT NULL DEFAULT 0,        -- ここまで読んだ
    PRIMARY KEY (user_id, thread_id),
    KEY idx_br_thread (thread_id),
    CONSTRAINT fk_br_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_br_thread FOREIGN KEY (thread_id) REFERENCES board_thread (thread_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
