-- =====================================================================
--  118_sched_nav_cache.sql — 日程調整：交通費の計算結果のキャッシュ（NAVITIME の経路・Google の駅の座標）
-- =====================================================================
--  117 まで流した DB に、追加で流す。（これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--
--  NAVITIME の Basic プランは月500回までなので、一度調べた経路・座標は180日使い回す
--    cache_key … 'g|明大前駅'（駅の座標）/ 'r|緯度,経度|緯度,経度'（経路）
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS sched_nav_cache (
    cache_key  VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    payload    MEDIUMTEXT   NOT NULL,
    fetched_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (cache_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
