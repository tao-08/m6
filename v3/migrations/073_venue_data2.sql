-- =====================================================================
--  会場の住所・最寄り駅・機材の追加分（072 のあとに1回だけ流す。駅・機材は流すたびに増える）
-- =====================================================================
--  出典は予約サイト・イベント掲載が中心で、古いものもある。現地で分かったら venue.php で直すこと。
--    西永福JAM … イベント掲載（2025年）/ 調布Cross … 記事（2019年）・卓は導入業者の資料 / 下北沢近道 … 予約サイト（2026年更新）
--    シェラリゾート湯沢 … 旅行サイト（公式でない）。駅からは送迎車で約15分なので駅は入れない
-- =====================================================================
SET NAMES utf8mb4;

UPDATE venue SET address = '東京都杉並区永福3-34-14 B1F' WHERE name = '西永福JAM' AND address IS NULL;
UPDATE venue SET address = '東京都調布市小島町1-35-3' WHERE name = '調布Cross' AND address IS NULL;
UPDATE venue SET address = '東京都世田谷区北沢3-31-15' WHERE name = '下北沢近道' AND address IS NULL;
UPDATE venue SET address = '新潟県南魚沼郡湯沢町大字土樽731-1' WHERE name = 'シェラリゾート湯沢' AND address IS NULL;

INSERT INTO venue_station (venue_id, station_name, walk_minutes, sort_order)
SELECT venue_id, s.station, s.minutes, 1 FROM venue
JOIN (
    SELECT '西永福JAM' AS vname, '西永福' AS station, 1 AS minutes
    UNION ALL SELECT '下北沢近道', '下北沢', 6
) s ON s.vname = venue.name;

INSERT INTO venue_equipment (venue_id, category, model_name, quantity, sort_order)
SELECT venue_id, 'PA', 'MIDAS M32-Live（卓）', 1, 1 FROM venue WHERE name = '調布Cross';
