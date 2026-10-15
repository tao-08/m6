-- =====================================================================
--  お知らせの予約（公開日時）
--  044_notice_category_color.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  notice.publish_at … 公開日時。これより前は管理者以外に見えず、未読数・ベル・ホームにも出ない
--    （cron は使わず、表示するときに publish_at <= NOW() で出し分ける）
--    今ある投稿は「作った時点で公開済み」なので created_at と同じにする
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE notice ADD COLUMN publish_at DATETIME NULL AFTER body;
UPDATE notice SET publish_at = created_at;
ALTER TABLE notice
    MODIFY COLUMN publish_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD KEY idx_notice_publish (publish_at);
