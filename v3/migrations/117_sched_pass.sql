-- =====================================================================
--  117_sched_pass.sql — 日程調整：自宅最寄り駅と定期券（交通費を「定期外の分だけ」出すための準備）
-- =====================================================================
--  116 まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること（先にデプロイすると、マイスケジュールが「列が無い」エラーになる）
--
--  sched_profile.home_station … 自宅の最寄り駅（= 定期の出発駅）。NULL = 未登録
--  sched_profile.pass_to      … 定期の到着駅（キャンパスの最寄り駅）。NULL = 定期なし
--  sched_profile.pass_via     … 定期の経路（登録のときに候補から選ぶ）。JSON {"label": 路線の題, "ids": [通る駅のID…]}。NULL = 最初の経路
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE sched_profile
  ADD COLUMN home_station VARCHAR(30) NULL AFTER ev_details,
  ADD COLUMN pass_to      VARCHAR(30) NULL AFTER home_station,
  ADD COLUMN pass_via     VARCHAR(2000) NULL AFTER pass_to;
