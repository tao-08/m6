-- =====================================================================
--  139_equipment_cabinet.sql — 会場・スタジオの機材に「キャビネット」の欄を足す
-- =====================================================================
--  138_equipment_maker.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--  アンプ（ギターアンプ・ベースアンプ）の型番に半角「+」・全角「＋」があれば、
--  最初の「+」より後ろを cabinet へ、前を model_name に残す（例: DSL100H + 1980A → DSL100H / 1980A）
--  それ以外の行は変えない
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE venue_equipment  ADD COLUMN cabinet VARCHAR(100) NULL AFTER note;
ALTER TABLE studio_equipment ADD COLUMN cabinet VARCHAR(100) NULL AFTER note;

-- 「+」の位置 = 半角と全角のうち先にある方（_bin で比べ、半角と全角を同じ文字として扱わない）。SET は左から順に評価されるので、cabinet を先に書く
UPDATE venue_equipment
   SET cabinet    = LEFT(TRIM(SUBSTRING(model_name, LEAST(IF(LOCATE('+', model_name COLLATE utf8mb4_bin) = 0, 999, LOCATE('+', model_name COLLATE utf8mb4_bin)), IF(LOCATE('＋', model_name COLLATE utf8mb4_bin) = 0, 999, LOCATE('＋', model_name COLLATE utf8mb4_bin))) + 1)), 100),
       model_name = TRIM(LEFT(model_name, LEAST(IF(LOCATE('+', model_name COLLATE utf8mb4_bin) = 0, 999, LOCATE('+', model_name COLLATE utf8mb4_bin)), IF(LOCATE('＋', model_name COLLATE utf8mb4_bin) = 0, 999, LOCATE('＋', model_name COLLATE utf8mb4_bin))) - 1))
 WHERE category IN ('ギターアンプ', 'ベースアンプ')
   AND (LOCATE('+', model_name COLLATE utf8mb4_bin) > 0 OR LOCATE('＋', model_name COLLATE utf8mb4_bin) > 0);
UPDATE studio_equipment
   SET cabinet    = LEFT(TRIM(SUBSTRING(model_name, LEAST(IF(LOCATE('+', model_name COLLATE utf8mb4_bin) = 0, 999, LOCATE('+', model_name COLLATE utf8mb4_bin)), IF(LOCATE('＋', model_name COLLATE utf8mb4_bin) = 0, 999, LOCATE('＋', model_name COLLATE utf8mb4_bin))) + 1)), 100),
       model_name = TRIM(LEFT(model_name, LEAST(IF(LOCATE('+', model_name COLLATE utf8mb4_bin) = 0, 999, LOCATE('+', model_name COLLATE utf8mb4_bin)), IF(LOCATE('＋', model_name COLLATE utf8mb4_bin) = 0, 999, LOCATE('＋', model_name COLLATE utf8mb4_bin))) - 1))
 WHERE category IN ('ギターアンプ', 'ベースアンプ')
   AND (LOCATE('+', model_name COLLATE utf8mb4_bin) > 0 OR LOCATE('＋', model_name COLLATE utf8mb4_bin) > 0);
