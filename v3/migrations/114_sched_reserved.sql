-- =====================================================================
--  114_sched_reserved.sql — 日程調整：決定した練習の「スタジオを予約した」記録
-- =====================================================================
--  113_sched_google.sql まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること（先にデプロイすると、日程調整の1件のページが「列が無い」エラーになる）
--
--  sched_poll に、予約したかどうかを覚える2列を足す。
--    reserved_at … 「予約完了」を押した日時。NULL = まだ予約していない
--    reserved_by … 押した人（user_account.user_id）。アカウントを消しても記録は残す（ON DELETE SET NULL）
--  決定し直す・調整中に戻す（sched_poll.php の fix / reopen）と、この2列は NULL に戻る。
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE sched_poll
  ADD COLUMN reserved_at DATETIME NULL AFTER fix_room_id,      -- 予約完了を押した日時。NULL = 未予約
  ADD COLUMN reserved_by INT UNSIGNED NULL AFTER reserved_at,  -- 押した人（user_account.user_id）
  ADD CONSTRAINT fk_spoll_reserved_by FOREIGN KEY (reserved_by) REFERENCES user_account (user_id) ON UPDATE CASCADE ON DELETE SET NULL;
