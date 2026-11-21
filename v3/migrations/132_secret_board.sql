-- 裏掲示板。通常掲示板のテーブル・通知・添付画像とは分離する。
-- 既存環境では手動適用済みの場合、このマイグレーションを再実行しないこと。
CREATE TABLE IF NOT EXISTS secret_board_thread (
    thread_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    title VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (thread_id),
    KEY idx_secret_board_updated (updated_at),
    CONSTRAINT fk_secret_board_thread_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS secret_board_post (
    post_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    thread_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (post_id),
    KEY idx_secret_board_post_thread (thread_id, post_id),
    CONSTRAINT fk_secret_board_post_thread FOREIGN KEY (thread_id) REFERENCES secret_board_thread (thread_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_secret_board_post_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
