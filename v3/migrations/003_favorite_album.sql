-- =====================================================================
--  v3 に「メンバーの好きなアルバム」を追加する
--  すでに schema.sql を流して abbey_v3 を使っている人（本番も含む）は、これを追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  member_favorite_album — メンバーが登録した「好きなアルバム」
--
--    ・1人で最大30枚（上限はアプリ側 member_album_save.php で数えて止める）
--    ・アルバムの情報は iTunes Search API から取ってきて「登録した瞬間の内容」を保存する
--      （スナップショット保存。ページを開くたびに iTunes に聞きに行かない
--        → 表示が速い & iTunes が止まってもページは壊れない）
--
--    主キー (member_id, sort_order)
--      → 「同じ人の3枚目」が2つできない。並び順そのものが行の住所になる。
--      ※ 列名を rank にしなかったのは、RANK が MySQL 8 で予約語（関数名）だから。
--        予約語を列名にすると毎回 `rank` とバッククォートで囲む必要があり、事故の元。
--        instrument テーブルの sort_order と名前を揃えた。
--
--    UNIQUE (member_id, itunes_collection_id)
--      → 同じ人が同じアルバムを2回登録できない、を DB が保証する。
--
--    外部キー member_id → member
--      ON DELETE CASCADE: メンバーが消えたら（統合など）アルバムも一緒に消える（孤児データを残さない）
--      ON UPDATE CASCADE: member_id が変わったらこっちも追従する
-- ---------------------------------------------------------------------
CREATE TABLE member_favorite_album (
    member_id            INT UNSIGNED      NOT NULL,
    sort_order           SMALLINT UNSIGNED NOT NULL,          -- 表示順 1, 2, 3...（小さいほど前）
    itunes_collection_id BIGINT UNSIGNED   NOT NULL,          -- iTunes 側のアルバムID（collectionId）
    title                VARCHAR(255)      NOT NULL,          -- アルバム名
    artist_name          VARCHAR(255)      NOT NULL,          -- アーティスト名
    artwork_url          VARCHAR(500)      NOT NULL,          -- ジャケット画像のURL（600x600）
    release_year         SMALLINT UNSIGNED NULL,              -- 発売年。分からなければ NULL
    created_at           DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (member_id, sort_order),
    UNIQUE KEY uq_fav_album (member_id, itunes_collection_id),
    CONSTRAINT fk_fav_member FOREIGN KEY (member_id) REFERENCES member (member_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT ck_fav_order CHECK (sort_order >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
