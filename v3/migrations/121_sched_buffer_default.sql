-- =====================================================================
--  121_sched_buffer_default.sql — 日程調整：連携した予定の前後の枠の初期値を 60 分にする
-- =====================================================================
--  120 まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--  ★ 流したあと、エラーが出ていないか必ず確認すること
--
--  sched_profile.buf_min を NULL 可にする。NULL = 初期の分数（lib/sched.php の SCHED_DEFAULT_BUF_MIN = 60）、0 = 付けない
--  今の 0 は「何も選んでいない人」と区別できないので、NULL（= 初期の60分）にする。
--  すでに自分で「なし」を選んだ人は、もう一度「なし」を選び直す必要がある
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE sched_profile
  MODIFY COLUMN buf_min SMALLINT UNSIGNED NULL DEFAULT NULL;

UPDATE sched_profile SET buf_min = NULL WHERE buf_min = 0;
