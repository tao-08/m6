-- =====================================================================
--  お知らせのカテゴリの色
--  043_notice_category.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  notice_category.color … 色の名前（gray / blue / green / yellow / red / purple / pink）。
--    CSS のクラス名に使うので、PHP 側（NOTICE_COLORS）で決まった名前だけを保存する
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE notice_category
    ADD COLUMN color VARCHAR(10) NOT NULL DEFAULT 'gray' AFTER name;
