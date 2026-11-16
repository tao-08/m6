-- =====================================================================
--  109_sched_live.sql — 日程調整：どのライブ（の日程）に向けた練習かを覚える
-- =====================================================================
--  108_live_2026_winter.sql まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること（先にデプロイすると、日程調整の画面がエラーになる）
--
--  sched_poll.live_day_id … 向けのライブの日程。NULL = ライブと関係ない調整
--    作成フォームでライブを選ぶと、期間の初期値が「開催日の2週間前〜当日」になる
--    ライブの日程を消しても、調整は残す（ON DELETE SET NULL）
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE sched_poll
    ADD COLUMN live_day_id INT UNSIGNED NULL COMMENT '向けのライブの日程' AFTER late_max,
    ADD KEY idx_spoll_live_day (live_day_id),
    ADD CONSTRAINT fk_spoll_live_day FOREIGN KEY (live_day_id) REFERENCES live_day (live_day_id)
        ON UPDATE CASCADE ON DELETE SET NULL;
