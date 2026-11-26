-- =====================================================================
--  146_song_video_start_detected.sql — 曲の検出の試験で得た、曲の始まりの秒を入れる
-- =====================================================================
--  145_song_video_start.sql まで流した DB（本番も含む）に、追加で流す。データだけ（テーブルは変えない）
--  セトリがあるバンド10組の YouTube を「YouTube から曲を検出」と同じやり方で解析し、
--  自信ありで、曲名がセトリの曲と一致した曲だけ、Gemini が答えた始まりの秒を入れる。
--  video_start がまだ NULL の曲だけ書き換える（手で入れた値は上書きしない。何度流しても同じ）
-- =====================================================================
SET NAMES utf8mb4;

-- band 219
UPDATE song SET video_start = 11 WHERE band_id = 219 AND track_no = 1 AND video_start IS NULL; -- DAY×DAY
UPDATE song SET video_start = 248 WHERE band_id = 219 AND track_no = 2 AND video_start IS NULL; -- 囮囚
UPDATE song SET video_start = 582 WHERE band_id = 219 AND track_no = 3 AND video_start IS NULL; -- ポラリス
UPDATE song SET video_start = 843 WHERE band_id = 219 AND track_no = 4 AND video_start IS NULL; -- もっと光を

-- band 187
UPDATE song SET video_start = 76 WHERE band_id = 187 AND track_no = 1 AND video_start IS NULL; -- グレーゾーンの私たち
UPDATE song SET video_start = 363 WHERE band_id = 187 AND track_no = 2 AND video_start IS NULL; -- インターセクション
UPDATE song SET video_start = 620 WHERE band_id = 187 AND track_no = 3 AND video_start IS NULL; -- 六甲シティ

-- band 840
UPDATE song SET video_start = 96 WHERE band_id = 840 AND track_no = 1 AND video_start IS NULL; -- SUN SHINE!!!
UPDATE song SET video_start = 421 WHERE band_id = 840 AND track_no = 2 AND video_start IS NULL; -- ソラシド
UPDATE song SET video_start = 588 WHERE band_id = 840 AND track_no = 3 AND video_start IS NULL; -- 花唄
UPDATE song SET video_start = 928 WHERE band_id = 840 AND track_no = 4 AND video_start IS NULL; -- キセキ

-- band 1015
UPDATE song SET video_start = 57 WHERE band_id = 1015 AND track_no = 1 AND video_start IS NULL; -- Lani
UPDATE song SET video_start = 532 WHERE band_id = 1015 AND track_no = 3 AND video_start IS NULL; -- REMEMBER
UPDATE song SET video_start = 914 WHERE band_id = 1015 AND track_no = 4 AND video_start IS NULL; -- 愛する人
UPDATE song SET video_start = 1128 WHERE band_id = 1015 AND track_no = 5 AND video_start IS NULL; -- Continue

-- band 341
UPDATE song SET video_start = 8 WHERE band_id = 341 AND track_no = 1 AND video_start IS NULL; -- Tokyo Rendez-Vous
UPDATE song SET video_start = 166 WHERE band_id = 341 AND track_no = 2 AND video_start IS NULL; -- あなたは蜃気楼
UPDATE song SET video_start = 354 WHERE band_id = 341 AND track_no = 3 AND video_start IS NULL; -- Sorrows
UPDATE song SET video_start = 919 WHERE band_id = 341 AND track_no = 5 AND video_start IS NULL; -- 飛行艇

-- band 91
UPDATE song SET video_start = 558 WHERE band_id = 91 AND track_no = 3 AND video_start IS NULL; -- 恋のメガラバ

-- band 762
UPDATE song SET video_start = 3 WHERE band_id = 762 AND track_no = 1 AND video_start IS NULL; -- スターライトパレード
UPDATE song SET video_start = 448 WHERE band_id = 762 AND track_no = 2 AND video_start IS NULL; -- 青い太陽
UPDATE song SET video_start = 792 WHERE band_id = 762 AND track_no = 3 AND video_start IS NULL; -- インスタントラジオ

-- band 652
UPDATE song SET video_start = 19 WHERE band_id = 652 AND track_no = 1 AND video_start IS NULL; -- US / アス
UPDATE song SET video_start = 366 WHERE band_id = 652 AND track_no = 2 AND video_start IS NULL; -- DANCING IN THE MOONLIGHT
UPDATE song SET video_start = 606 WHERE band_id = 652 AND track_no = 3 AND video_start IS NULL; -- HURTS

-- band 1017
UPDATE song SET video_start = 68 WHERE band_id = 1017 AND track_no = 2 AND video_start IS NULL; -- 花になって - Be a flower
UPDATE song SET video_start = 298 WHERE band_id = 1017 AND track_no = 3 AND video_start IS NULL; -- Shout Baby
UPDATE song SET video_start = 914 WHERE band_id = 1017 AND track_no = 5 AND video_start IS NULL; -- Mela!

-- band 477
UPDATE song SET video_start = 32 WHERE band_id = 477 AND track_no = 1 AND video_start IS NULL; -- 芥の部屋は錆色に沈む
UPDATE song SET video_start = 315 WHERE band_id = 477 AND track_no = 2 AND video_start IS NULL; -- 夢遊病者は此岸にて
UPDATE song SET video_start = 668 WHERE band_id = 477 AND track_no = 3 AND video_start IS NULL; -- ずうっといっしょ！
