-- =====================================================================
--  band.song_count を NULL 可にする（NULL = 曲数不明・未入力）
--  013_live_day_total_bands.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  今までは空欄でも 0 で保存していたので「0曲」と「不明」の区別がつかなかった。
--  既にある 0 は、どちらか分からないので「不明」とみなして NULL にする。
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE band
    MODIFY COLUMN song_count TINYINT UNSIGNED NULL;

UPDATE band SET song_count = NULL WHERE song_count = 0;
