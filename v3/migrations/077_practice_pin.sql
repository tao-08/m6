-- =====================================================================
--  練習プレイヤーのピン留め（practice.php）
-- =====================================================================
--  076_song_part.sql まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  人ごとに曲（song_key）をピン留めする。user_account と song_key の many-to-many を中間テーブルで解決。
--  title / artist_name は表示用の控え（曲が DB に無くても、ピン留め欄に出せるように）
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE practice_pin (
    user_id     INT UNSIGNED NOT NULL,
    song_key    VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    title       VARCHAR(100) NOT NULL,
    artist_name VARCHAR(255) NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, song_key),
    CONSTRAINT fk_pracpin_user FOREIGN KEY (user_id) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
