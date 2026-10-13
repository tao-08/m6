-- =====================================================================
--  問い合わせ・要望（contact.php）
--  024_tutorial_seen.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  user_account.email … 問い合わせの通知を受け取るメールアドレス。管理者だけがアカウント設定で登録できる
--  inquiry            … 送られた問い合わせ。メールが届かなかったときも消えないように DB にも残す
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE user_account
    ADD COLUMN email VARCHAR(254) NULL AFTER is_admin;

CREATE TABLE inquiry (
    inquiry_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NULL,          -- NULL = 匿名（誰が送ったかを残さない）
    category   VARCHAR(20)  NOT NULL,
    body       TEXT         NOT NULL,
    mailed     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (inquiry_id),
    KEY idx_inquiry_user (user_id, created_at),
    CONSTRAINT fk_inquiry_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
