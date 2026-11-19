-- =====================================================================
--  127_sched_google_calendar.sql — Google 連携：取り込むカレンダーを本人が選ぶ
-- =====================================================================
--  126_block_month_best.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--
--  sched_google_calendar … 部員が選んだ、予定を読み込む Google カレンダー（部員 × カレンダー）
--    行が1つも無い人（選択画面の前に連携した人）は、これまでどおり表示中のカレンダーをすべて読む
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE sched_google_calendar (
    member_id   INT UNSIGNED NOT NULL,
    calendar_id VARCHAR(255) NOT NULL,              -- Google のカレンダー id（メインはメールアドレス）
    PRIMARY KEY (member_id, calendar_id),
    CONSTRAINT fk_sgcal_member FOREIGN KEY (member_id) REFERENCES member (member_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
