-- =====================================================================
--  会場・係に「並び順」を持たせる（会場・日程名・楽器・係の管理 masters.php の ↑↓ で並び替える）
--  017_omnibus_no_artist.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  今まではどこでも名前順（ORDER BY name）だったので、最初の並び順は名前順の 1, 2, 3 … にする
--  （ライブ編集の会場のプルダウン・プロフィールの係のチェックボックスの並びが変わらないように）
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE venue ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER website_url;
ALTER TABLE role  ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER name;

UPDATE venue v JOIN (SELECT venue_id, ROW_NUMBER() OVER (ORDER BY name) AS rn FROM venue) x ON x.venue_id = v.venue_id
SET v.sort_order = x.rn;
UPDATE role r JOIN (SELECT role_id, ROW_NUMBER() OVER (ORDER BY name) AS rn FROM role) x ON x.role_id = r.role_id
SET r.sort_order = x.rn;
