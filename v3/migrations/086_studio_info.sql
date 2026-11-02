-- =====================================================================
--  スタジオ情報（studio_list.php / studio_info.php）— 練習スタジオの情報・料金・機材
-- =====================================================================
--  085_instrument_accordion.sql まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  ※ スタジオ割（studio.php / studio_plan / studio_need）とは別物。
--     あちらは「ライブ前の練習の部屋割り」、こちらは「スタジオそのものの情報」。
--
--  テーブルの関係（1対多 が4本。どれも studio を消すと一緒に消える = ON DELETE CASCADE）
--
--     studio ─┬─< studio_room        部屋（Aスタ / Bスタ …）
--             ├─< studio_rate        料金表（部屋 × 曜日区分 × 時間帯 → 1時間の単価）
--             ├─< studio_rental      レンタル（マイク・シールド …）
--             └─< studio_equipment   機材（アンプ・ドラム …）
--
--  なぜ料金を「部屋ごとの1列」ではなく、別テーブル（studio_rate）にするのか
--    → 料金は「平日昼は 2,200 円、平日夜は 2,750 円、土日祝は終日 3,000 円」のように
--      条件の組み合わせで決まる。列で持つと条件が増えるたびに ALTER TABLE が要るが、
--      行で持てば「条件1つ = 1行」で、何パターンでも増やせる（これが正規化の考え方）
--
--  studio_rate の読み方
--    studio_room_id … NULL = 全部屋共通の料金。部屋の行があれば、そちらが優先（シミュレーションで）
--    day_type       … 0 = 全日 / 1 = 平日 / 2 = 土日祝
--    start_min / end_min … 0時からの分。深夜は 24時を超えて書ける（22:00〜29:00 = 1320〜1740）
--                         日付をまたぐ料金を1行で書けるようにするため、TIME 型ではなく分（整数）で持つ
--    price          … 1時間・1部屋の料金（円）
--    student_price  … 学割の1時間の料金（円）。NULL = その時間帯は学割なし
--
--  lat / lng … 地図のピンの位置（緯度・経度）。DECIMAL(9,6) = 小数6桁 ≒ 10cm 単位まで持てる
--    FLOAT にしないのは、FLOAT は 2進数の近似で「35.681236」が「35.68123626…」にずれるため
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE studio (
    studio_id    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    name         VARCHAR(100)  NOT NULL,
    website_url  VARCHAR(255)  NULL,
    reserve_url  VARCHAR(255)  NULL,             -- 予約ページ
    address      VARCHAR(255)  NULL,
    access       VARCHAR(100)  NULL,             -- 最寄り駅（例: 立川駅 北口 徒歩5分）
    phone        VARCHAR(30)   NULL,
    lat          DECIMAL(9,6)  NULL,
    lng          DECIMAL(9,6)  NULL,
    student_note VARCHAR(500)  NULL,             -- 学割の条件（例: 学生証の提示が必要・平日のみ）
    note         VARCHAR(1000) NULL,
    sort_order   INT UNSIGNED  NOT NULL DEFAULT 0,
    updated_by   INT UNSIGNED  NULL,
    updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (studio_id),
    CONSTRAINT fk_studio_info_updated FOREIGN KEY (updated_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE studio_room (
    studio_room_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    studio_id      INT UNSIGNED NOT NULL,
    name           VARCHAR(50)  NOT NULL,          -- 部屋名（例: Aスタ）
    size_note      VARCHAR(50)  NULL,              -- 広さ・定員（例: 20畳 / 8人）
    sort_order     INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (studio_room_id),
    KEY idx_sroom_studio (studio_id),
    CONSTRAINT fk_sroom_studio FOREIGN KEY (studio_id) REFERENCES studio (studio_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE studio_rate (
    studio_rate_id INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    studio_id      INT UNSIGNED      NOT NULL,
    studio_room_id INT UNSIGNED      NULL,          -- NULL = 全部屋共通
    day_type       TINYINT UNSIGNED  NOT NULL DEFAULT 0,
    start_min      SMALLINT UNSIGNED NOT NULL,
    end_min        SMALLINT UNSIGNED NOT NULL,
    price          INT UNSIGNED      NOT NULL,
    student_price  INT UNSIGNED      NULL,
    sort_order     INT UNSIGNED      NOT NULL DEFAULT 0,
    PRIMARY KEY (studio_rate_id),
    KEY idx_srate_studio (studio_id),
    CONSTRAINT fk_srate_studio FOREIGN KEY (studio_id) REFERENCES studio (studio_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    -- 部屋を消したら、その部屋だけの料金も消す
    CONSTRAINT fk_srate_room FOREIGN KEY (studio_room_id) REFERENCES studio_room (studio_room_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE studio_rental (
    studio_rental_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    studio_id        INT UNSIGNED NOT NULL,
    name             VARCHAR(100) NOT NULL,         -- 例: SM58
    price            INT UNSIGNED NOT NULL,
    per_hour         TINYINT(1)   NOT NULL DEFAULT 0, -- 1 = 1時間あたり / 0 = 1回あたり
    sort_order       INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (studio_rental_id),
    KEY idx_srental_studio (studio_id),
    CONSTRAINT fk_srental_studio FOREIGN KEY (studio_id) REFERENCES studio (studio_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE studio_equipment (
    studio_equipment_id INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    studio_id           INT UNSIGNED      NOT NULL,
    studio_room_id      INT UNSIGNED      NULL,     -- NULL = 全部屋共通
    category            VARCHAR(20)       NOT NULL, -- 区分（studio_info.php の STUDIO_EQUIP_CATEGORIES）
    model_name          VARCHAR(100)      NOT NULL,
    quantity            SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    sort_order          INT UNSIGNED      NOT NULL DEFAULT 0,
    PRIMARY KEY (studio_equipment_id),
    KEY idx_sequip_studio (studio_id),
    CONSTRAINT fk_sequip_studio FOREIGN KEY (studio_id) REFERENCES studio (studio_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_sequip_room FOREIGN KEY (studio_room_id) REFERENCES studio_room (studio_room_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
