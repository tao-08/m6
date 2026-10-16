-- =====================================================================
--  ライブに画像を添付できるようにする（1ライブ最大5枚。上限はアプリ側で止める）
-- =====================================================================
--  057_song_se.sql まで流した DB（本番も含む）に、追加で流す。
--  file_name … storage/live_images/ の中のファイル名（サムネイルは t_ を付けたもの）
--  taken_at  … 撮影日時（EXIF の DateTimeOriginal。無ければ NULL）
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE live_image (
    image_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    live_id    INT UNSIGNED NOT NULL,
    file_name  VARCHAR(40)  NOT NULL,
    taken_at   DATETIME     NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (image_id),
    KEY idx_live_image_live (live_id, sort_order),
    CONSTRAINT fk_live_image_live FOREIGN KEY (live_id) REFERENCES live (live_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
