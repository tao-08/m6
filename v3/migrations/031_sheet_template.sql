-- =====================================================================
--  セッティングシートの型紙を DB に置く（lib/setting_sheet.php の all_sheet_templates）
--  030_hide_retired_suggest.sql まで流した DB（本番も含む）に、追加で流す。
--  （これから新しく作る人は schema.sql に含まれているので不要）
--
--  前からある型紙は lib/setting_sheet.php の SHEET_TEMPLATES（コード）のまま。
--  ここには「画面から追加した型紙」だけが入る。画面上のキーは 'db_' . template_id
--  config … 座標など（fields / members / stage / setlist / choices / texts …）の JSON。
--           中の値で検索することは無いので、表に分けずに JSON 1列にしている
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE sheet_template (
    template_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    label       VARCHAR(100) NOT NULL,             -- プルダウンに出す名前
    venue_words VARCHAR(255) NOT NULL DEFAULT '',  -- 会場名の自動選択に使う言葉。改行区切り
    pdf_path    VARCHAR(255) NOT NULL,             -- assets/sheets/uploads/xxx.pdf
    config      JSON         NOT NULL,
    created_by  INT UNSIGNED NULL,                 -- アカウントを消しても型紙は残す（NULL になる）
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (template_id),
    CONSTRAINT fk_sheet_template_user FOREIGN KEY (created_by) REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
