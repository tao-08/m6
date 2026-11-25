-- =====================================================================
--  143_band_survey_viewer.sql — バンド希望調査の閲覧可能者
-- =====================================================================
--  142_band_survey.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--  見られる人（lib/band_survey.php の band_survey_list）:
--    作った人 / band_survey_viewer の人 / 入部年度が band_survey_view_year にある人（view_role = 'exec' なら幹部だけ）/
--    年度がなく view_role = 'exec' なら、すべての年度の幹部
--  すでにある調査は「2023年度・幹部だけ」にする（今まで見られた人と同じ）
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE band_survey
    ADD COLUMN view_role ENUM('all', 'exec') NOT NULL DEFAULT 'all' COMMENT '年度で見られる人: all = 一般と幹部 / exec = 幹部だけ' AFTER data_enc;

CREATE TABLE band_survey_view_year (
    survey_id  INT UNSIGNED      NOT NULL COMMENT '調査',
    entry_year SMALLINT UNSIGNED NOT NULL COMMENT 'この入部年度の人が見られる',
    PRIMARY KEY (survey_id, entry_year),
    CONSTRAINT fk_band_survey_view_year FOREIGN KEY (survey_id) REFERENCES band_survey (survey_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE band_survey_viewer (
    survey_id INT UNSIGNED NOT NULL COMMENT '調査',
    member_id INT UNSIGNED NOT NULL COMMENT '個別に見られるようにしたメンバー',
    PRIMARY KEY (survey_id, member_id),
    KEY idx_band_survey_viewer_member (member_id),
    CONSTRAINT fk_band_survey_viewer_survey FOREIGN KEY (survey_id) REFERENCES band_survey (survey_id) ON DELETE CASCADE,
    CONSTRAINT fk_band_survey_viewer_member FOREIGN KEY (member_id) REFERENCES member (member_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

UPDATE band_survey SET view_role = 'exec';
INSERT INTO band_survey_view_year (survey_id, entry_year) SELECT survey_id, 2023 FROM band_survey;
