-- =====================================================================
--  159_chapter_to_video.sql — YouTube の区分の音源のチャプターを、動画のチャプター（'y:動画ID'）に寄せる
-- =====================================================================
--  158_yt_drive_folder.sql まで流した DB（本番も含む）に、追加で流す。
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--  ファイルのカードと動画で同じチャプターを使う（編集はファイルのカード。band.php）
--  動画の側にもうチャプターがあれば動画の側を残し、ファイルの 'a:' / 'd:' の分は消す。無ければ 'y:動画ID' に付け替える
--  これからの区分の変更は lib/audio_player.php の audio_chapters_to_video が同じことをする
-- =====================================================================
SET NAMES utf8mb4;

-- 寄せ先: ファイルの音源キー => 動画のキー
CREATE TEMPORARY TABLE chapter_move (
    band_id  INT UNSIGNED NOT NULL,
    from_key VARCHAR(110) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    to_key   VARCHAR(110) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (band_id, from_key)
);
INSERT IGNORE INTO chapter_move
    SELECT band_id, CONCAT('a:', image_id), CONCAT('y:', video_id) FROM band_audio WHERE video_id IS NOT NULL
    UNION ALL SELECT band_id, CONCAT('d:', file_id), CONCAT('y:', video_id) FROM band_drive_file WHERE video_id IS NOT NULL;

-- 動画の側にもうあるものは、ファイルの分を消す
CREATE TEMPORARY TABLE chapter_has_video AS
    SELECT DISTINCT band_id, audio_key FROM band_audio_chapter WHERE audio_key LIKE 'y:%';
DELETE c FROM band_audio_chapter c
JOIN chapter_move m ON m.band_id = c.band_id AND m.from_key = c.audio_key
JOIN chapter_has_video v ON v.band_id = m.band_id AND v.audio_key = m.to_key;

-- 残りは付け替える（同じ動画の音源が2つあれば両方移るので、先に消した上で1つ目だけ）
CREATE TEMPORARY TABLE chapter_first AS
    SELECT c.band_id, MIN(c.audio_key) AS from_key, m.to_key
    FROM band_audio_chapter c JOIN chapter_move m ON m.band_id = c.band_id AND m.from_key = c.audio_key
    GROUP BY c.band_id, m.to_key;
DELETE c FROM band_audio_chapter c
JOIN chapter_move m ON m.band_id = c.band_id AND m.from_key = c.audio_key
LEFT JOIN chapter_first f ON f.band_id = c.band_id AND f.from_key = c.audio_key
WHERE f.from_key IS NULL;
UPDATE band_audio_chapter c
JOIN chapter_first f ON f.band_id = c.band_id AND f.from_key = c.audio_key
SET c.audio_key = f.to_key;

DROP TEMPORARY TABLE chapter_move, chapter_has_video, chapter_first;
