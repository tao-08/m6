-- AbbeyRoad.online v2 スキーマ (MariaDB / MySQL 8)
-- 既存DBとは別のデータベース (例: m6_v2) に流し込むこと。
--   mysql -u root -p m6_v2 < schema.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS band_member, band_master, live_detail, live_master, venue, user_index, member;
SET FOREIGN_KEY_CHECKS = 1;

-- サークル員
CREATE TABLE member (
    member_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_name VARCHAR(64)  NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (member_id),
    UNIQUE KEY uq_member_name (member_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- サイトのログインユーザー (member と 1:1 で紐付け可能)
CREATE TABLE user_index (
    user_auto_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       VARCHAR(32)  NOT NULL,
    user_name     VARCHAR(64)  NOT NULL,
    user_ruby     VARCHAR(64)  NOT NULL DEFAULT '',
    user_password VARCHAR(255) NOT NULL, -- password_hash() の結果のみ保存
    user_admin    TINYINT(1)   NOT NULL DEFAULT 0,
    member_id     INT UNSIGNED NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_auto_id),
    UNIQUE KEY uq_user_id (user_id),
    UNIQUE KEY uq_user_member (member_id),
    CONSTRAINT fk_user_member FOREIGN KEY (member_id) REFERENCES member (member_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 会場
CREATE TABLE venue (
    venue_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    venue_name VARCHAR(64)  NOT NULL,
    PRIMARY KEY (venue_id),
    UNIQUE KEY uq_venue_name (venue_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ライブ (例: 2025年度 ライブハウス)
CREATE TABLE live_master (
    live_id   INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    year      SMALLINT UNSIGNED NOT NULL, -- 年度
    live_name VARCHAR(64)       NOT NULL,
    PRIMARY KEY (live_id),
    UNIQUE KEY uq_live (year, live_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ライブの各日程 (1日目, 2日目 ...)
CREATE TABLE live_detail (
    live_detail_id INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    live_id        INT UNSIGNED     NOT NULL,
    day_no         TINYINT UNSIGNED NOT NULL DEFAULT 1,
    live_date      DATE             NULL,
    venue_id       INT UNSIGNED     NULL,
    meeting_time   TIME             NULL, -- 集合時間
    PRIMARY KEY (live_detail_id),
    UNIQUE KEY uq_live_day (live_id, day_no),
    CONSTRAINT fk_detail_live  FOREIGN KEY (live_id)  REFERENCES live_master (live_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_detail_venue FOREIGN KEY (venue_id) REFERENCES venue (venue_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- その日に出演したコピーバンド
CREATE TABLE band_master (
    band_id        INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    live_detail_id INT UNSIGNED      NOT NULL,
    band_name      VARCHAR(128)      NOT NULL,
    play_order     SMALLINT UNSIGNED NOT NULL,
    start_time     TIME              NULL,
    end_time       TIME              NULL,
    song_count     TINYINT UNSIGNED  NULL,
    member_count   TINYINT UNSIGNED  NULL,
    key_note       VARCHAR(128)      NOT NULL DEFAULT '', -- 鍵盤の私物/貸出メモ
    PRIMARY KEY (band_id),
    UNIQUE KEY uq_band_order (live_detail_id, play_order),
    CONSTRAINT fk_band_detail FOREIGN KEY (live_detail_id) REFERENCES live_detail (live_detail_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- バンドとメンバーの中間テーブル (many-to-many)
-- 同じ人が同じバンドで Vo と Ba を兼ねることがあるので part まで含めて複合主キー
CREATE TABLE band_member (
    band_id   INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    part      VARCHAR(8)   NOT NULL, -- Vo / Gt / Ba / Dr / Key / Other
    PRIMARY KEY (band_id, member_id, part),
    KEY idx_band_member_member (member_id),
    CONSTRAINT fk_bm_band   FOREIGN KEY (band_id)   REFERENCES band_master (band_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_bm_member FOREIGN KEY (member_id) REFERENCES member (member_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
