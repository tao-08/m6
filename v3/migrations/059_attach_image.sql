-- =====================================================================
--  掲示板（1投稿1枚）・お知らせ（最大5枚）・バンド（最大5枚）に画像を添付できるようにする
-- =====================================================================
--  058_live_image.sql まで流した DB（本番も含む）に、追加で流す。
--  上限はアプリ側（lib/attach_image.php の ATTACH_KINDS）で止める。ファイル本体は storage/live_images/
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE board_post_image (
    image_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    post_id    INT UNSIGNED NOT NULL,
    file_name  VARCHAR(40)  NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (image_id),
    KEY idx_bpi_post (post_id, sort_order),
    CONSTRAINT fk_bpi_post FOREIGN KEY (post_id) REFERENCES board_post (post_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE notice_image (
    image_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    notice_id  INT UNSIGNED NOT NULL,
    file_name  VARCHAR(40)  NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (image_id),
    KEY idx_ni_notice (notice_id, sort_order),
    CONSTRAINT fk_ni_notice FOREIGN KEY (notice_id) REFERENCES notice (notice_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE band_image (
    image_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    band_id    INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NULL,           -- 追加した人（本人と管理者・出演メンバーが削除できる）
    file_name  VARCHAR(40)  NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (image_id),
    KEY idx_bi_band (band_id, sort_order),
    CONSTRAINT fk_bi_band FOREIGN KEY (band_id) REFERENCES band (band_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_bi_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
