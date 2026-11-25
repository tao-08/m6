-- =====================================================================
--  142_band_survey.sql — バンド希望調査（卒業ライブのバンドの熱量を集計する）
-- =====================================================================
--  141_band_acr_file.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--  ★ このSQLを本番DBで流してから、コードをデプロイすること
--  band_survey … アップロードした調査の CSV。回答行は JSON にして sched_ical_encrypt() で暗号化して入れる（lib/band_survey.php）
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE band_survey (
    survey_id  INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '調査の ID',
    title      VARCHAR(100) NOT NULL COMMENT '調査名',
    data_enc   MEDIUMTEXT   NOT NULL COMMENT '回答行 [[バンド, メンバー, 順位]] の JSON を暗号化したもの',
    created_by INT UNSIGNED NULL COMMENT 'アップロードしたアカウント。消えたら NULL',
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'アップロード日時',
    PRIMARY KEY (survey_id),
    CONSTRAINT fk_band_survey_user FOREIGN KEY (created_by) REFERENCES user_account (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
