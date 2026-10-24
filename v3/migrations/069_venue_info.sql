-- =====================================================================
--  会場リスト（venue_list.php / venue.php）の最寄り駅と機材
-- =====================================================================
--  068_song_tab.sql まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  含まれるテーブル: venue_station（最寄り駅）・venue_equipment（機材）
--
--  どちらも「会場1 : 行N」なので、カンマ区切りで1列に詰めず別テーブルにする。
--  会場（venue）を消すと一緒に消える（ON DELETE CASCADE）。
--  住所・サイトの URL は venue に前からある address / website_url を使う。
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE venue_station (
    venue_station_id INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    venue_id         INT UNSIGNED      NOT NULL,
    station_name     VARCHAR(50)       NOT NULL,           -- 「駅」は付けずに保存（画面で「◯◯駅」と出す）
    walk_minutes     SMALLINT UNSIGNED NOT NULL,           -- 徒歩の分数
    sort_order       INT UNSIGNED      NOT NULL DEFAULT 0,
    PRIMARY KEY (venue_station_id),
    KEY idx_vs_venue (venue_id),
    CONSTRAINT fk_vs_venue FOREIGN KEY (venue_id) REFERENCES venue (venue_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE venue_equipment (
    venue_equipment_id INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    venue_id           INT UNSIGNED      NOT NULL,
    category           VARCHAR(20)       NOT NULL,         -- 区分（ギターアンプ / ベースアンプ / ドラム / キーボード / PA / その他。一覧は venue.php の VENUE_EQUIP_CATEGORIES）
    model_name         VARCHAR(100)      NOT NULL,
    quantity           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    sort_order         INT UNSIGNED      NOT NULL DEFAULT 0,
    PRIMARY KEY (venue_equipment_id),
    KEY idx_ve_venue (venue_id),
    CONSTRAINT fk_ve_venue FOREIGN KEY (venue_id) REFERENCES venue (venue_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
