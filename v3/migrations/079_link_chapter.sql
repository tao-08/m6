-- =====================================================================
--  YouTube 動画のチャプターの控え（tab.php?link=）
-- =====================================================================
--  078_link_practicing.sql まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  動画の概要欄のタイムスタンプ（0:00 イントロ …）を、動画（song_link）ごとに1回だけ YouTube から取って控える。
--  ゲストにも見せるため、人ごとの song_link_practice ではなく全員共通の表に置く。チャプターが無い動画は [] を入れる
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE song_link_chapter (
    link_id    INT UNSIGNED NOT NULL,
    data       JSON         NOT NULL,
    fetched_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (link_id),
    CONSTRAINT fk_linkchap_link FOREIGN KEY (link_id) REFERENCES song_link (link_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
