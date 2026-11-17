-- =====================================================================
--  123_sched_campus2.sql — 日程調整：キャンパスを4つに／サブのキャンパスと、サブの授業
-- =====================================================================
--  122 まで流した DB に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること（先にデプロイするとマイスケジュールが開けない）
--  ★ 流したあと、エラーが出ていないか必ず確認すること
--
--  sched_profile.campus  … メインのキャンパス。1 = 和泉 / 2 = 駿河台 / 3 = 中野 / 4 = 生田 / NULL = 自動
--                          （自動 = 学部（総合数理・国際日本 → 中野、理工・農 → 生田）→ 学年（1・2年 → 和泉、3年以上 → 駿河台））
--                          → CHECK の範囲が 1〜2 から 1〜4 に広がるので、ck_sprof_campus を作り直す
--  sched_profile.campus2 … サブのキャンパス（同じ 1〜4）。NULL = なし。メインと同じ値は入れない（アプリ側で NULL にする）
--  sched_class.sub       … 1 = サブのキャンパスの授業 / 0 = メインのキャンパスの授業。
--                          サブが NULL（なし）の人は、sub = 1 のコマもメイン扱い
--
--  ※ CHECK 制約の削除は DROP CONSTRAINT IF EXISTS（MariaDB 10.2.1 以上。MySQL では使えない）
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE sched_profile
  DROP CONSTRAINT IF EXISTS ck_sprof_campus; -- 古い DB では CHECK が無いことがあるので IF EXISTS

ALTER TABLE sched_profile
  ADD CONSTRAINT ck_sprof_campus CHECK (campus IS NULL OR campus IN (1, 2, 3, 4)),
  ADD COLUMN campus2 TINYINT UNSIGNED NULL AFTER campus,
  ADD CONSTRAINT ck_sprof_campus2 CHECK (campus2 IS NULL OR campus2 IN (1, 2, 3, 4));

ALTER TABLE sched_class
  ADD COLUMN sub TINYINT(1) NOT NULL DEFAULT 0 AFTER period;
