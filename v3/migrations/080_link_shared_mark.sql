-- =====================================================================
--  YouTube 動画の共通マーカー（イントロ・サビなどの曲構成。tab.php?link=）
-- =====================================================================
--  079_link_chapter.sql まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  曲構成のラベル付きマーカーは人ごとではなく動画ごとに全員共通で持つ（未ログインの人も編集できる）。
--  行が無い動画は、従来どおり人ごとの song_link_practice のマーカーを使う
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE song_link_shared_mark (
    link_id    INT UNSIGNED NOT NULL,
    data       JSON         NOT NULL,                  -- [{"t": ミリ秒, "l": ラベル}]
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (link_id),
    CONSTRAINT fk_linkshared_link FOREIGN KEY (link_id) REFERENCES song_link (link_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
