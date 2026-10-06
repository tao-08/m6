-- =====================================================================
--  「自分が使っている音楽アプリ」と、アプリごとのアルバムURLのキャッシュ
--  004_album_source.sql を流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  member.music_app — そのメンバーが使っている音楽アプリ（プロフィール編集で選ぶ）
--    マイアルバムのリンクを押したとき、押した人のこのアプリで開く。
--    NULL = 選んでいない（今までどおり、登録元の Spotify / Apple Music のページを開く）
-- ---------------------------------------------------------------------
ALTER TABLE member
    ADD COLUMN music_app VARCHAR(20) CHARACTER SET ascii NULL AFTER entry_year,
    ADD CONSTRAINT ck_member_music_app
        CHECK (music_app IS NULL OR music_app IN ('spotify', 'apple_music', 'youtube_music', 'line_music'));

-- ---------------------------------------------------------------------
--  album_link_cache — 「このアルバムは、このアプリだとこの URL」の覚え書き（キャッシュ）
--
--    例: Spotify から登録したアルバムを Apple Music の人が押したとき、
--        iTunes で検索して見つけた Apple Music の URL をここに保存しておく。
--        → 2回目からは検索しないで済む（速い & 外部 API を叩きすぎない）
--
--    主キー (source, album_id, app): 「どのサービスの何番のアルバムを、どのアプリで開くか」で1行
--    url が NULL = 探したけど見つからなかった（そのときは検索ページを開く。毎回探し直さないために記録する）
--
--    ※ 外部キーは張っていない。source + album_id は Spotify / iTunes 側の ID で、
--      このDBに「アルバム」の親テーブルは無いため（member_favorite_album は「誰が登録したか」の表で、
--      同じアルバムが何人分も入るので参照先にできない）。消えても困らない「覚え書き」なのでこれで良い。
--    ※ source / album_id は 004 と同じく ascii_bin（Spotify の ID は大文字小文字を区別するため）
-- ---------------------------------------------------------------------
CREATE TABLE album_link_cache (
    source     VARCHAR(10)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- 'itunes' / 'spotify'
    album_id   VARCHAR(40)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- そのサービスでのアルバムID
    app        VARCHAR(20)  CHARACTER SET ascii NOT NULL,                   -- 開くアプリ（member.music_app と同じ値）
    url        VARCHAR(500) NULL,                                           -- 見つかった URL。NULL = 見つからなかった
    checked_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,             -- 調べた日時
    PRIMARY KEY (source, album_id, app),
    CONSTRAINT ck_alc_source CHECK (source IN ('itunes', 'spotify')),
    CONSTRAINT ck_alc_app CHECK (app IN ('spotify', 'apple_music', 'youtube_music', 'line_music'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
