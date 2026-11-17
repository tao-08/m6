-- =====================================================================
--  115_sched_event_format.sql — 日程調整：カレンダーに入れる練習の書式（タイトル・場所・メモ）と、スタジオ名の短縮形
-- =====================================================================
--  114 まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること（先にデプロイすると、日程調整・購読・スタジオ情報が「列が無い」エラーになる）
--
--  studio.short_name        … スタジオ名の初期の短縮形（スタジオ情報で編集。NULL = 短縮しない）
--  sched_profile.ev_*       … 人ごとの書式。{タイトル}{スタジオ}{短縮}{部屋}{住所}{メンバー}{URL} を差し込める。NULL = 初期の書式
--  sched_studio_short       … 人ごとの、スタジオ名の短縮形（初期の短縮形より優先）
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE studio
  ADD COLUMN short_name VARCHAR(30) NULL AFTER name;

ALTER TABLE sched_profile
  ADD COLUMN ev_title    VARCHAR(100) NULL AFTER feed_version,
  ADD COLUMN ev_location VARCHAR(200) NULL AFTER ev_title,
  ADD COLUMN ev_details  VARCHAR(500) NULL AFTER ev_location;

CREATE TABLE sched_studio_short (
    member_id  INT UNSIGNED NOT NULL,
    studio_id  INT UNSIGNED NOT NULL,
    short_name VARCHAR(30)  NOT NULL,
    PRIMARY KEY (member_id, studio_id),
    KEY idx_sstshort_studio (studio_id),
    CONSTRAINT fk_sstshort_member FOREIGN KEY (member_id) REFERENCES member (member_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_sstshort_studio FOREIGN KEY (studio_id) REFERENCES studio (studio_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
