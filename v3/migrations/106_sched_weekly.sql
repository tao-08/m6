-- =====================================================================
--  106_sched_weekly.sql — 日程調整：毎週の NG（バイト等）と、決定した練習の欠席
-- =====================================================================
--  105_sched.sql まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること（先にデプロイすると日程調整のページが開けない）
--
--  sched_weekly … 部員の「毎週くり返す NG」（1つの時間帯 = 1行）。例: 毎週火曜 18:00〜22:00 のバイト
--    weekday   … 1 = 月 〜 7 = 日（PHP の date('N') と同じ番号。sched_class.weekday と同じ数え方で、日曜の 7 が増えただけ）
--    start_min / end_min … 0時からの分（例: 1080 = 18:00）。終わりは 1440（= 24:00）まで
--    → 期限は持たない（消すまで毎週効く）。祝日・休業期間でも効く（授業と違って、バイトは休みにならないため）
--    → 日付ごとの行は作らない。調整のたびに「その曜日の日」に当てはめて埋まりにする（計算で出せるものは保存しない）
--
--  sched_poll_member.fix_absent … 1 = 決定（仮決定）した練習を欠席する人
--    「1人欠席の候補」で決めたとき、その1人に 1 を入れる。調整中に戻したら 0 に戻す
--    欠席の人には、その練習を「埋まり」として数えない（ほかの調整でその時間が空きになる）
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE sched_weekly (
    sched_weekly_id INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    member_id       INT UNSIGNED      NOT NULL,
    weekday         TINYINT UNSIGNED  NOT NULL,          -- 1 = 月 〜 7 = 日
    start_min       SMALLINT UNSIGNED NOT NULL,          -- 0時からの分
    end_min         SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (sched_weekly_id),
    KEY idx_sweekly_member (member_id, weekday),         -- 「この部員の、この曜日の NG」を探す
    CONSTRAINT fk_sweekly_member FOREIGN KEY (member_id) REFERENCES member (member_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT ck_sweekly_range CHECK (weekday BETWEEN 1 AND 7 AND end_min > start_min AND end_min <= 1440)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE sched_poll_member
    ADD COLUMN fix_absent TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '1=決定した練習を欠席' AFTER answered_at;
