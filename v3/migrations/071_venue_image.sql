-- =====================================================================
--  会場の添付画像（venue.php。1会場に7枚まで）
-- =====================================================================
--  069_venue_info.sql まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ファイルの保存・縮小は他の添付画像と同じ（lib/attach_image.php の kind = venue）。
--  会場（venue）を消すと行も消える（ON DELETE CASCADE）。ファイルは masters.php が消す。
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE venue_image (
    image_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    venue_id   INT UNSIGNED NOT NULL,
    file_name  VARCHAR(40)  NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (image_id),
    KEY idx_vi_venue (venue_id, sort_order),
    CONSTRAINT fk_vi_venue FOREIGN KEY (venue_id) REFERENCES venue (venue_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
