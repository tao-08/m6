-- =====================================================================
--  157_chapter_song_no.sql — 曲のチャプターごとに「セトリの何曲目か」を持つ
-- =====================================================================
--  156_audio_first_song.sql まで流した DB（本番も含む）に、追加で流す。
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--  song_no … 曲のチャプターが当たる曲（セトリの SE 以外の何曲目か）。NULL = 前の曲のチャプター + 1（最初は 1）
--  156 の「開始の曲」は、音源の1つ目の曲のチャプターの song_no に移して、表は消す
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE band_audio_chapter
    ADD COLUMN song_no SMALLINT UNSIGNED NULL AFTER kind;

CREATE TEMPORARY TABLE tmp_first AS
SELECT c.chapter_id, f.first_song
FROM band_audio_first f
JOIN band_audio_chapter c ON c.band_id = f.band_id AND c.audio_key = f.audio_key AND c.kind = 'song'
WHERE NOT EXISTS (
    SELECT 1 FROM band_audio_chapter p
    WHERE p.band_id = c.band_id AND p.audio_key = c.audio_key AND p.kind = 'song'
      AND (p.at_ms < c.at_ms OR (p.at_ms = c.at_ms AND p.chapter_id < c.chapter_id))
);

UPDATE band_audio_chapter c JOIN tmp_first t ON t.chapter_id = c.chapter_id SET c.song_no = t.first_song;

DROP TEMPORARY TABLE tmp_first;
DROP TABLE band_audio_first;
