-- =====================================================================
--  124_sched_fix.sql — 日程調整：1つの調整で、練習を何回でも（別々の日に）決定できるように
-- =====================================================================
--  123_sched_campus2.sql まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること（先にデプロイすると、日程調整のページが「sched_fix が無い」エラーになる）
--  ★ 流したあと、エラーが出ていないか必ず確認すること（sched_google_event の主キーの付け替えが途中で止まると、Google カレンダーの同期だけ動かなくなる）
--
--  これまでは「1つの調整 = 決定1回」だったので、決定は sched_poll の列（status / fix_* / reserved_*）と
--  sched_poll_member.fix_absent に持っていた。1つの調整で複数回決められるようにするため、決定1回 = 1行の表 sched_fix に移す。
--
--  sched_fix（決定1回）
--    status           … 1 = 仮決定（日のみ）/ 2 = 仮決定（日時）/ 3 = 決定。調整全体の状態は、ここから導く
--                       （決定が1つでもあれば「決定」、無ければ仮決定が1つでもあれば「仮決定」、どちらも無ければ「調整中」）
--    fix_date         … 練習の日
--    fix_start/fix_end … 練習の日時（status = 1 は NULL）
--    studio_id/room_id … スタジオ・部屋。スタジオや部屋を消しても決定は残す（ON DELETE SET NULL）
--    absent_member_id … 欠席する部員（1回につき最大1人）。部員を消しても決定は残す
--    reserved_at/by   … 「予約完了」を押した日時・押した人（旧 sched_poll.reserved_*）
--    UNIQUE (sched_poll_id, fix_date) … 1つの調整で1日1回まで。同じ日に決め直すと、その行を更新する
--
--  sched_google_event … 主キーを (sched_poll_id, member_id) から (sched_fix_id, member_id) に付け替える
--    （Google に入れる予定は、決定1回ごとに1つ。sched_poll_id の列は残す。調整を消したら一緒に消える FK もそのまま）
--
--  ★ 旧い列は消さない。sched_poll の status / fix_date / fix_start / fix_end / fix_studio_id / fix_room_id / reserved_at / reserved_by と、
--    sched_poll_member.fix_absent は、新しいコードからは使わない（本番は SQL を先に流してからデプロイするので、
--    その間に動く旧コードを壊さないために残す。あとで不要になったら別の SQL で消す）。
--
--  ※ CHECK 制約は MariaDB 10.2 以上（既存のマイグレーションと同じ前提）
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE sched_fix (
    sched_fix_id     INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    sched_poll_id    INT UNSIGNED     NOT NULL,
    status           TINYINT UNSIGNED NOT NULL,          -- 1 = 仮決定（日のみ）/ 2 = 仮決定（日時）/ 3 = 決定
    fix_date         DATE             NOT NULL,
    fix_start        DATETIME         NULL,              -- status = 1 は NULL
    fix_end          DATETIME         NULL,
    studio_id        INT UNSIGNED     NULL,
    room_id          INT UNSIGNED     NULL,
    absent_member_id INT UNSIGNED     NULL,              -- 欠席する部員（1回につき最大1人）
    reserved_at      DATETIME         NULL,              -- 「予約完了」を押した日時。NULL = 未予約
    reserved_by      INT UNSIGNED     NULL,              -- 押した人（user_account.user_id）
    created_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (sched_fix_id),
    UNIQUE KEY uq_sfix_poll_date (sched_poll_id, fix_date), -- 1つの調整で1日1回
    KEY idx_sfix_status_start (status, fix_start),          -- 「決定済みの練習」を期間で探す
    KEY idx_sfix_studio (studio_id),
    KEY idx_sfix_room (room_id),
    KEY idx_sfix_absent (absent_member_id),
    KEY idx_sfix_reserved_by (reserved_by),
    CONSTRAINT fk_sfix_poll FOREIGN KEY (sched_poll_id) REFERENCES sched_poll (sched_poll_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_sfix_studio FOREIGN KEY (studio_id) REFERENCES studio (studio_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_sfix_room FOREIGN KEY (room_id) REFERENCES studio_room (studio_room_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_sfix_absent FOREIGN KEY (absent_member_id) REFERENCES member (member_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_sfix_reserved_by FOREIGN KEY (reserved_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT ck_sfix_status CHECK (status BETWEEN 1 AND 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---- 移行1: いま決定・仮決定されている調整（status >= 1）を、1行ずつ sched_fix にコピーする ----
--   欠席の人は sched_poll_member で fix_absent = 1 の人（1人まで。念のため MIN で1人に絞る）
INSERT INTO sched_fix (sched_poll_id, status, fix_date, fix_start, fix_end, studio_id, room_id, absent_member_id, reserved_at, reserved_by)
SELECT p.sched_poll_id, p.status, p.fix_date, p.fix_start, p.fix_end, p.fix_studio_id, p.fix_room_id,
       (SELECT MIN(x.member_id) FROM sched_poll_member x WHERE x.sched_poll_id = p.sched_poll_id AND x.fix_absent = 1),
       p.reserved_at, p.reserved_by
FROM sched_poll p
WHERE p.status >= 1 AND p.fix_date IS NOT NULL;

-- ---- 移行2: sched_google_event を「決定1回ごと」に付け替える ----
--   主キーの (sched_poll_id, member_id) は、外部キー fk_sgevent_poll の索引を兼ねているので、先に別の索引を作ってから外す
ALTER TABLE sched_google_event
  ADD COLUMN sched_fix_id INT UNSIGNED NULL AFTER sched_poll_id,
  ADD KEY idx_sgevent_poll (sched_poll_id);

-- 移行1で、調整1件につき決定は高々1行なので、sched_poll_id が同じ行に結びつければよい
UPDATE sched_google_event e
  JOIN sched_fix f ON f.sched_poll_id = e.sched_poll_id
  SET e.sched_fix_id = f.sched_fix_id;

-- 決定に結びつかない行（調整中に戻したあとに残った等）は、どの練習の予定でもないので消す
DELETE FROM sched_google_event WHERE sched_fix_id IS NULL;

ALTER TABLE sched_google_event
  MODIFY sched_fix_id INT UNSIGNED NOT NULL,
  DROP PRIMARY KEY,
  ADD PRIMARY KEY (sched_fix_id, member_id),
  ADD CONSTRAINT fk_sgevent_fix FOREIGN KEY (sched_fix_id) REFERENCES sched_fix (sched_fix_id)
    ON UPDATE CASCADE ON DELETE CASCADE;
