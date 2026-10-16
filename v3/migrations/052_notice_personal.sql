-- =====================================================================
--  個人宛てのお知らせ（バンドへのコメントを、出演者に知らせる。lib/comments.php）
--  051_comment.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  notice.target_user_id … 宛先。NULL = 全員（今までのお知らせ）。入っていればその人にだけ見える
--  notice.link_url       … お知らせから飛ぶ先（サイト内の相対 URL。コメントの位置など）
--  カテゴリ「コメント」を足す（lib/comments.php は名前で探すので、無ければ自動で作る）
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE notice
    ADD COLUMN target_user_id INT UNSIGNED NULL AFTER user_id,
    ADD COLUMN link_url VARCHAR(255) NULL AFTER body,
    ADD KEY idx_notice_target (target_user_id),
    ADD CONSTRAINT fk_notice_target FOREIGN KEY (target_user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE;

INSERT IGNORE INTO notice_category (name, color) VALUES ('コメント', 'pink');
