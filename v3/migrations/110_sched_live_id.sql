-- =====================================================================
--  110_sched_live_id.sql — 日程調整：向けのライブを「日程（1日目・2日目）」ではなく「ライブ」で覚える
-- =====================================================================
--  109_sched_live.sql まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること（先にデプロイすると、日程調整の画面がエラーになる）
--
--  バンドがライブの何日目に出るかは、練習を決める時点ではわからないことが多い。
--  → ライブで選び、候補の期間は「ライブ初日の N週間前〜ライブ最終日」にする
--
--  sched_poll.live_id … 向けのライブ。NULL = ライブと関係ない調整（ライブを消しても調整は残す）
--  sched_poll.live_day_id … 廃止（入っていた分は、その日程のライブに移してから消す）
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE sched_poll
    ADD COLUMN live_id INT UNSIGNED NULL COMMENT '向けのライブ' AFTER late_max,
    ADD KEY idx_spoll_live (live_id),
    ADD CONSTRAINT fk_spoll_live FOREIGN KEY (live_id) REFERENCES live (live_id)
        ON UPDATE CASCADE ON DELETE SET NULL;

-- 109 で保存した日程を、そのライブに移す
UPDATE sched_poll p JOIN live_day d ON d.live_day_id = p.live_day_id SET p.live_id = d.live_id;

ALTER TABLE sched_poll
    DROP FOREIGN KEY fk_spoll_live_day,
    DROP KEY idx_spoll_live_day,
    DROP COLUMN live_day_id;
