-- =====================================================================
--  112_sched_feed.sql — 日程調整：決定した練習を、部員のカレンダーに自動で入れる「購読 URL」の版
-- =====================================================================
--  111_sched_busy_title.sql まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること（先にデプロイすると、マイスケジュールが「列が無い」エラーになる）
--
--  部員ごとに秘密の URL（sched_feed?m=部員&t=鍵）を作り、Google カレンダー・iPhone に登録してもらう。
--  → 決定した練習が、変更・取り消しも含めて自動で反映される。
--  URL の「鍵」は DB に保存しない（lib/sched_ical.php の sched_feed_token が、部員 id と版から毎回計算する。秘密は config.php の sched.ical_key）。
--  → DB に入れるのは「版」だけ。URL が漏れたときは版を +1 する（再発行）と、古い URL は計算結果が合わなくなって使えなくなる。
--
--  sched_profile.feed_version … 購読 URL の版。0 = 初期。再発行のたびに +1
--    sched_profile の行が無い部員は、版 0 とみなす（再発行のときに行を作る）
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE sched_profile ADD COLUMN feed_version INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '購読 URL の版。再発行で +1（古い URL は無効）' AFTER ical_error;
