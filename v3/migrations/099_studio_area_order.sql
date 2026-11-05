-- =====================================================================
--  099_studio_area_order.sql — エリアの並び順（studio.area は文字列のまま。順番だけ別表に持つ）
-- =====================================================================
--  表に無いエリアは、表にあるエリアの後ろに名前順で並ぶ
--  ★ このSQLを本番DBで流してから、コードをデプロイすること（流す前でもエラーにはならず、名前順のまま）
-- =====================================================================
CREATE TABLE studio_area_order (
    area       VARCHAR(30) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (area)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
