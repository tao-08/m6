-- =====================================================================
--  推奨: 中間テーブルに複合主キーを付ける（v2 はこれが無くても動く）
--
--  なぜ必要？
--    今の band_member / band_member_instrument には主キーが無いので、
--    同じ (band_id, member_id) の行を2回 INSERT できてしまう。
--    → 出演回数が2倍に数えられるなどのバグの原因になる。
--    複合主キーにすると「同じ組み合わせは1行だけ」をDBが保証してくれる。
--
--  流す前に: 重複行があると ALTER が失敗する。下の SELECT で 0 件か確認すること。
--    SELECT band_id, member_id, COUNT(*) FROM band_member GROUP BY band_id, member_id HAVING COUNT(*) > 1;
--    SELECT band_id, member_id, instrument_id, COUNT(*) FROM band_member_instrument
--      GROUP BY band_id, member_id, instrument_id HAVING COUNT(*) > 1;
-- =====================================================================

ALTER TABLE `band_member`
  ADD PRIMARY KEY (`band_id`, `member_id`);

ALTER TABLE `band_member_instrument`
  ADD PRIMARY KEY (`band_id`, `member_id`, `instrument_id`);

-- 同じ年度に同じ名前のライブが2つ作られないように
--   （流す前に確認: SELECT year, name, COUNT(*) FROM live_master GROUP BY year, name HAVING COUNT(*) > 1;）
ALTER TABLE `live_master`
  ADD UNIQUE KEY `uq_live_year_name` (`year`, `name`);

-- 同じライブに「1日目」が2つ作られないように
ALTER TABLE `live_detail`
  ADD UNIQUE KEY `uq_live_label` (`live_id`, `label`);
