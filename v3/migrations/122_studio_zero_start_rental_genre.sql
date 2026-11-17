-- =====================================================================
--  122_studio_zero_start_rental_genre.sql — スタジオ：部屋の「0分開始」と、レンタルのジャンルを足す
-- =====================================================================
--  121 まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--  ★ 流したあと、エラーが出ていないか必ず確認すること
--
--  zero_start = 1 … 毎時0分から開始できる / half_start = 1 … 毎時30分から開始できる
--  両方 1 = どちらでも開始できる（料金の時間帯はずらさない）
--  今「30分開始」の部屋は、0分からは開始できない扱いだったので zero_start = 0 にする
--
--  studio_rental.genre … レンタルのジャンル（lib/studio_info.php の STUDIO_RENTAL_GENRES）。NULL = 名前から自動で判定
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE studio_room
  ADD COLUMN zero_start TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 AFTER size_note;

UPDATE studio_room SET zero_start = 0 WHERE half_start = 1;

ALTER TABLE studio_rental
  ADD COLUMN genre TINYINT UNSIGNED NULL AFTER per_hour;
