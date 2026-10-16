-- =====================================================================
--  プッシュ通知（Web Push。lib/push.php）
--  052_notice_personal.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  push_subscription … 通知を受け取る端末（ユーザー × 端末 = 1対多。1人が複数の端末で受け取れる）
--  notice.pushed_at  … プッシュ通知を処理した日時。NULL = まだ。予約投稿も公開日時を過ぎたら送る
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE push_subscription (
    subscription_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    endpoint        VARCHAR(500) NOT NULL,          -- 通知の送り先（Google / Apple / Mozilla のサーバーの URL）
    endpoint_hash   CHAR(64)     NOT NULL,          -- endpoint の SHA-256。長い文字列に UNIQUE を付けないため
    p256dh          VARCHAR(255) NOT NULL,          -- 暗号化に使う端末の公開鍵
    auth            VARCHAR(64)  NOT NULL,          -- 暗号化に使う端末の合言葉
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (subscription_id),
    UNIQUE KEY uq_ps_endpoint (endpoint_hash),
    KEY idx_ps_user (user_id),
    CONSTRAINT fk_ps_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 今ある公開済みのお知らせは「処理済み」にする（流した瞬間に過去のお知らせが一斉に飛ばないように）
ALTER TABLE notice
    ADD COLUMN pushed_at DATETIME NULL AFTER publish_at,
    ADD KEY idx_notice_pushed (pushed_at, publish_at);
UPDATE notice SET pushed_at = NOW() WHERE publish_at <= NOW();
