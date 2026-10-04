-- =====================================================================
--  local_abbeydb のテーブル構造（2026-10-04 時点の phpMyAdmin エクスポートから）
--
--  v2 はこの構造に「そのまま」乗るように作ってある。
--  すでに local_abbeydb がある人はこのファイルを流す必要はない。
--  まっさらな環境を作るときだけ使う（中身のデータは入っていない。instrument だけ初期値あり）。
--
--  ※ 改善したほうがいい点は migrations/001_recommended_keys.sql にまとめた。
-- =====================================================================

SET NAMES utf8mb4;

-- バンド（ライブの1日 = live_detail に出演した1組）
CREATE TABLE `band` (
  `band_id`        int(5)      NOT NULL AUTO_INCREMENT,
  `name`           varchar(50) NOT NULL,          -- タイムテーブル上のバンド名
  `live_detail_id` int(5)      NOT NULL,          -- どの日に出たか
  `play_order`     int(11)     NOT NULL,          -- 出演順（最大のものがトリ）
  `song_count`     int(11)     NOT NULL,          -- 曲数
  `note`           text        DEFAULT NULL,      -- v2 では鍵盤の私物/貸出メモを入れている
  PRIMARY KEY (`band_id`),
  KEY `id_live_detail` (`live_detail_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- バンドに誰がいたか（中間テーブル）
CREATE TABLE `band_member` (
  `member_id`  int(11) NOT NULL,
  `band_id`    int(11) NOT NULL,
  `song_count` int(11) DEFAULT NULL,             -- その人が参加した曲数（全曲なら NULL）
  KEY `band_fk` (`band_id`),
  KEY `neme_fk` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- そのバンドで誰が何の楽器だったか（中間テーブル）
CREATE TABLE `band_member_instrument` (
  `band_id`       int(11) NOT NULL,
  `member_id`     int(11) NOT NULL,
  `instrument_id` int(11) NOT NULL,
  `played_count`  int(11) DEFAULT NULL,          -- その楽器で演奏した曲数（全曲なら NULL）
  KEY `band_id` (`band_id`),
  KEY `member_id` (`member_id`),
  KEY `instrument_id` (`instrument_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `instrument` (
  `instrument_id`    int(11)     NOT NULL AUTO_INCREMENT,
  `instrument_name`  varchar(50) NOT NULL,
  `instrument_en`    varchar(50) NOT NULL,
  `instrument_short` varchar(10) NOT NULL,
  PRIMARY KEY (`instrument_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `instrument` (`instrument_id`, `instrument_name`, `instrument_en`, `instrument_short`) VALUES
(1, 'ボーカル', 'Vocals', 'Vo.'),
(2, 'ギター', 'Guitar', 'Gt.'),
(3, 'ベース', 'Bass', 'Ba.'),
(4, 'ドラム', 'Drums', 'Dr.'),
(5, 'キーボード', 'Keyboards', 'Key.'),
(6, 'ヴァイオリン', 'Violin', 'Vn.'),
(7, 'サックス', 'Saxophone', 'Sax.'),
(8, 'ギターボーカル', 'Vocals / Guitar', 'Vo./Gt.'),
(9, 'ベースボーカル', 'Vocals / Bass', 'Vo./Ba.');

-- ライブの各日程（1日目 / 2日目 / 教室ライブ ...）
CREATE TABLE `live_detail` (
  `live_detail_id` int(11)     NOT NULL AUTO_INCREMENT,
  `live_id`        int(11)     NOT NULL,
  `date`           date        NOT NULL,         -- NOT NULL なので取り込み時に日付は必須
  `label`          varchar(50) DEFAULT NULL,     -- 「1日目」「教室ライブ」など
  `venue_id`       int(11)     NOT NULL,         -- NOT NULL なので会場も必須
  `note`           text        DEFAULT NULL,     -- v2 では「集合 11:00」を入れている
  PRIMARY KEY (`live_detail_id`),
  KEY `id_venue` (`venue_id`),
  KEY `id_live_master` (`live_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ライブ（年度 + 名前。例: 2025年度 文化祭ライブ）
CREATE TABLE `live_master` (
  `live_id` int(11)     NOT NULL AUTO_INCREMENT,
  `year`    year(4)     NOT NULL,                -- 年度（1〜3月のライブは前の年）
  `name`    varchar(50) NOT NULL,
  PRIMARY KEY (`live_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- サークル員
CREATE TABLE `member` (
  `member_id`  int(11)     NOT NULL AUTO_INCREMENT,
  `name`       varchar(50) NOT NULL,
  `name_kana`  varchar(50) NOT NULL,             -- NOT NULL なので不明なら '' を入れる
  `entry_year` smallint(6) DEFAULT NULL,         -- 入部年度
  PRIMARY KEY (`member_id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ログインユーザー
CREATE TABLE `user_index` (
  `user_id`       int(5)       NOT NULL AUTO_INCREMENT,
  `login_id`      varchar(25)  NOT NULL,
  `name`          varchar(50)  NOT NULL,
  `name_kana`     varchar(50)  NOT NULL,
  `password_hash` varchar(255) NOT NULL,         -- password_hash() の結果だけを入れる
  `member_id`     int(11)      DEFAULT NULL,     -- member と紐付けると「マイページ」が出る
  `is_admin`      tinyint(1)   DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `id` (`login_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `venue` (
  `venue_id`    int(11)      NOT NULL AUTO_INCREMENT,
  `name`        varchar(50)  NOT NULL,
  `address`     varchar(255) DEFAULT NULL,
  `website_url` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`venue_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `band`
  ADD CONSTRAINT `band_ibfk_1` FOREIGN KEY (`live_detail_id`) REFERENCES `live_detail` (`live_detail_id`);
ALTER TABLE `band_member`
  ADD CONSTRAINT `band_fk` FOREIGN KEY (`band_id`) REFERENCES `band` (`band_id`),
  ADD CONSTRAINT `neme_fk` FOREIGN KEY (`member_id`) REFERENCES `member` (`member_id`);
ALTER TABLE `band_member_instrument`
  ADD CONSTRAINT `band_id` FOREIGN KEY (`band_id`) REFERENCES `band` (`band_id`),
  ADD CONSTRAINT `instrument_id` FOREIGN KEY (`instrument_id`) REFERENCES `instrument` (`instrument_id`),
  ADD CONSTRAINT `member_id` FOREIGN KEY (`member_id`) REFERENCES `member` (`member_id`);
ALTER TABLE `live_detail`
  ADD CONSTRAINT `live_detail_ibfk_1` FOREIGN KEY (`venue_id`) REFERENCES `venue` (`venue_id`),
  ADD CONSTRAINT `live_detail_ibfk_2` FOREIGN KEY (`live_id`) REFERENCES `live_master` (`live_id`);
