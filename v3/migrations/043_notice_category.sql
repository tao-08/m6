-- =====================================================================
--  お知らせのカテゴリと「重要」の区分
--  042_notice.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  notice_category … カテゴリのマスタ（管理者が notices.php で追加・変更・削除）
--  notice.category_id … どのカテゴリか。使われているカテゴリは消せない（ON DELETE RESTRICT）
--  notice.is_important … 重要か（一覧で上に固定・ホームにバナー）
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE notice_category (
    category_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(20)  NOT NULL,
    PRIMARY KEY (category_id),
    UNIQUE KEY uq_notice_category_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO notice_category (name) VALUES ('お知らせ'), ('イベント'), ('システム');

-- 既にあるお知らせは最初のカテゴリ（お知らせ）にする。付けたあとで DEFAULT は外す（カテゴリは必ず選ばせる）
ALTER TABLE notice
    ADD COLUMN category_id  INT UNSIGNED NOT NULL DEFAULT 1 AFTER user_id,
    ADD COLUMN is_important TINYINT(1)   NOT NULL DEFAULT 0 AFTER category_id,
    ADD KEY idx_notice_important (is_important, notice_id),
    ADD CONSTRAINT fk_notice_category FOREIGN KEY (category_id) REFERENCES notice_category (category_id)
        ON UPDATE CASCADE ON DELETE RESTRICT;
ALTER TABLE notice ALTER COLUMN category_id DROP DEFAULT;
