-- =====================================================================
--  138_equipment_maker.sql — 会場・スタジオの機材を「メーカー｜型番｜メモ」に分ける
-- =====================================================================
--  137_special_author.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--  model_name は「型番」として使い続ける（型番なしは空文字）。maker・note は無ければ NULL
--  今あるデータは、全角括弧「（…）」の中身を note へ、先頭のメーカー名を maker へ移す
--  （一覧にないメーカー・半角括弧はそのまま model_name に残す）
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE venue_equipment
    ADD COLUMN maker VARCHAR(50) NULL AFTER category,
    ADD COLUMN note  VARCHAR(100) NULL AFTER model_name;
ALTER TABLE studio_equipment
    ADD COLUMN maker VARCHAR(50) NULL AFTER category,
    ADD COLUMN note  VARCHAR(100) NULL AFTER model_name;

-- 全角括弧の中身（最初の1つ）を note へ。model_name からは括弧ごと消して詰める
UPDATE venue_equipment
   SET note = LEFT(SUBSTRING(REGEXP_SUBSTR(model_name, '（[^）]*）'), 2, CHAR_LENGTH(REGEXP_SUBSTR(model_name, '（[^）]*）')) - 2), 100),
       model_name = TRIM(REGEXP_REPLACE(REGEXP_REPLACE(model_name, '（[^）]*）', ''), '[[:space:]]+', ' '))
 WHERE model_name REGEXP '（[^）]*）';
UPDATE studio_equipment
   SET note = LEFT(SUBSTRING(REGEXP_SUBSTR(model_name, '（[^）]*）'), 2, CHAR_LENGTH(REGEXP_SUBSTR(model_name, '（[^）]*）')) - 2), 100),
       model_name = TRIM(REGEXP_REPLACE(REGEXP_REPLACE(model_name, '（[^）]*）', ''), '[[:space:]]+', ' '))
 WHERE model_name REGEXP '（[^）]*）';

-- 先頭語がメーカー名なら maker へ（pat = 先頭に来る表記の正規表現 / name = 保存する表記）
CREATE TEMPORARY TABLE tmp_equip_maker (pat VARCHAR(60) NOT NULL, name VARCHAR(50) NOT NULL) DEFAULT CHARSET=utf8mb4;
INSERT INTO tmp_equip_maker (pat, name) VALUES
    ('Marshall', 'Marshall'), ('Roland', 'Roland'), ('Fender', 'Fender'), ('Ampeg', 'Ampeg'),
    ('YAMAHA', 'YAMAHA'), ('Pearl', 'Pearl'), ('TAMA', 'TAMA'), ('Ludwig', 'Ludwig'),
    ('Hartke', 'Hartke'), ('Mark ?bass', 'Markbass'), ('KORG', 'KORG'), ('MACKIE', 'MACKIE'),
    ('QSC', 'QSC'), ('MIDAS', 'MIDAS'), ('DYNACORD', 'DYNACORD'), ('BOSS', 'BOSS'),
    ('COUNTRYMAN', 'COUNTRYMAN'), ('EV', 'EV'), ('Peavey', 'Peavey'), ('Soldano', 'Soldano'),
    ('Aguilar', 'Aguilar'), ('EBS', 'EBS'), ('Eden', 'Eden'), ('CANOPUS', 'CANOPUS'),
    ('DW', 'DW'), ('Gretsch', 'Gretsch'), ('SONOR', 'SONOR'), ('Zildjian', 'Zildjian'),
    ('Sabian', 'Sabian'), ('TASCAM', 'TASCAM'), ('BULLITT', 'BULLITT'), ('PJB', 'PJB'),
    ('Shure', 'Shure'), ('Hughes ?& ?Kettner', 'Hughes & Kettner');

-- 後ろは「空白」「数字（Marshall2000 のようにくっついたもの）」「文字の終わり（メーカー名だけの行）」のどれか
UPDATE venue_equipment e JOIN tmp_equip_maker m
    ON e.model_name REGEXP CONCAT('(?i)^(', m.pat, ')([[:space:]]|(?=[0-9])|$)')
   SET e.maker = m.name,
       e.model_name = TRIM(REGEXP_REPLACE(e.model_name, CONCAT('(?i)^(', m.pat, ')[[:space:]]*'), ''));
UPDATE studio_equipment e JOIN tmp_equip_maker m
    ON e.model_name REGEXP CONCAT('(?i)^(', m.pat, ')([[:space:]]|(?=[0-9])|$)')
   SET e.maker = m.name,
       e.model_name = TRIM(REGEXP_REPLACE(e.model_name, CONCAT('(?i)^(', m.pat, ')[[:space:]]*'), ''));

DROP TEMPORARY TABLE tmp_equip_maker;
