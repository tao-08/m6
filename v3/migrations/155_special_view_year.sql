-- =====================================================================
--  155_special_view_year.sql — スペシャルコンテンツを見られる入部年度
-- =====================================================================
--  154_audio_chapter_se.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--  1件も無いものは全員が見られる。ある場合はその入部年度の人と、追加した人・管理者だけ（lib/special.php の special_visible_sql）
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE special_view_year (
    special_id INT UNSIGNED      NOT NULL COMMENT 'スペシャルコンテンツ',
    entry_year SMALLINT UNSIGNED NOT NULL COMMENT 'この入部年度の人が見られる',
    PRIMARY KEY (special_id, entry_year),
    CONSTRAINT fk_special_view_year FOREIGN KEY (special_id) REFERENCES special_content (special_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
