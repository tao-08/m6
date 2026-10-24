-- =====================================================================
--  会場の住所・サイト・最寄り駅・機材の初期データ（venue.php。Web 検索で調べた分だけ）
-- =====================================================================
--  069_venue_info.sql / 071_venue_image.sql のあとに1回だけ流す（駅・機材は流すたびに増えるので2回流さない）。
--  会場は名前で探す（本番と ID がずれていても動くように）。住所・サイトは空のものだけ埋める。
--  機材は掲載サイトの情報が古いものがある。現地で確認できたら venue.php で直すこと。
--  調べても出なかった会場は入れていない（空のまま）。
-- =====================================================================
SET NAMES utf8mb4;

-- ---- 住所・サイト ----
UPDATE venue SET address = '東京都世田谷区北沢2-14-13 B1F', website_url = 'https://waverwaver.net/' WHERE name = '下北沢WAVER' AND address IS NULL;
UPDATE venue SET address = '東京都新宿区百人町1-10-7 一番街ビルB1F' WHERE name = '新大久保CLUB Voice' AND address IS NULL;
UPDATE venue SET address = '東京都豊島区南大塚3-50-1' WHERE name = '大塚MEETS' AND address IS NULL;
UPDATE venue SET address = '東京都練馬区小竹町1-55-7 日神パレスB1F', website_url = 'http://clubdorothy.com/' WHERE name = '江古田クラブドロシー' AND address IS NULL;
UPDATE venue SET address = '東京都新宿区百人町1-17-7 大久保ビルA館B1F' WHERE name = '大久保HOT SHOT' AND address IS NULL;
UPDATE venue SET address = '東京都新宿区歌舞伎町2-45-2 ジャストビル5F' WHERE name = '新宿Motion' AND address IS NULL;
UPDATE venue SET address = '東京都新宿区四谷2-10 第2太郎ビルB1', website_url = 'http://www.hor-outbreak.com/' WHERE name = '四谷アウトブレイク' AND address IS NULL;
UPDATE venue SET address = '東京都武蔵野市吉祥寺本町1-30-10 B1F', website_url = 'http://warp.rinky.info/' WHERE name = '吉祥寺WARP' AND address IS NULL;
UPDATE venue SET address = '東京都杉並区永福1-9-1' WHERE name = '和泉キャンパス' AND address IS NULL;

-- ---- 最寄り駅（駅名に「駅」は付けない） ----
INSERT INTO venue_station (venue_id, station_name, walk_minutes, sort_order)
SELECT venue_id, s.station, s.minutes, s.ord FROM venue
JOIN (
    SELECT '下北沢WAVER' AS vname, '下北沢' AS station, 3 AS minutes, 1 AS ord
    UNION ALL SELECT '新大久保CLUB Voice', '新大久保', 1, 1
    UNION ALL SELECT '大塚MEETS', '大塚', 3, 1
    UNION ALL SELECT '江古田クラブドロシー', '江古田', 1, 1
    UNION ALL SELECT '大久保HOT SHOT', '大久保', 1, 1
    UNION ALL SELECT '大久保HOT SHOT', '新大久保', 3, 2
    UNION ALL SELECT '新宿Motion', '西武新宿', 4, 1
    UNION ALL SELECT '新宿Motion', '新宿', 9, 2
    UNION ALL SELECT '和泉キャンパス', '明大前', 5, 1
) s ON s.vname = venue.name;

-- ---- 機材 ----
INSERT INTO venue_equipment (venue_id, category, model_name, quantity, sort_order)
SELECT venue_id, e.category, e.model, 1, e.ord FROM venue
JOIN (
    SELECT '江古田クラブドロシー' AS vname, 'ギターアンプ' AS category, 'Marshall JCM2000' AS model, 1 AS ord
    UNION ALL SELECT '江古田クラブドロシー', 'ギターアンプ', 'Marshall JCM900', 2
    UNION ALL SELECT '江古田クラブドロシー', 'ギターアンプ', 'Roland JC-120', 3
    UNION ALL SELECT '江古田クラブドロシー', 'ギターアンプ', 'Marshall 1960A', 4
    UNION ALL SELECT '四谷アウトブレイク', 'ギターアンプ', 'Marshall', 1
    UNION ALL SELECT '四谷アウトブレイク', 'ギターアンプ', 'Roland', 2
    UNION ALL SELECT '四谷アウトブレイク', 'ベースアンプ', 'Ampeg', 3
    UNION ALL SELECT '四谷アウトブレイク', 'ベースアンプ', 'Hartke', 4
    UNION ALL SELECT '四谷アウトブレイク', 'ドラム', 'Pearl', 5
) e ON e.vname = venue.name;
