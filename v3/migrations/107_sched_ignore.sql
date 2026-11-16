-- =====================================================================
--  107_sched_ignore.sql — 日程調整：授業・カレンダー・毎週の予定を、その1回分だけ無視する
-- =====================================================================
--  106_sched_weekly.sql まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること（先にデプロイすると、無視の保存だけ動かない）
--
--  sched_ignore … 部員が「この予定を無視」にした、1回分の予定（授業・カレンダー・毎週）
--    start_at / end_at … 無視する予定の時間帯。授業・毎週は日付ごとに計算で出すので、「その日のその時間帯」で覚える
--    → 無視した予定は「予定あり」に数えない（調整の計算からも外す）。消せば元に戻る
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE sched_ignore (
    member_id INT UNSIGNED NOT NULL,
    start_at  DATETIME     NOT NULL,
    end_at    DATETIME     NOT NULL,
    PRIMARY KEY (member_id, start_at, end_at),
    CONSTRAINT fk_signore_member FOREIGN KEY (member_id) REFERENCES member (member_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT ck_signore_range CHECK (end_at > start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
