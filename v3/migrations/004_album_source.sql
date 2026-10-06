-- =====================================================================
--  好きなアルバムを Spotify からも登録できるようにする
--  003_favorite_album.sql を流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  変更点:
--    itunes_collection_id（数字）をやめて、
--      source   … どのサービスから取ったか（'itunes' / 'spotify'）
--      album_id … そのサービスでのアルバムID（Spotify は英数字22文字なので文字列）
--    の2列にする。登録済みの iTunes のアルバムは source='itunes' としてそのまま残る。
--
--  album_id を ascii_bin にしている理由:
--    Spotify の ID は大文字と小文字を区別する（"abc" と "ABC" は別のアルバム）。
--    この DB の標準の照合順序 utf8mb4_general_ci は大文字小文字を「同じ」とみなすので、
--    そのままだと UNIQUE が別のアルバムを重複扱いしたり、WHERE で別のアルバムに当たったりする。
--    _bin（バイナリ）は1バイトずつ厳密に比べるので、大文字小文字を区別する。
-- =====================================================================
SET NAMES utf8mb4;

-- ① 新しい列を足す（今ある行のために、いったん仮の初期値を付けておく）
ALTER TABLE member_favorite_album
    ADD COLUMN source   VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'itunes' AFTER sort_order,
    ADD COLUMN album_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''       AFTER source;

-- ② 今ある行の iTunes の ID を新しい列へ写す
UPDATE member_favorite_album SET album_id = CAST(itunes_collection_id AS CHAR);

-- ③ 古い列と UNIQUE を消して、新しい列で UNIQUE を作り直す。仮の初期値も外す（入れ忘れを DB が止めるように）
ALTER TABLE member_favorite_album
    DROP INDEX uq_fav_album,
    DROP COLUMN itunes_collection_id,
    ALTER COLUMN source DROP DEFAULT,
    ALTER COLUMN album_id DROP DEFAULT,
    ADD UNIQUE KEY uq_fav_album (member_id, source, album_id),
    ADD CONSTRAINT ck_fav_source CHECK (source IN ('itunes', 'spotify'));
