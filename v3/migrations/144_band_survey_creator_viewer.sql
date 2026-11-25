-- =====================================================================
--  144_band_survey_creator_viewer.sql — バンド希望調査の作成者を、個別の閲覧可能者に入れる
-- =====================================================================
--  143_band_survey_viewer.sql まで流した DB（本番も含む）に、追加で流す。
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--  作った人は「いつでも見られる」から「個別の閲覧可能者（外せる）」に変わる。
--  今までの調査の作った人が見られなくならないように、作った人を個別の閲覧可能者に入れる（何回流しても同じ）
-- =====================================================================
SET NAMES utf8mb4;

INSERT IGNORE INTO band_survey_viewer (survey_id, member_id)
SELECT s.survey_id, u.member_id FROM band_survey s JOIN user_account u ON u.user_id = s.created_by WHERE u.member_id IS NOT NULL;
